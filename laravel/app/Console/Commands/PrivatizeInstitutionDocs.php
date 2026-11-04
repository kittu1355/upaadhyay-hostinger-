<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: institution proof documents (category "other") used to be stored on the PUBLIC uploads disk.
 * This moves them to the private disk so they are only reachable through signed links / the admin console.
 *   php artisan files:privatize-other          (dry run: shows what would move)
 *   php artisan files:privatize-other --force  (moves them)
 */
class PrivatizeInstitutionDocs extends Command
{
    protected $signature = 'files:privatize-other {--force}';
    protected $description = 'Move institution documents (category other) from the public disk to the private disk';

    public function handle(): int
    {
        $private = config('uploads.private_disk', 'local');
        $files = File::where('category', 'other')->where(fn ($q) => $q->whereNull('disk')->orWhere('disk', '!=', $private))->get();
        $this->info($files->count().' file(s) on a public disk.');
        $moved = 0;

        foreach ($files as $f) {
            $from = $f->disk ?: 'uploads';
            if (! $this->option('force')) { $this->line("would move #{$f->id} {$f->storage_path}"); continue; }
            try {
                if (! Storage::disk($from)->exists($f->storage_path)) { $this->warn("#{$f->id} missing on $from, skipped"); continue; }
                Storage::disk($private)->put($f->storage_path, Storage::disk($from)->get($f->storage_path));
                Storage::disk($from)->delete($f->storage_path);
                $f->update(['disk' => $private, 'url' => null]);
                $moved++;
            } catch (\Throwable $e) {
                $this->error("#{$f->id} failed: ".$e->getMessage());
            }
        }
        $this->info($this->option('force') ? "Moved $moved file(s)." : 'Dry run only. Re-run with --force to move them.');
        return self::SUCCESS;
    }
}
