<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $r)
    {
        return ApiResponse::created($this->auth->register($r->validated()), 'Registration successful');
    }

    public function login(LoginRequest $r)
    {
        return ApiResponse::success($this->auth->login($r->email, $r->password), 'Login successful');
    }

    public function logout(Request $r)
    {
        $this->auth->logout($r->user());
        return ApiResponse::success(null, 'Logged out');
    }

    public function me(Request $r)
    {
        return ApiResponse::success($this->auth->withProfile($r->user()));
    }

    /** Settings → change password. Other devices are signed out; this one stays signed in. */
    public function changePassword(Request $r)
    {
        $d = $r->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user = $r->user();
        if (! Hash::check($d['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }
        if ($d['current_password'] === $d['password']) {
            throw ValidationException::withMessages(['password' => ['Choose a password different from your current one.']]);
        }
        $user->forceFill(['password' => $d['password']])->save();   // 'hashed' cast hashes it

        $token = $user->currentAccessToken();
        $currentId = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->getKey() : null;
        $others = $user->tokens();
        if ($currentId) $others->where('id', '!=', $currentId);
        $others->delete();
        return ApiResponse::success(null, 'Password updated');
    }

    /** Settings → delete account. Stored files (photos, logos, resumes, documents) are removed from storage first so nothing is orphaned. */
    public function deleteAccount(Request $r, FileUploadService $uploads)
    {
        $user = $r->user();
        abort_if($user->isAdmin(), 403, 'Admin accounts cannot be deleted here.');

        foreach ($user->files()->get() as $file) {
            if (! $uploads->delete($file)) {
                return ApiResponse::error('Could not remove your stored files. Nothing was deleted — please try again.', 502);
            }
        }
        DB::transaction(function () use ($user) {
            if ($user->company) {   // sub-branches of a deleted main institution become independent again
                Company::where('parent_id', $user->company->id)->update(['parent_id' => null, 'branch_status' => null]);
            }
            $user->tokens()->delete();
            $user->delete();        // profile, jobs, applications, notifications, resumes… cascade
        });
        return ApiResponse::success(null, 'Account deleted');
    }

    public function forgotPassword(Request $r)
    {
        $r->validate(['email' => ['required', 'email']]);
        $this->auth->sendResetLink($r->email);
        return ApiResponse::success(null, 'If that email exists, a reset link has been sent');
    }

    public function resetPassword(Request $r)
    {
        $d = $r->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);
        $this->auth->resetPassword($d + ['password_confirmation' => $r->password_confirmation]);
        return ApiResponse::success(null, 'Password reset successful');
    }
}
