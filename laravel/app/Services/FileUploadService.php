<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Reusable upload/delete over configured disks.
 * Public files (photos, logos, job images) -> UPLOAD_DISK ('uploads' folder by default).
 * Private files (resumes, certificates)     -> UPLOAD_PRIVATE_DISK ('local', outside the web root).
 * Switching provider = change the env + disk config; callers don't change.
 * MySQL stores metadata only.
 */
class FileUploadService
{
    public function disk(string $category = 'other'): string
    {
        return $this->isPrivate($category)
            ? config('uploads.private_disk', 'local')
            : config('uploads.disk', 'uploads');
    }

    public function isPrivate(string $category): bool
    {
        return in_array($category, config('uploads.private_categories', ['resume', 'certificate']), true);
    }

    public function upload(UploadedFile $file, User $user, string $category = 'other'): File
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $name = Str::uuid().'.'.$ext;
        $path = "{$category}/{$user->id}/{$name}";

        Storage::disk($this->disk($category))->putFileAs("{$category}/{$user->id}", $file, $name);

        return File::create([
            'user_id' => $user->id,
            'file_name' => $name,
            'original_name' => Str::limit($file->getClientOriginalName(), 190, ''),
            'file_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'storage_path' => $path,
            'url' => $this->isPrivate($category) ? null : $this->publicUrl($path, $this->disk($category)),
            'category' => $category,
        ]);
    }

    public function delete(File $file): void
    {
        Storage::disk($this->disk($file->category))->delete($file->storage_path);
        $file->delete();
    }

    /** Short-lived signed link for private files (resumes/certificates). Works on any disk, incl. local. */
    public function temporaryUrl(File $file, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('files.download', now()->addMinutes($minutes), ['id' => $file->id]);
    }

    public function download(File $file)
    {
        $disk = Storage::disk($this->disk($file->category));
        abort_unless($disk->exists($file->storage_path), 404, 'File not found');
        return $disk->download($file->storage_path, $file->original_name, ['Content-Type' => $file->file_type]);
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
