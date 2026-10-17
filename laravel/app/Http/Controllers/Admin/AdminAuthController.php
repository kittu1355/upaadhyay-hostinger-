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
        // Role is checked BEFORE a token is minted, so a faculty/institution login attempt here never creates
        // a token and never revokes that account's own sessions.
        try {
            $res = $auth->login($r->email, $r->password, 'admin');
        } catch (ValidationException) {
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }
        return ApiResponse::success($res, 'Login successful');
    }
}
