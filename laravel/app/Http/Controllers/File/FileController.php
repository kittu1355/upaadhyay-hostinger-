<?php

namespace App\Http\Controllers\File;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileUploadRequest;
use App\Models\File;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FileController extends Controller
{
    /** Which categories each role may upload here. Resumes/certificates have their own endpoints. */
    private const ALLOWED = [
        'candidate' => ['profile_photo', 'other'],
        'company' => ['company_logo', 'job_image', 'video', 'other'],
        'admin' => ['profile_photo', 'company_logo', 'job_image', 'video', 'other'],
    ];

    public function __construct(private FileUploadService $uploads) {}

    public function upload(FileUploadRequest $r)
    {
        $user = $r->user();
        $category = $r->input('category');

        if (in_array($category, ['resume', 'certificate'], true)) {
            return ApiResponse::error('Use /api/candidate/resume or /api/candidate/certificates for this file type', 422);
        }
        if (! in_array($category, self::ALLOWED[$user->role], true)) {
            return ApiResponse::error("You cannot upload '$category' files", 403);
        }

        $old = $user->files()->where('category', $category)->get(); // one photo/logo at a time
        // Any other slot (job/branch image, documents): the caller names the file this one replaces. Own files, same category only.
        $replaced = $r->filled('replaces')
            ? $user->files()->where('category', $category)->whereKey((int) $r->input('replaces'))->first()
            : null;
        $file = $this->uploads->upload($r->file('file'), $user, $category);
        $ref = $file->url ?? $file->storage_path;

        try {
            if ($category === 'profile_photo') $user->candidateProfile()->updateOrCreate([], ['profile_photo' => $ref]);
            if ($category === 'company_logo' && $user->isCompany()) $user->company()->update(['logo' => $ref]);
        } catch (\Throwable $e) {
            $this->uploads->delete($file);   // reference update failed: keep the old file, drop the new one
            throw $e;
        }
        // Replacement succeeded -> now (and only now) remove the previous objects. A failed delete keeps its row and is retried next upload.
        if (in_array($category, ['profile_photo', 'company_logo'], true)) $old->each(fn ($f) => $this->uploads->delete($f));
        elseif ($replaced && $replaced->id !== $file->id) $this->uploads->delete($replaced);

        return ApiResponse::created($file, 'File uploaded');
    }

    /** Authenticated: returns a 10-minute signed URL the browser can open directly (a normal <a href> works). */
    public function link(Request $r, int $id)
    {
        $file = File::findOrFail($id);
        Gate::forUser($r->user())->authorize('view', $file);
        return ApiResponse::success([
            'url' => $file->url ?: $this->uploads->temporaryUrl($file),
            'expires_in' => $file->url ? null : 600,
        ]);
    }

    /** Public images only (profile photos, logos, job images, videos): redirects to a short-lived storage URL. Private categories -> 404. */
    public function view(int $id)
    {
        $file = File::findOrFail($id);
        abort_if($this->uploads->isPrivate($file->category), 404);
        $to = $this->uploads->publicRedirectUrl($file);
        abort_unless($to, 404);
        return redirect()->away($to, 302, ['Cache-Control' => 'public, max-age=300']);
    }

    /** Signed (no bearer token needed): streams the stored file. */
    public function download(int $id)
    {
        return $this->uploads->download(File::findOrFail($id));
    }

    public function destroy(Request $r, int $id)
    {
        $file = File::findOrFail($id);
        Gate::forUser($r->user())->authorize('delete', $file);

        $ref = $file->url ?? $file->storage_path;
        if (! $this->uploads->delete($file)) return ApiResponse::error('Could not delete the file from storage. Please try again.', 502);
        \App\Models\CandidateProfile::where('user_id', $file->user_id)->where('profile_photo', $ref)->update(['profile_photo' => null]);
        \App\Models\Company::where('user_id', $file->user_id)->where('logo', $ref)->update(['logo' => null]);

        return ApiResponse::success(null, 'File deleted');
    }
}
