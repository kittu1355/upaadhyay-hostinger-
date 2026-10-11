<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;

class FilePolicy
{
    /**
     * Owner, admin, or a company that received an application from the file's owner (resume / certificates only).
     * Candidates who never applied to this company stay private.
     */
    public function view(User $user, File $file): bool
    {
        if ($user->isAdmin() || $file->user_id === $user->id) return true;
        if (! $user->isCompany() || ! $user->company) return false;
        if (! in_array($file->category, ['resume', 'certificate'], true)) return false;
        return \App\Models\Application::where('candidate_id', $file->user_id)
            ->where('status', '!=', 'withdrawn')
            ->whereHas('job', fn ($j) => $j->where('company_id', $user->company->id))
            ->exists();
    }

    public function delete(User $user, File $file): bool
    {
        return $user->isAdmin() || $file->user_id === $user->id;
    }
}
