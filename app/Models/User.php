<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'avatar',
        'gender',
        'date_of_birth',
        'address',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /* ========== Role Helpers ========== */

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isBusinessOwner(): bool
    {
        return $this->role === 'business_owner';
    }

    /* ========== Relations ========== */

    public function business()
    {
        return $this->hasOne(Business::class, 'owner_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'customer_id');
    }

    public function savedServices()
    {
        return $this->belongsToMany(Service::class, 'saved_services')
                    ->withTimestamps();
    }

    /* ========== Accessors ========== */

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=ec4899&color=fff';
    }
}
