<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;

class FilePolicy
{
    /** Owner, admin, or a company that received an application carrying this file as the resume. */
    public function view(User $user, File $file): bool
    {
        if ($user->isAdmin() || $file->user_id === $user->id) return true;
        if (! $user->isCompany() || ! $user->company) return false;
        return \App\Models\Application::whereHas('job', fn ($j) => $j->where('company_id', $user->company->id))
            ->whereHas('resume', fn ($r) => $r->where('file_id', $file->id))
            ->exists();
    }

    public function delete(User $user, File $file): bool
    {
        return $user->isAdmin() || $file->user_id === $user->id;
    }
}
