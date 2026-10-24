<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Application\ApplicationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Candidate;
use App\Http\Controllers\Company;
use App\Http\Controllers\File\FileController;
use App\Http\Controllers\Job\JobController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Track\TrackController;
use Illuminate\Support\Facades\Route;

Route::get('institutions', [Company\BranchController::class, 'lookup'])->middleware('throttle:api');
Route::get('categories', fn () => \App\Support\ApiResponse::success(\App\Models\Category::orderBy('id')->get(['id', 'name', 'slug'])));
Route::get('/ping', fn () => response()->json(['success' => true, 'message' => 'pong', 'data' => null]));
// Upload rules (types + max size per category) so the frontend checks exactly what the backend enforces.
Route::get('limits', fn () => \App\Support\ApiResponse::success(['categories' => config('uploads.categories')]))->middleware('throttle:api');

// ---------- Public visit tracking (website js/track.js; stores a random visitor id only) ----------
Route::post('track', [TrackController::class, 'store'])->middleware('throttle:track');

// ---------- Public auth ----------
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// ---------- Admin sign-in (administrators only) ----------
Route::post('admin/login', [Admin\AdminAuthController::class, 'login'])->middleware('throttle:auth');

// ---------- Public job browsing ----------
Route::middleware('throttle:api')->group(function () {
    Route::get('jobs', [JobController::class, 'index']);
    Route::get('jobs/{id}', [JobController::class, 'show'])->whereNumber('id');
});

// ---------- Signed private-file download (link comes from GET /files/{id}/link) ----------
Route::get('files/{id}/download', [FileController::class, 'download'])->whereNumber('id')->middleware(['signed', 'throttle:api'])->name('files.download');

// Public images (logos/photos/job images): redirect to a short-lived storage URL. Private categories return 404.
Route::get('files/{id}/view', [FileController::class, 'view'])->whereNumber('id')->middleware('throttle:api')->name('files.view');

// ---------- Authenticated ----------
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);
    Route::delete('auth/account', [AuthController::class, 'deleteAccount']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::put('notifications/{id}/read', [NotificationController::class, 'read']);

    // Files
    Route::post('files/upload', [FileController::class, 'upload'])->middleware('throttle:uploads');
    Route::get('files/{id}/link', [FileController::class, 'link'])->whereNumber('id');
    Route::delete('files/{id}', [FileController::class, 'destroy']);

    // Applications (shared; policy decides who sees what)
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::get('applications/{id}', [ApplicationController::class, 'show']);
    Route::put('applications/{id}/status', [ApplicationController::class, 'updateStatus'])->middleware('role:company,admin');
    Route::post('jobs/{job}/apply', [ApplicationController::class, 'apply'])->middleware('role:candidate');
    Route::put('applications/{id}/withdraw', [ApplicationController::class, 'withdraw'])->middleware('role:candidate');

    // Job write operations (company owner or admin)
    Route::middleware('role:company,admin')->group(function () {
        Route::post('jobs', [JobController::class, 'store']);
        Route::put('jobs/{id}', [JobController::class, 'update']);
        Route::delete('jobs/{id}', [JobController::class, 'destroy']);
    });

    // ---------- Candidate ----------
    Route::prefix('candidate')->middleware('role:candidate')->group(function () {
        Route::get('profile', [Candidate\CandidateProfileController::class, 'show']);
        Route::put('profile', [Candidate\CandidateProfileController::class, 'update']);
        Route::delete('profile/photo', [Candidate\CandidateProfileController::class, 'removePhoto']);

        Route::get('education', [Candidate\EducationController::class, 'index']);
        Route::post('education', [Candidate\EducationController::class, 'store']);
        Route::put('education/{id}', [Candidate\EducationController::class, 'update']);
        Route::delete('education/{id}', [Candidate\EducationController::class, 'destroy']);

        Route::get('experience', [Candidate\ExperienceController::class, 'index']);
        Route::post('experience', [Candidate\ExperienceController::class, 'store']);
        Route::put('experience/{id}', [Candidate\ExperienceController::class, 'update']);
        Route::delete('experience/{id}', [Candidate\ExperienceController::class, 'destroy']);

        Route::get('skills', [Candidate\CandidateSkillController::class, 'index']);
        Route::post('skills', [Candidate\CandidateSkillController::class, 'store']);

        Route::post('resume', [Candidate\CandidateFileController::class, 'resume'])->middleware('throttle:uploads');
        Route::post('certificates', [Candidate\CandidateFileController::class, 'certificate'])->middleware('throttle:uploads');

        Route::get('applications', [Candidate\CandidateApplicationController::class, 'index']);
    });

    // ---------- Company ----------
    Route::prefix('company')->middleware('role:company')->group(function () {
        Route::get('profile', [Company\CompanyProfileController::class, 'show']);
        Route::put('profile', [Company\CompanyProfileController::class, 'update']);
        Route::delete('logo', [Company\CompanyProfileController::class, 'removeLogo']);

        Route::get('jobs', [Company\CompanyJobController::class, 'index']);
        Route::post('jobs', [Company\CompanyJobController::class, 'store']);
        Route::get('jobs/{id}', [Company\CompanyJobController::class, 'show']);
        Route::put('jobs/{id}', [Company\CompanyJobController::class, 'update']);
        Route::delete('jobs/{id}', [Company\CompanyJobController::class, 'destroy']);

        Route::get('branches', [Company\BranchController::class, 'index']);
        Route::post('branch/join', [Company\BranchController::class, 'join']);
        Route::put('branches/{id}/{action}', [Company\BranchController::class, 'decide'])->whereNumber('id');
        Route::get('candidates', [Company\ApplicantController::class, 'candidates']);
        Route::get('candidates/{id}', [Company\ApplicantController::class, 'candidate'])->whereNumber('id');
        Route::post('messages', [Company\ApplicantController::class, 'message']);
        Route::get('applicants', [Company\ApplicantController::class, 'index']);
        Route::get('applicants/{id}', [Company\ApplicantController::class, 'show']);
        Route::post('invitations', [Company\ApplicantController::class, 'invite']);
        Route::put('applications/{id}/status', [ApplicationController::class, 'updateStatus']);
    });

    // ---------- Admin ----------
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('dashboard', [Admin\DashboardController::class, 'index']);
        Route::get('analytics', [Admin\AnalyticsController::class, 'index']);
        Route::get('users', [Admin\AdminUserController::class, 'index']);
        Route::get('users/{id}', [Admin\AdminUserController::class, 'show'])->whereNumber('id');
        Route::put('users/{id}', [Admin\AdminUserController::class, 'update'])->whereNumber('id');
        Route::delete('users/{id}', [Admin\AdminUserController::class, 'destroy'])->whereNumber('id');
        Route::put('users/{id}/block', [Admin\AdminUserController::class, 'block']);
        Route::put('users/{id}/unblock', [Admin\AdminUserController::class, 'unblock']);
        Route::get('companies', [Admin\AdminCompanyController::class, 'index']);
        Route::get('companies/{id}', [Admin\AdminCompanyController::class, 'show'])->whereNumber('id');
        Route::put('companies/{id}', [Admin\AdminCompanyController::class, 'update'])->whereNumber('id');
        Route::delete('companies/{id}', [Admin\AdminCompanyController::class, 'destroy'])->whereNumber('id');
        Route::put('companies/{id}/approve', [Admin\AdminCompanyController::class, 'approve']);
        Route::put('companies/{id}/reject', [Admin\AdminCompanyController::class, 'reject']);
        Route::put('companies/{id}/block', [Admin\AdminCompanyController::class, 'block']);
        Route::put('companies/{id}/email-verification', [Admin\AdminCompanyController::class, 'emailVerification'])->whereNumber('id');
        Route::get('jobs', [Admin\AdminJobController::class, 'index']);
        Route::put('jobs/{id}/publish', [Admin\AdminJobController::class, 'publish']);
        Route::put('jobs/{id}/unpublish', [Admin\AdminJobController::class, 'unpublish']);
        Route::delete('jobs/{id}', [Admin\AdminJobController::class, 'destroy']);
        Route::get('applications', [Admin\AdminApplicationController::class, 'index']);
        Route::delete('applications/{id}', [Admin\AdminApplicationController::class, 'destroy'])->whereNumber('id');
        Route::get('files', [Admin\AdminFileController::class, 'index']);
        Route::delete('files/{id}', [Admin\AdminFileController::class, 'destroy']);
        Route::get('notifications', [Admin\AdminNotificationController::class, 'index']);
        Route::post('notifications', [Admin\AdminNotificationController::class, 'store']);
        Route::delete('notifications/{id}', [Admin\AdminNotificationController::class, 'destroy']);
        Route::apiResource('skills', Admin\AdminSkillController::class)->except('show');
        Route::apiResource('categories', Admin\AdminCategoryController::class)->except('show');

        // Sub-branch requests (admin review)
        Route::get('branch-requests', [Admin\AdminBranchController::class, 'index']);
        Route::get('branch-requests/{id}', [Admin\AdminBranchController::class, 'show'])->whereNumber('id');
        Route::put('branch-requests/{id}/approve', [Admin\AdminBranchController::class, 'approve'])->whereNumber('id');
        Route::put('branch-requests/{id}/reject', [Admin\AdminBranchController::class, 'reject'])->whereNumber('id');
    });
});
