<?php

namespace App\Enums;

enum RoleName: string
{
    case Customer = 'CUSTOMER';
    case BusinessOwner = 'BUSINESS_OWNER';
    case Receptionist = 'RECEPTIONIST';
    case Staff = 'STAFF';
    case PlatformAdmin = 'PLATFORM_ADMIN';

    public function requiresOperationalAccount(): bool
    {
        return $this !== self::Customer;
    }
}
