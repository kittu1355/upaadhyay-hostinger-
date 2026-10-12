<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Company;
use App\Models\File;
use App\Services\AdminService;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminCompanyController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index(Request $r)
    {
        $like = $r->filled('search') ? '%'.addcslashes($r->search, '%_\\').'%' : null;
        $rows = Company::query()->with(['user:id,name,email,phone,status', 'parent:id,company_name'])->withCount('jobs')
            ->when($r->filled('status'), fn ($q) => $q->where('verification_status', $r->status))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('company_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('city', 'like', $like)))
            ->latest('id')->paginate(min((int) $r->query('per_page', 20), 100));
        return ApiResponse::success($rows);
    }

    /**
     * Everything the institution shared, in one response, so the admin can review it on a single page:
     * profile columns, the saved institution form (profile_data), the uploaded proof documents, branches and activity counts.
     * Documents are listed from the files table (owner = the institution's user) and opened through GET /files/{id}/link.
     */
    public function show(int $id)
    {
        $company = Company::with(['user:id,name,email,phone,status,created_at', 'parent:id,company_name,city,state,institution_code'])
            ->withCount('jobs')->findOrFail($id);

        $files = File::where('user_id', $company->user_id)
            ->whereIn('category', ['other', 'company_logo'])
            ->latest('id')->get(['id', 'original_name', 'file_type', 'file_size', 'category', 'created_at']);

        return ApiResponse::success([
            'company' => $company,
            'files' => $files,
            'branches' => $company->branches()->get(['id', 'company_name', 'city', 'state', 'branch_status', 'verification_status']),
            'stats' => [
                'jobs' => $company->jobs_count,
                'live_jobs' => $company->jobs()->where('status', 'published')->count(),
                'applications' => Application::whereHas('job', fn ($j) => $j->where('company_id', $company->id))->count(),
            ],
        ]);
    }

    public function approve(int $id)
    {
        return ApiResponse::success($this->admin->setCompanyStatus(Company::findOrFail($id), 'approved'), 'Company approved');
    }

    /** Optional reason (max 500 chars) is sent to the institution in its notification so it knows what to fix. */
    public function reject(Request $r, int $id)
    {
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:500']]);
        return ApiResponse::success($this->admin->setCompanyStatus(Company::findOrFail($id), 'rejected', $d['reason'] ?? null), 'Company rejected');
    }

    public function block(Request $r, int $id)
    {
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:500']]);
        return ApiResponse::success($this->admin->setCompanyStatus(Company::findOrFail($id), 'blocked', $d['reason'] ?? null), 'Company blocked');
    }

    public function update(Request $r, int $id)
    {
        $company = Company::findOrFail($id);
        $d = $r->validate([
            'company_name' => ['sometimes', 'required', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_size' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        return ApiResponse::success($this->admin->updateCompany($company, $d), 'Company updated');
    }

    public function destroy(int $id, FileUploadService $uploads)
    {
        $this->admin->deleteCompany(Company::with('user')->findOrFail($id), $uploads);
        return ApiResponse::success(null, 'Company deleted');
    }
}
