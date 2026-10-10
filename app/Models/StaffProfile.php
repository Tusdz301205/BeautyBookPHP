<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id', 'branch_id', 'user_id', 'full_name', 'position', 'bio', 'employee_code',
        'experience_years', 'public_visible', 'is_bookable', 'status', 'hired_at',
    ];

    protected function casts(): array
    {
        return [
            'public_visible' => 'boolean',
            'is_bookable' => 'boolean',
            'hired_at' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopePublicBookable($query)
    {
        return $query->where('status', 'ACTIVE')->where('public_visible', true)->where('is_bookable', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereHas('user', fn ($user) => $user->where('is_active', true)));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(StaffBranchAssignment::class);
    }

    public function offerings(): BelongsToMany
    {
        return $this->belongsToMany(BranchServiceOffering::class, 'staff_services', 'staff_profile_id', 'branch_service_offering_id')
            ->withPivot(['business_id', 'branch_id'])
            ->withTimestamps();
    }
}
