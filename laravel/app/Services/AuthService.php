<?php

namespace App\Services;

use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $d): array
    {
        $user = DB::transaction(function () use ($d) {
            $user = User::create([
                'name' => $d['name'], 'email' => strtolower($d['email']), 'phone' => $d['phone'] ?? null,
                'password' => $d['password'], 'role' => $d['role'], 'status' => 'active',
            ]);
            if ($d['role'] === User::ROLE_CANDIDATE) {
                CandidateProfile::create(['user_id' => $user->id]);
            } else {
                Company::create([
                    'user_id' => $user->id, 'company_name' => $d['company_name'],
                    'email' => $user->email, 'phone' => $user->phone,
                    'verification_status' => config('app.auto_approve_companies') ? 'approved' : 'pending', // else admin must approve before posting jobs
                ]);
            }
            return $user;
        });

        return ['user' => $this->withProfile($user), 'token' => $user->createToken('auth_token')->plainTextToken];
    }

    /** UI label for each login area, used in the "No X Account Found" message. */
    private const AREA_LABEL = ['candidate' => 'Faculty', 'company' => 'Institution', 'admin' => 'Admin'];

    /**
     * Role-gated login. $area = the login page being used ('candidate' | 'company' | 'admin').
     * A token is minted ONLY when the credentials are valid AND the account's database role equals $area.
     * An account of another role is never logged in through this area (and gets no token at all).
     */
    public function login(string $email, string $password, ?string $area = null): array
    {
        $user = User::where('email', strtolower($email))->first();
        $label = self::AREA_LABEL[$area] ?? null;

        if ($area && ! $user) {
            throw $this->noAccount($label);
        }
        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }
        if ($area && $user->role !== $area) {
            // valid password but the account belongs to a different role: reject, no token, no hint about which role
            throw $this->noAccount($label);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => ['This account has been blocked.']]);
        }
        return ['user' => $this->withProfile($user), 'token' => $user->createToken('auth_token')->plainTextToken];
    }

    private function noAccount(string $label): ValidationException
    {
        return ValidationException::withMessages(['email' => ["No {$label} Account Found. This email is not registered as a {$label} account."]]);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => strtolower($email)]); // result ignored: no account enumeration
    }

    public function resetPassword(array $d): void
    {
        $status = Password::reset($d, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // log out everywhere
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }
    }

    public function withProfile(User $user): User
    {
        return $user->load($user->isCandidate() ? 'candidateProfile' : ($user->isCompany() ? 'company' : []));
    }
}
