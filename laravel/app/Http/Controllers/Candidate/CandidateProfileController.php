<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Requests\CandidateProfileRequest;
use App\Services\CandidateService;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CandidateProfileController extends Controller
{
    public function __construct(private CandidateService $svc) {}

    public function show(Request $r)
    {
        return ApiResponse::success($this->svc->getProfile($r->user()));
    }

    public function update(CandidateProfileRequest $r)
    {
        return ApiResponse::success($this->svc->updateProfile($r->user(), $r->validated()), 'Profile updated');
    }

    /** Remove the profile photo: the stored object(s), the files row(s) and the profile reference. */
    public function removePhoto(Request $r, FileUploadService $uploads)
    {
        $user = $r->user();
        foreach ($user->files()->where('category', 'profile_photo')->get() as $f) {
            if (! $uploads->delete($f)) return ApiResponse::error('Could not remove the photo from storage. Please try again.', 502);
        }
        $user->candidateProfile()->updateOrCreate([], ['profile_photo' => null]);   // also clears a reference that had no file row
        return ApiResponse::success($this->svc->getProfile($user->fresh()), 'Photo removed');
    }
}
