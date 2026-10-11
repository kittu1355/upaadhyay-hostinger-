<?php

namespace App\Services;

use App\Models\CandidateProfile;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\File;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reusable upload/delete over configured disks (Backblaze B2 in production, see config/filesystems.php 'b2').
 * Disk is recorded per file row (files.disk), so legacy local files keep working after switching to B2.
 * Public categories (photos, logos, job images) -> served via GET /api/files/{id}/view (redirect to a short presigned URL).
 * Private categories (resume, certificate)       -> served via signed GET /api/files/{id}/download (streamed by backend).
 * The bucket itself is never public. MySQL stores metadata only.
 */
class FileUploadService
{
    /** Disk used for NEW uploads in this category. */
    public function disk(string $category = 'other'): string
    {
        return $this->isPrivate($category)
            ? config('uploads.private_disk', 'local')
            : config('uploads.disk', 'uploads');
    }

    /** Disk that actually holds an EXISTING file. NULL disk = legacy row from before B2. */
    public function diskFor(File $file): string
    {
        if ($file->disk) return $file->disk;
        return $this->isPrivate($file->category) ? 'local' : 'uploads';
    }

    public function isPrivate(string $category): bool
    {
        return in_array($category, config('uploads.private_categories', ['resume', 'certificate']), true);
    }

    /** Public category stored on a non-local disk -> must be served through the backend. */
    private function servedByBackend(string $disk): bool
    {
        return config("filesystems.disks.$disk.driver") === 's3';
    }

    public function upload(UploadedFile $file, User $user, string $category = 'other'): File
    {
        $disk = $this->disk($category);
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $name = Str::uuid().'.'.$ext;                       // unpredictable key; user-supplied name never used in the path
        $dir = "{$category}/{$user->id}";
        $path = "$dir/$name";

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $size = $file->getSize();
        $original = Str::limit($file->getClientOriginalName(), 190, '');

        try {
            $ok = Storage::disk($disk)->putFileAs($dir, $file, $name);
        } catch (Throwable $e) {
            Log::error('File upload to storage failed', ['disk' => $disk, 'category' => $category, 'error' => $e->getMessage()]);
            abort(502, 'File storage is temporarily unavailable. Please try again.');
        }
        abort_if($ok === false, 502, 'File storage is temporarily unavailable. Please try again.');

        try {
            $row = File::create([
                'user_id' => $user->id,
                'file_name' => $name,
                'original_name' => $original,
                'file_type' => $mime,
                'file_size' => $size,
                'storage_path' => $path,
                'disk' => $disk,
                'url' => null,
                'category' => $category,
            ]);
            if (! $this->isPrivate($category)) {
                $row->update(['url' => $this->servedByBackend($disk) ? url("/api/files/{$row->id}/view") : $this->publicUrl($path, $disk)]);
            }
            return $row;
        } catch (Throwable $e) {
            // DB failed after the object was written: remove the object so nothing is orphaned.
            $this->removeObject($disk, $path);
            Log::error('File metadata save failed; object removed', ['path' => $path, 'error' => $e->getMessage()]);
            abort(500, 'Could not save the file. Please try again.');
        }
    }

    /**
     * Delete object, then the DB row. Returns false (row kept, so it can be retried) if the cloud delete failed.
     * A missing object counts as success.
     */
    public function delete(File $file): bool
    {
        // Capture what depends on this file BEFORE the row goes (FKs null/cascade them away).
        $ref = $file->url ?? $file->storage_path;
        $userId = $file->user_id;
        $category = $file->category;
        $certIds = $category === 'certificate' ? Certificate::where('file_id', $file->id)->pluck('id')->all() : [];
        $wasPrimaryResume = $category === 'resume' && Resume::where('file_id', $file->id)->where('is_primary', true)->exists();

        if (! $this->removeObject($this->diskFor($file), $file->storage_path)) return false;
        $file->delete();

        // Keep the account data consistent with what is actually stored.
        if ($certIds) Certificate::whereIn('id', $certIds)->delete();                       // no certificate row without its file
        if ($wasPrimaryResume) Resume::where('user_id', $userId)->latest('id')->first()?->update(['is_primary' => true]);
        if ($category === 'profile_photo') CandidateProfile::where('user_id', $userId)->where('profile_photo', $ref)->update(['profile_photo' => null]);
        if ($category === 'company_logo') Company::where('user_id', $userId)->where('logo', $ref)->update(['logo' => null]);
        return true;
    }

    private function removeObject(string $disk, string $path): bool
    {
        try {
            Storage::disk($disk)->delete($path);
            return true;
        } catch (Throwable $e) {
            Log::warning('Storage delete failed', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Short-lived signed backend link for private files (resumes/certificates). */
    public function temporaryUrl(File $file, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('files.download', now()->addMinutes($minutes), ['id' => $file->id]);
    }

    /** Streams a private file through the backend with a safe attachment disposition. */
    public function download(File $file)
    {
        $disk = Storage::disk($this->diskFor($file));
        try {
            abort_unless($disk->exists($file->storage_path), 404, 'File not found');
            return $disk->download($file->storage_path, $file->original_name, [
                'Content-Type' => $file->file_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Storage read failed', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            abort(502, 'File storage is temporarily unavailable. Please try again.');
        }
    }

    /** Public images: where the browser should be sent. B2 -> 15-min presigned URL; local -> stored public URL. */
    public function publicRedirectUrl(File $file): ?string
    {
        $disk = $this->diskFor($file);
        try {
            if ($this->servedByBackend($disk)) {
                return Storage::disk($disk)->temporaryUrl($file->storage_path, now()->addMinutes(15), [
                    'ResponseContentType' => $file->file_type,
                    'ResponseContentDisposition' => 'inline',
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Presign failed', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            abort(502, 'File storage is temporarily unavailable.');
        }
        return $file->url;
    }

    private function publicUrl(string $path, string $disk): ?string
    {
        $base = config('filesystems.disks.'.$disk.'.url');
        return $base ? rtrim($base, '/').'/'.$path : null;
    }

    /** Validation rules for a single file in the given category (mime + size from config/uploads.php). */
    public static function fileRules(string $category): array
    {
        $cfg = config("uploads.categories.$category", config('uploads.categories.other'));
        return ['required', 'file', 'mimes:'.implode(',', $cfg['mimes']), 'max:'.$cfg['max_kb']];
    }
}
