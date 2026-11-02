<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminService;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index(Request $r)
    {
        $like = $r->filled('search') ? '%'.addcslashes($r->search, '%_\\').'%' : null;
        $users = User::query()
            ->when($r->filled('role'), fn ($q) => $q->where('role', $r->role))
            ->when($r->filled('status'), fn ($q) => $q->where('status', $r->status))
            ->when($r->role === 'candidate', fn ($q) => $q->with('candidateProfile:id,user_id,profile_photo,city,state,current_job_title,total_experience,profile_data'))
            ->when($r->role === 'company', fn ($q) => $q->with('company:id,user_id,company_name,city,state,verification_status,institution_code,parent_id,branch_status,branch_admin_status'))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->latest('id')->paginate(min((int) $r->query('per_page', 20), 100));
        return ApiResponse::success($users);
    }

    public function block(int $id)
    {
        return ApiResponse::success($this->admin->setUserStatus(User::findOrFail($id), 'blocked'), 'User blocked');
    }

    public function unblock(int $id)
    {
        return ApiResponse::success($this->admin->setUserStatus(User::findOrFail($id), 'active'), 'User unblocked');
    }

    public function show(int $id)
    {
        // Everything the admin needs to see the full profile: saved form (profile_data), skills and uploaded documents.
        // Institution accounts: the whole company row (institution form in profile_data.inst, code, approval states),
        // its main institution / sub-branches, and the proof documents it uploaded.
        $user = User::with([
            'candidateProfile',
            'company.parent:id,company_name,city,state,institution_code',
            'company.branches:id,parent_id,company_name,city,state,branch_status,branch_admin_status,verification_status',
            'skills:id,name',
            'resumes.file',
            'certificates.file',
        ])->findOrFail($id);

        if ($user->isCompany()) {
            $user->setRelation('files', $user->files()->whereIn('category', ['other', 'company_logo'])
                ->latest('id')->get(['id', 'user_id', 'original_name', 'file_type', 'file_size', 'category', 'created_at']));
        }
        return ApiResponse::success($user);
    }

    public function update(Request $r, int $id)
    {
        $user = User::findOrFail($id);
        $d = $r->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => ['sometimes', 'required', 'email', 'max:190', 'unique:users,email,'.$user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:active,blocked'],
        ]);
        if (isset($d['email'])) $d['email'] = strtolower($d['email']);
        return ApiResponse::success($this->admin->updateUser($user, $d), 'User updated');
    }

    public function destroy(int $id, FileUploadService $uploads)
    {
        $this->admin->deleteUser(User::findOrFail($id), $uploads);
        return ApiResponse::success(null, 'User deleted');
    }
}
