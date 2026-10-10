<?php

namespace App\Enums;

enum BranchStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
