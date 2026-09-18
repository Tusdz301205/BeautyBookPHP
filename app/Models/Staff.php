<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'user_id',
        'branch_id',
        'name',
        'phone',
        'email',
        'avatar',
        'position',
        'bio',
        'status',
    ];

    /* ========== Relations ========== */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'staff_services');
    }

    public function bookingServices()
    {
        return $this->hasMany(BookingService::class);
    }

    /* ========== Scopes ========== */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /* ========== Accessors ========== */

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=06b6d4&color=fff';
    }
}
