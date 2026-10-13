<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'blocked'];

    protected $fillable = [
        'user_id', 'company_name', 'logo', 'description', 'website', 'email', 'phone',
        'industry', 'location', 'city', 'state', 'company_size', 'verification_status', 'profile_data', 'parent_id', 'institution_code', 'branch_status', 'branch_admin_status', 'branch_submitted_at', 'branch_note',
    ];

    protected $casts = ['profile_data' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (Company $c) {
            if (! $c->institution_code) {
                do { $code = (string) random_int(10000, 99999); } while (static::where('institution_code', $code)->exists());
                $c->institution_code = $code;
            }
        });
    }

    public function parent(): BelongsTo { return $this->belongsTo(Company::class, 'parent_id'); }
    public function branches(): HasMany { return $this->hasMany(Company::class, 'parent_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function jobs(): HasMany { return $this->hasMany(Job::class); }
    /** A sub-branch is live only when BOTH its main institution and an admin approved it. */
    public function branchFullyApproved(): bool
    {
        return $this->parent_id && $this->branch_status === 'approved' && $this->branch_admin_status === 'approved';
    }
    public function isApproved(): bool { return $this->verification_status === 'approved'; }
}
