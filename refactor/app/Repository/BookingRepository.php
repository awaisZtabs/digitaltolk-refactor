<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];
        if(!$cuser ) return [];


        if ($cuser->is('customer')) {
            $usertype = 'customer';
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
        } elseif ($cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }
        if (!empty($jobs)) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }
            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1); // Default to page 1 if not set
        $cuser = User::find($user_id);
        
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];
        if ($cuser) {
            // Handle Customer
            if ($cuser->is('customer')) {
                $jobs = $cuser->jobs()
                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);
                $usertype = 'customer';
                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => [],
                    'jobs' => $jobs,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => 0,
                    'pagenum' => 0
                ];
            }
            // Handle Translator
            if ($cuser->is('translator')) {
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
                $totalJobs = $jobs_ids->total();
                $numPages = ceil($totalJobs / 15);
                $usertype = 'translator';
                return [
                    'emergencyJobs' => $emergencyJobs,
                    'normalJobs' => $jobs_ids,
                    'jobs' => $jobs_ids,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => $numPages,
                    'pagenum' => $page
                ];
            }
        }
    }

   
    /**
     * Store a new booking for the user.
     *
     * @param \App\Models\User $user The user creating the booking.
     * @param array $data The booking data.
     * @return \Illuminate\Http\JsonResponse The response indicating success or failure.
     *
     * @throws \Exception If there is an error during the booking creation process.
     */
    public function store($user, $data)
    {
        $immediateTime = 5;

        if ($user->user_type != config('roles.customer')) {
            return $this->failResponse('Translator cannot create booking');
        }
        try {
            DB::beginTransaction();
            $this->validateRequiredFields($data);
            $data = $this->prepareBookingData($user, $data, $immediateTime);
            $job = $user->jobs()->create($data);
            DB::commit();
            return  $this->generateSuccessResponse($job, $data, $user);
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the error for debugging
            Log::error('Job creation failed: ' . $e->getMessage());
            // Return a fail response with the exception message
            return $this->failResponse($e->getMessage());
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        // Prepare Email Details
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
     
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

            // Response Preparation
        $response = [
            'type'   => $user_type,
            'job'    => $job,
            'status' => 'success',
        ];

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();
        // Prepare message details
        $dueDate = Carbon::parse($job->due);
        $date = $dueDate->format('d.m.Y');
        $time = $dueDate->format('H:i');
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;
        // Determine message type
        if ($job->customer_physical_type === 'yes' && $job->customer_phone_type !== 'yes') {
            $message = trans('sms.physical_job', [
                'date' => $date,
                'time' => $time,
                'town' => $city,
                'duration' => $duration,
                'jobId' => $jobId
            ]);
        } else {
            // Default to phone job if both are 'yes' or only phone is 'yes'
            $message = trans('sms.phone_job', [
                'date' => $date,
                'time' => $time,
                'duration' => $duration,
                'jobId' => $jobId
            ]);
        }
        // Log message template for debugging
        Log::info('SMS Message: ' . $message);
        
        // Send SMS to translators
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));
        }
        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . now()->format('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        
        // Log initial push information
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        
        // Fetch OneSignal configuration based on environment
        $onesignalAppID = config('app.' . (env('APP_ENV') === 'prod' ? 'prodOnesignalAppID' : 'devOnesignalAppID'));
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.' . (env('APP_ENV') === 'prod' ? 'prodOnesignalApiKey' : 'devOnesignalApiKey')));
        
        // Generate user tags
        $userTags = json_decode($this->getUserTagsStringFromArray($users));
        
        // Set notification sounds based on type
        $androidSound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'yes'
            ? 'emergency_booking'
            : 'normal_booking';
        
        $iosSound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'yes'
            ? 'emergency_booking.mp3'
            : 'normal_booking.mp3';
        
        // Prepare payload fields
        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => $userTags,
            'data'           => array_merge($data, ['job_id' => $job_id]),
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound,
        ];
        
        // Schedule delayed push notifications if necessary
        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }
        
        $fieldsJson = json_encode($fields);
        
        // Perform OneSignal API request
        $response = $this->sendPushNotificationToOneSignal($fieldsJson, $onesignalRestAuthKey, $job_id, $logger);
        
        return $response;
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $jobType = $job->job_type;

        // Determine translator type based on job type
        $translatorType = match ($jobType) {
            'paid'   => 'professional',
            'rws'    => 'rwstranslator',
            'unpaid' => 'volunteer',
            default  => null
        };
        
        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];
        
        // Determine translator levels based on certification
        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translatorLevel = [
                        'Certified',
                        'Certified with specialisation in law',
                        'Certified with specialisation in health care',
                    ];
                    break;
                case 'law':
                case 'n_law':
                    $translatorLevel[] = 'Certified with specialisation in law';
                    break;
                case 'health':
                case 'n_health':
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case 'normal':
                    $translatorLevel = [
                        'Layman',
                        'Read Translation courses',
                    ];
                    break;
                default:
                    $translatorLevel = [
                        'Certified',
                        'Certified with specialisation in law',
                        'Certified with specialisation in health care',
                        'Layman',
                        'Read Translation courses',
                    ];
                    break;
            }
        }
        
        // Fetch blacklist translator IDs
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        
        // Get potential users
        $potentialUsers = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $blacklist);
        
        return $potentialUsers;

    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;
        // Handle Translator Change
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
            $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
        }
      // Handle Due Date Change
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
            $this->sendChangedDateNotification($job, $old_time);
        }

        // Handle Language Change
        $langChanged = false;
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
            $this->sendChangedLangNotification($job, $old_lang);
        }
        // Handle Status Change
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        // Update Admin Comments and Reference
        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        // Log the Update
        $this->logger->addInfo(
            'USER #' . $cuser->id . '(' . $cuser->name . ')' 
            . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
            $log_data
        );

        // Save the Job and Perform Actions Based on Due Date
        $job->save();
        if ($job->due > Carbon::now()) {
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        } else {
            return ['Updated'];
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }


//        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        // Check for translator changes
        $hasTranslatorData = !is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || !empty($data['translator_email']);
        if ($hasTranslatorData) {
            // Set new translator ID if email is provided
            if (!empty($data['translator_email'])) {
                $translatorUser = User::where('email', $data['translator_email'])->first();
                $data['translator'] = $translatorUser ? $translatorUser->id : null;
            }
            // Case: Change existing translator
            if (
                !is_null($current_translator)
                && ((isset($data['translator']) && $current_translator->user_id != $data['translator'])
                || !empty($data['translator_email']))
                && (isset($data['translator']) && $data['translator'] != 0)
            ) {

                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);

                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
                // Case: Add new translator if none exists
            } elseif (
                is_null($current_translator)
                && isset($data['translator'])
                && ($data['translator'] != 0 || !empty($data['translator_email']))
            ) {

                $new_translator = Translator::create([
                    'user_id' => $data['translator'],
                    'job_id' => $job->id
                ]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }

            // Return if translator was changed
            if ($translatorChanged) {
                return [
                    'translatorChanged' => $translatorChanged,
                    'new_translator' => $new_translator,
                    'log_data' => $log_data
                ];
            }
        }
        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($input, $cuser)
    {
        // Determine input type (array or job_id)
        $job_id = is_array($input) ? $input['job_id'] : $input;
    
        // Fetch the job
        $job = Job::findOrFail($job_id);
    
        // Check if translator is already booked
        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
            ];
        }
    
        // Assign the job if pending and insert relation
        if ($job->status === 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();
    
            // Fetch user details
            $user = $job->user()->first();
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
    
            // Send email notification
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.job-accepted', [
                'user' => $user,
                'job'  => $job
            ]);
    
            // Send push notification (if required)
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $msg_text = [
                "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
            ];
            if ($this->isNeedToSendPush($user->id)) {
                $users_array = [$user];
                $this->sendPushNotificationToSpecificUsers($users_array, $job_id, ['notification_type' => 'job_accepted'], $msg_text, $this->isNeedToDelayPush($user->id));
            }
    
            // Fetch potential jobs (for `acceptJob` behavior)
            $jobs = $this->getPotentialJobs($cuser);
    
            return [
                'status' => 'success',
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
            ];
        }
    
        // Booking already accepted by someone else
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        return [
            'status' => 'fail',
            'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
        ];
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        
            // Fetch job and translator details
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $hoursDiff = $job->withdraw_at->diffInHours($job->due);

            $job->status = $hoursDiff >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $response['jobstatus'] = 'success';

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            // Send push notification to the translator
            if ($translator) {
                $this->notifyTranslatorOnCancellation($translator, $job);
            }

        } else {
            $hoursDiff = $job->due->diffInHours(Carbon::now());

            if ($hoursDiff > 24) {
                $customer = $job->user()->first();
                if ($customer) {
                    $this->notifyCustomerOnTranslatorCancellation($customer, $job);
                }

                // Reset job to pending and notify suitable translators
                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);
                $this->sendNotificationTranslator($job, $this->jobToData($job), $translator->id);

                $response['status'] = 'success';
            } else {
                $response = [
                    'status' => 'fail',
                    'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!'
                ];
            }
        }
        return $response;
    }

 
    /**
     * Retrieves potential jobs for the given user based on their profile and preferences.
     *
     * @param User $cuser The current user object.
     * @return array An array of job objects that match the user's criteria.
     *
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translatorType = $cuser_meta->translator_type;
        $job_type = match ($translatorType) {
            'professional' => 'paid', // For professional translators
            'rwstranslator' => 'rws', // For RWS translators
            'volunteer' => 'unpaid',  // For volunteers
            default => 'unpaid',
        };

        $languages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        
        // Fetch job IDs matching criteria
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $languages, $gender, $translator_level);
        
        foreach ($job_ids as $k => $job) {
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
        
            $checktown = Job::checkTowns($job->user_id, $cuser->id);
            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completedDate = now()->format('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobId);
        
        // Check job status
        if ($job->status !== 'started') {
            return ['status' => 'success'];
        }
        
        // Calculate session time
        $dueDate = $job->due;
        $interval = date_diff(date_create($completedDate), date_create($dueDate));
        $sessionTime = $interval->h . ':' . $interval->i . ':' . $interval->s;
        
        // Update job details
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $sessionTime;
        
        // Prepare email details for customer
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $sessionFormatted = $interval->h . ' tim ' . $interval->i . ' min';
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $mailData = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionFormatted,
            'for_text'     => 'faktura'
        ];
        
        // Send email to customer
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $mailData);
        
        // Save job updates
        $job->save();
        // Fetch translator details and send email
        $translatorRel = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $translatorRel->user_id : $job->user_id));
        
        $translator = $translatorRel->user()->first();
        $mailData['for_text'] = 'lön';
        $mailer->send($translator->email, $translator->name, $subject, 'emails.session-ended', $mailData);
        
        // Update translator relationship
        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $post_data['user_id'];
        $translatorRel->save();
        
        return ['status' => 'success'];
    }


    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;
        $allJobs = Job::query();
       
        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            if (!empty($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', 0)
                      ->whereHas('feedback', fn($q) => $q->where('rating', '<=', 3));
            }
            if (!empty($requestData['customer_email'])) {
                $userIds = DB::table('users')->whereIn('email', $requestData['customer_email'])->pluck('id');
                $allJobs->whereIn('user_id', $userIds);
            }
        
            if (!empty($requestData['translator_email'])) {
                $translatorIds = DB::table('users')->whereIn('email', $requestData['translator_email'])->pluck('id');
                $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $translatorIds)->pluck('job_id');
                $allJobs->whereIn('id', $jobIds);
            }

        } else {
            if ($consumerType == 'RWS') {
                $allJobs->where('job_type', 'rws');
            } else {
                $allJobs->where('job_type', 'unpaid');
            }
            if (!empty($requestData['customer_email'])) {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', $user->id);
                }
            }
        }
        /* filters that are applied to both superadmin and normal user jobs */
        $this->applyCommonFilters($allJobs, $requestData);
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        $allJobs->orderBy('created_at', 'desc');
        return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobIds = [];
        
        // Process job session times and filter based on duration
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $totalMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($totalMinutes >= $job->duration && $totalMinutes >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }
        
        $jobIds = collect($sesJobs)->pluck('id')->all();
        
        // Fetch related data
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->pluck('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->pluck('email');
        
        $cuser = Auth::user();
        $consumerType = TeHelper::getUsermeta($cuser->id, 'consumer_type');
        
        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobIds)
                ->where('jobs.ignore', 0);
        
            // Apply filters
            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang']);
            }
        
            if (!empty($requestData['status'])) {
                $allJobs->whereIn('jobs.status', $requestData['status']);
            }
        
            if (!empty($requestData['customer_email'])) {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }
        
            if (!empty($requestData['translator_email'])) {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs);
                }
            }
        
            if (!empty($requestData['filter_timetype'])) {
                $timeFilter = $requestData['filter_timetype'];
                if ($timeFilter === 'created') {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('jobs.created_at', '>=', $requestData['from']);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData['to'] . ' 23:59:00';
                        $allJobs->where('jobs.created_at', '<=', $to);
                    }
                    $allJobs->orderBy('jobs.created_at', 'desc');
                } elseif ($timeFilter === 'due') {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('jobs.due', '>=', $requestData['from']);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData['to'] . ' 23:59:00';
                        $allJobs->where('jobs.due', '<=', $to);
                    }
                    $allJobs->orderBy('jobs.due', 'desc');
                }
            }
        
            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type']);
            }
        
            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');
        
            $allJobs = $allJobs->paginate(15);
        }
        
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $allCustomers,
            'all_translators' => $allTranslators,
            'requestdata' => $requestData
        ];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->pluck('email')->all();
        $allTranslators = DB::table('users')->where('user_type', '2')->pluck('email')->all();

        $cuser = Auth::user();

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->select('jobs.*', 'languages.language')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());
        
            // Filters: Language
            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang']);
            }
        
            // Filters: Status
            if (!empty($requestData['status'])) {
                $allJobs->whereIn('jobs.status', $requestData['status']);
            }
        
            // Filters: Customer Email
            if (!empty($requestData['customer_email'])) {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }
        
            // Filters: Translator Email
            if (!empty($requestData['translator_email'])) {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $jobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->all();
                    $allJobs->whereIn('jobs.id', $jobIDs);
                }
            }
        
            // Filters: Time-based (created or due)
            if (!empty($requestData['filter_timetype'])) {
                $from = $requestData['from'] ?? null;
                $to = !empty($requestData['to']) ? $requestData['to'] . ' 23:59:00' : null;
        
                if ($requestData['filter_timetype'] === 'created') {
                    if ($from) $allJobs->where('jobs.created_at', '>=', $from);
                    if ($to) $allJobs->where('jobs.created_at', '<=', $to);
                    $allJobs->orderBy('jobs.created_at', 'desc');
                } elseif ($requestData['filter_timetype'] === 'due') {
                    if ($from) $allJobs->where('jobs.due', '>=', $from);
                    if ($to) $allJobs->where('jobs.due', '<=', $to);
                    $allJobs->orderBy('jobs.due', 'desc');
                }
            }
        
            // Filters: Job Type
            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type']);
            }
        
            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $allCustomers,
            'all_translators' => $allTranslators,
            'requestdata' => $requestData
        ];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reOpenBooking($request)
    {
        try {
            DB::beginTransaction();

            $jobId = $request['jobid'];
            $userId = $request['userid'];

            // Fetch the job
            $job = Job::findOrFail($jobId);
            $now = Carbon::now();

            // Prepare common data for translator relation
            $translatorData = [
                'created_at' => $now,
                'will_expire_at' => TeHelper::willExpireAt($job->due, $now),
                'updated_at' => $now,
                'user_id' => $userId,
                'job_id' => $jobId,
                'cancel_at' => $now,
            ];

            // Reopen job logic
            if ($job->status !== 'timedout') {
                // Update job status to pending
                $job->update([
                    'status' => 'pending',
                    'created_at' => $now,
                    'will_expire_at' => TeHelper::willExpireAt($job->due, $now)
                ]);
                $newJobId = $jobId;
            } else {
                // Recreate job with modifications
                $newJob = $job->replicate();
                $newJob->status = 'pending';
                $newJob->created_at = $now;
                $newJob->updated_at = $now;
                $newJob->will_expire_at = TeHelper::willExpireAt($job->due, $now);
                $newJob->cust_16_hour_email = 0;
                $newJob->cust_48_hour_email = 0;
                $newJob->admin_comments = "This booking is a reopening of booking #{$jobId}";
                $newJob->save();

                $newJobId = $newJob->id;
            }

            // Update translator relation
            Translator::where('job_id', $jobId)
                ->whereNull('cancel_at')
                ->update(['cancel_at' => $now]);

            Translator::create($translatorData);

            // Send notification and return response
            $this->sendNotificationByAdminCancelJob($newJobId);
            DB::commit();
            return ['success', 'Tolk cancelled!'];
        } catch (\Exception $e) {
            DB::rollBack();
            // Log the error for debugging
            Log::error('Job reopening failed: ' . $e->getMessage());
            // Return a fail response with the exception message
            return $this->failResponse($e->getMessage());
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

    
    /**
     * Generates a failure response array with a given message.
     *
     * @param string $message The failure message to include in the response.
     * @return array An associative array containing the status 'fail' and the provided message.
     */
    private function failResponse($message)
    {
        return ['status' => 'fail', 'message' => $message];
    }

    /**
     * Validate required fields for job creation.
     *
     * @param array $data The data to validate.
     * @throws \Exception If any required field is missing or empty.
     */
    private function validateRequiredFields($data)
    {
        $requiredFields = ['from_language_id'];
        if ($data['immediate'] == 'no') {
            /* add required fields based on immediate flag. */
            $requiredFields = array_merge($requiredFields, ['due_date', 'due_time', 'duration']);
            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                throw new \Exception("Du måste göra ett val här", 422);
            }
        }

        foreach ($requiredFields as $field) {
            /* throw exception if any requried field is missing. */
            if (!isset($data[$field]) || $data[$field] == '') {
                throw new \Exception("Du måste fylla in alla fält. Missing: $field", 422);
            }
        }
    }

    /**
     * Prepares booking data by setting various attributes based on the provided user and data.
     *
     * @param \App\Models\User $user The user object containing user metadata.
     * @param array $data The booking data to be prepared.
     * @param int $immediateTime The time in minutes to add for immediate bookings.
     * 
     * @return array The prepared booking data.
     * 
     * @throws \Exception If the booking is attempted to be created in the past.
     */
    private function prepareBookingData($user, $data, $immediateTime)
    {
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes') {
            $dueCarbon = Carbon::now()->addMinutes($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . ' ' . $data['due_time'];
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            if ($dueCarbon->isPast()) {
                throw new \Exception("Can't create booking in the past", 422);
            }
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['type'] = 'regular';
        }
        $this->setGenderAndCertification($data);
      
        $data['job_type'] = $this->determineJobType($user->userMeta->consumer_type);
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['by_admin'] = $data['by_admin'] ?? 'no';
        if (isset($data['due'])) {
            $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        }
        return $data;
    }
    /**
     * Sets the gender and certification status in the provided data array based on the 'job_for' field.
     * @param array $data The data array that contains the 'job_for' field and will be updated with 'gender' and 'certified' fields.
     * The function sets the 'gender' field in the data array to either 'male' or 'female' based on the presence of these values in the 'job_for' field.
     */
    private function setGenderAndCertification(&$data)
    {

        $genderOptions = ['male', 'female'];
        $certificationOptions = [
            'normal' => 'normal',
            'certified' => 'yes',
            'certified_in_law' => 'law',
            'certified_in_helth' => 'health'
        ];

        foreach ($genderOptions as $gender) {
            if (in_array($gender, $data['job_for'])) {
            $data['gender'] = $gender;
            break;
            }
        }

        foreach ($certificationOptions as $key => $value) {
            if (in_array($key, $data['job_for'])) {
            $data['certified'] = $value;
            break;
            }
        }

        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
            $data['certified'] = 'both';
        } elseif (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'n_law';
        } elseif (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
            $data['certified'] = 'n_health';
        }
    }

    private function determineJobType($consumerType)
    {
        /* return job type based on consumer type (paid by default) */
        return match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            default => 'paid',
        };
    }
    private function generateSuccessResponse($job, $data, $user)
    {
        $response = [
            'status' => 'success',
            'id' => $job->id,
            'customer_physical_type' => $data['customer_physical_type'],
            'type' => $data['type'],
            'job_for' => $this->formatJobForResponse($job),
            'customer_town' => $user->userMeta->city,
            'customer_type' => $user->userMeta->customer_type,
        ];

        return $response;
    }

    private function formatJobForResponse($job)
    {
        $jobFor = [];
        if ($job->gender) {
            $jobFor[] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified == 'both') {
            $jobFor[] = 'normal';
            $jobFor[] = 'certified';
        } elseif ($job->certified) {
            $jobFor[] = $job->certified;
        }
        return $jobFor;
    }

    /**
     * Apply common filters to the query based on the provided request data.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query The query builder instance.
     * @param array $requestData The request data containing filter criteria.
     *
     */
    private function applyCommonFilters($query, $requestData)
    {
        if (!empty($requestData['id'])) {
            $query->whereIn('id', is_array($requestData['id']) ? $requestData['id'] : [$requestData['id']]);
        }

        if (!empty($requestData['lang'])) {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (!empty($requestData['status'])) {
            $query->whereIn('status', $requestData['status']);
        }

        if (!empty($requestData['job_type'])) {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (!empty($requestData['expired_at'])) {
            $query->where('expired_at', '>=', $requestData['expired_at']);
        }

        if (!empty($requestData['will_expire_at'])) {
            $query->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        if (!empty($requestData['filter_timetype'])) {
            $this->applyDateFilters($query, $requestData);
        }

        if (!empty($requestData['physical'])) {
            $query->where('customer_physical_type', $requestData['physical'])->where('ignore_physical', 0);
        }

        if (!empty($requestData['phone'])) {
            $query->where('customer_phone_type', $requestData['phone'])->where('ignore_physical_phone', 0);
        }

        if (!empty($requestData['flagged'])) {
            $query->where('flagged', $requestData['flagged'])->where('ignore_flagged', 0);
        }

        if (!empty($requestData['distance']) && $requestData['distance'] == 'empty') {
            $query->whereDoesntHave('distance');
        }

        if (!empty($requestData['salary']) && $requestData['salary'] == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }

        if (!empty($requestData['consumer_type'])) {
            $query->whereHas('user.userMeta', fn($q) => $q->where('consumer_type', $requestData['consumer_type']));
        }

        if (!empty($requestData['booking_type'])) {
            if ($requestData['booking_type'] == 'physical') {
                $query->where('customer_physical_type', 'yes');
            } elseif ($requestData['booking_type'] == 'phone') {
                $query->where('customer_phone_type', 'yes');
            }
        }
    }

        /**
         * Applies date filters to the query based on the request data.
         * @param array $requestData The request data containing filter information.
         * @return void
         */
    private function applyDateFilters($query, $requestData)
    {
        $field = $requestData['filter_timetype'] == 'created' ? 'created_at' : 'due';
        if (!empty($requestData['from'])) {
            $query->where($field, '>=', $requestData['from']);
        }
        if (!empty($requestData['to'])) {
            $to = $requestData['to'] . ' 23:59:00';
            $query->where($field, '<=', $to);
        }
        $query->orderBy($field, 'desc');
    }

     // Inline notification logic for Translator Cancellation
    private function notifyTranslatorOnCancellation($translator, $job)
    {
        $data = ['notification_type' => 'job_cancelled'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    // Inline notification logic for Customer Cancellation
    private function notifyCustomerOnTranslatorCancellation($customer, $job)
    {
        $data = ['notification_type' => 'job_cancelled'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        ];

        if ($this->isNeedToSendPush($customer->id)) {
            $users_array = [$customer];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
        }
    }

    /**
     * Updates the distance feed and job details based on the provided data.
     *
     * @param array $data An associative array containing the following keys:
     *
     * @return \Illuminate\Http\Response|string A response indicating the result of the update operation.
     *  - Returns a 400 response with a message if the job is flagged and no admin comment is provided.
     *  - Returns a success message if the update is successful.
     */
    public function updateDistanceFeed($data){
        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? null;
        $session = $data['session_time'] ?? '';
        $admincomment = $data['admincomment'] ?? '';

        if (($data['flagged'] ?? 'false') === 'true') {
            if (empty($admincomment)) {
                return response('Please, add comment', 400);
            }
            $flagged = 'yes';
        } else {
            $flagged = 'no';
        }

        $manually_handled = ($data['manually_handled'] ?? 'false') === 'true' ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] ?? 'false') === 'true' ? 'yes' : 'no';

        // Update distance and time if provided
        if ($time || $distance) {
            Distance::where('job_id', $jobid)
                ->update([
                    'distance' => $distance,
                    'time'     => $time
                ]);
        }

        // Update job details if relevant fields are provided
        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', $jobid)
                ->update([
                    'admin_comments'    => $admincomment,
                    'flagged'           => $flagged,
                    'session_time'      => $session,
                    'manually_handled'  => $manually_handled,
                    'by_admin'          => $by_admin
                ]);
        }
        return "Record updated successfully";
    }

    private function sendPushNotificationToOneSignal($fields, $authKey, $jobId, $logger)
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://onesignal.com/api/v1/notifications",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', $authKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $logger->addError('Push send error for job ' . $jobId, ['error' => curl_error($ch)]);
        } else {
            $logger->addInfo('Push sent for job ' . $jobId . ' curl response', [$response]);
        }

        curl_close($ch);

        return $response;
    }
}