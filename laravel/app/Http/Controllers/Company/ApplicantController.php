<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ApplicantController extends Controller
{
    public function __construct(private ApplicationService $apps) {}

    public function index(Request $r)
    {
        return ApiResponse::success($this->apps->listForCompany($r->user(), $r->query()));
    }

    public function show(Request $r, int $id)
    {
        return ApiResponse::success($this->apps->view($r->user(), $id));
    }

    /** Employer-side candidate search (the "Candidates" tab). */
    public function candidates(Request $r)
    {
        $f = $r->query();
        $like = ! empty($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;
        $rows = \App\Models\User::query()
            ->where('role', \App\Models\User::ROLE_CANDIDATE)->where('status', 'active')
            ->select('id', 'name')
            ->with('candidateProfile:id,user_id,profile_photo,city,state,bio,current_job_title,total_experience,profile_data')
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhereHas('candidateProfile', fn ($p) => $p->where('current_job_title', 'like', $like)->orWhere('city', 'like', $like))))
            ->latest('id')
            ->paginate(min(max((int) ($f['per_page'] ?? 30), 1), 50));
        return ApiResponse::success($rows);
    }

    /** Full candidate profile for the employer's candidate page (resume/certificate file ids included for signed links). */
    public function candidate(Request $r, int $id)
    {
        $u = \App\Models\User::where('role', \App\Models\User::ROLE_CANDIDATE)->where('status', 'active')
            ->select('id', 'name')
            ->with(['candidateProfile', 'skills:id,name', 'resumes.file:id,original_name,file_type', 'certificates.file:id,original_name,file_type'])
            ->findOrFail($id);
        return ApiResponse::success($u);
    }

    /** Employer -> candidate message (delivered as an in-app notification). */
    public function message(Request $r, \App\Services\NotificationService $notifications)
    {
        $d = $r->validate(['candidate_id' => ['required', 'integer'], 'message' => ['required', 'string', 'max:1000']]);
        $co = $r->user()->company;
        abort_unless($co, 403, 'Only companies can send messages.');
        $cand = \App\Models\User::where('role', \App\Models\User::ROLE_CANDIDATE)->where('status', 'active')->findOrFail($d['candidate_id']);
        $notifications->notify($cand, 'message', 'Message from '.$co->company_name, $d['message'], ['company_id' => $co->id]);
        return ApiResponse::success(null, 'Message sent');
    }

    public function invite(Request $r)
    {
        $d = $r->validate([
            'candidate_id' => ['required', 'integer'],
            'job_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->apps->invite($r->user(), $d['candidate_id'], $d['job_id'] ?? null, $d['message'] ?? null);
        return ApiResponse::success(null, 'Invitation sent');
    }
}
