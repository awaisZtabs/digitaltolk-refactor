<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }
    
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $userId = $request->get('user_id');

        if ($userId) {
            $response = $this->repository->getUsersJobs($userId);
        } elseif ($this->isAdminUser($request->__authenticatedUser)) {
            $response = $this->repository->getAll($request);
        } else {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }

        return response()->json($response, 200);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }
    
        return response()->json($job, 200);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->store($request->__authenticatedUser, $data);
        
        if (isset($response['status']) && $response['status'] === 'fail') {
            return response()->json($response, 400); // Bad Request
        }
        return response()->json($response, 201); // Success: Created
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->except(['_token', 'submit']); // Exclude unnecessary fields
        $cuser = $request->__authenticatedUser;
    
        $response = $this->repository->updateJob($id, $data, $cuser);
    
        return response()->json($response); 
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);
        return response()->json($response); 
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {

        $userId = $request->user_id;
        if(!$userId || $userId == "") {
            return response()->json(['error' => 'User id is required'], 400);
        }
        $response = $this->repository->getUsersJobsHistory($user_id, $request);
        return response()->json($response, 200);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($data, $user);
        return response()->json($response, 200);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($data, $user);

        return response()->json($response, 200);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->cancelJobAjax($data, $user);

        return response()->json($response, 200);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return response()->json($response, 200);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->customerNotCall($data);

        return response()->json($response, 200);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $user = $request->__authenticatedUser;

        $response = $this->repository->getPotentialJobs($user);

        return response()->json($response, 200);

    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->updateDistanceFeed($data);
        return response()->json($response, 200);
    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reOpenBooking($data);
        if (isset($response['status']) && $response['status'] === 'fail') {
            return response()->json($response, 400); // Bad Request
        }
        return response()->json($response, 201); // Success: Created
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        try {
            $this->repository->sendNotificationTranslator($job, $job_data, '*');
            return response(['success' => 'Push sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

    
    /**
     * Check if the authenticated user is an admin or superadmin.
     *
     * @param \App\Models\User $authenticatedUser The authenticated user instance.
     * @return bool Returns true if the user is an admin or superadmin, false otherwise.
     */
    private function isAdminUser($authenticatedUser)
    {
        return in_array($authenticatedUser->user_type, [
            config('roles.admin'),
            config('roles.superadmin'),
        ]);
    }

}
