<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminService
{
    public function __construct(private NotificationService $notifications) {}

    public function dashboard(): array
    {
        return Cache::remember('admin:dashboard', 60, function () {
            $apps = Application::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $cos = Company::selectRaw('verification_status as s, count(*) as c')->groupBy('verification_status')->pluck('c', 's');
            return [
                'total_candidates' => User::where('role', 'candidate')->count(),
                'total_companies' => Company::count(),
                'total_jobs' => Job::count(),
                'active_jobs' => Job::published()->count(),
                'total_applications' => (int) $apps->sum(),
                'pending_companies' => Company::where('verification_status', 'pending')->count(),
                'selected_candidates' => (int) ($apps['selected'] ?? 0),
                'rejected_applications' => (int) ($apps['rejected'] ?? 0),
                'companies_by_status' => collect(Company::STATUSES)->mapWithKeys(fn ($st) => [$st => (int) ($cos[$st] ?? 0)])->all(),
            ];
        });
    }

    public function setCompanyStatus(Company $company, string $status, ?string $reason = null): Company
    {
        DB::transaction(function () use ($company, $status) {
            $company->update(['verification_status' => $status]);
            if ($status === 'blocked') $company->jobs()->where('status', 'published')->update(['status' => 'closed']);
        });
        Cache::forget('admin:dashboard');
        JobService::bumpCache();

        $msg = [
            'approved' => ['Company approved', 'Your company has been approved. You can now publish jobs.'],
            'rejected' => ['Company rejected', 'Your company registration was rejected. Please update your profile or contact support.'],
            'blocked' => ['Company blocked', 'Your company has been blocked and its jobs were closed.'],
        ][$status] ?? null;
        if ($msg) $this->notifications->notify($company->user_id, 'company_'.$status, $msg[0], $msg[1].($reason ? ' Reason: '.$reason : ''));
        return $company->fresh();
    }

    public function setUserStatus(User $user, string $status): User
    {
        abort_if($user->isAdmin(), 403, 'Admin accounts cannot be blocked here');
        $user->update(['status' => $status]);
        if ($status === 'blocked') $user->tokens()->delete(); // kicks them out immediately
        Cache::forget('admin:dashboard');
        return $user->fresh();
    }

    /** Admin edits a user's name / email / phone / status. A status change goes through setUserStatus (admins protected, tokens revoked on block). */
    public function updateUser(User $user, array $d): User
    {
        $status = $d['status'] ?? null;
        unset($d['status']);
        if ($d) $user->fill($d)->save();
        if ($status !== null && $status !== $user->status) $this->setUserStatus($user, $status);
        return $user->fresh();
    }

    /** Remove the account: stored files first (nothing orphaned in storage), then the row; profile, jobs, applications, notifications cascade. */
    public function deleteUser(User $user, FileUploadService $uploads): void
    {
        abort_if($user->isAdmin(), 403, 'Admin accounts cannot be deleted here.');
        foreach ($user->files()->get() as $file) {
            if (! $uploads->delete($file)) abort(502, 'Could not remove the stored files. Nothing was deleted — please try again.');
        }
        DB::transaction(function () use ($user) {
            if ($user->company) {   // sub-branches of a deleted main institution become independent again
                Company::where('parent_id', $user->company->id)->update(['parent_id' => null, 'branch_status' => null, 'branch_admin_status' => null]);
            }
            $user->tokens()->delete();
            $user->delete();
        });
        Cache::forget('admin:dashboard');
        JobService::bumpCache();
    }

    public function updateCompany(Company $company, array $d): Company
    {
        $company->update($d);
        JobService::bumpCache();   // company name/logo appear inside cached job listings
        return $company->fresh();
    }

    /** A company only exists with its owner account, so deleting it removes the account too (same cleanup as deleteUser). */
    public function deleteCompany(Company $company, FileUploadService $uploads): void
    {
        if ($company->user) {
            $this->deleteUser($company->user, $uploads);
            return;
        }
        Company::where('parent_id', $company->id)->update(['parent_id' => null, 'branch_status' => null, 'branch_admin_status' => null]);
        $company->delete();
        Cache::forget('admin:dashboard');
        JobService::bumpCache();
    }

    public function deleteApplication(Application $application): void
    {
        $application->delete();   // status history cascades
        Cache::forget('admin:dashboard');
    }
}
