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
        return ApiResponse::success(User::with(['candidateProfile', 'company:id,user_id,company_name'])->findOrFail($id));
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
