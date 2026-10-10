<?php

// Upload rules per file category (size in KB). Used by FileUploadService + FileUploadRequest.
return [
    // Public images/videos (photos, logos, job images). Default 'uploads' = plain folder on shared hosting, no symlink needed.
    'disk' => env('UPLOAD_DISK', 'uploads'),
    // Resumes + certificates: private disk, never directly web-accessible; served through signed links (GET /api/files/{id}/link).
    'private_disk' => env('UPLOAD_PRIVATE_DISK', 'local'),
    'private_categories' => ['resume', 'certificate'],

    'categories' => [
        'profile_photo' => ['mimes' => ['jpg', 'jpeg', 'png', 'webp'], 'max_kb' => 2048],
        'company_logo'  => ['mimes' => ['jpg', 'jpeg', 'png', 'webp'], 'max_kb' => 2048],
        'job_image'     => ['mimes' => ['jpg', 'jpeg', 'png', 'webp'], 'max_kb' => 4096],
        'resume'        => ['mimes' => ['pdf', 'doc', 'docx'], 'max_kb' => 5120],
        'certificate'   => ['mimes' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_kb' => 5120],
        'video'         => ['mimes' => ['mp4', 'webm'], 'max_kb' => 51200],
        'other'         => ['mimes' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_kb' => 5120],
    ],
];
