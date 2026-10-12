<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Validation\ValidationException;

/** POST /api/admin/login — email + password, administrators only. A non-admin gets the same error as a wrong password and no token. */
class AdminAuthController extends Controller
{
    public function login(LoginRequest $r, AuthService $auth)
    {
        $res = $auth->login($r->email, $r->password);
        if (($res['user']->role ?? null) !== 'admin') {
            $res['user']->tokens()->delete();   // revoke the token just minted for a non-admin
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }
        return ApiResponse::success($res, 'Login successful');
    }
}
