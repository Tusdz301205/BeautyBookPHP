<?php

namespace App\Enums;

enum BranchOperationalStatus: string
{
    case Inactive = 'INACTIVE';
    case ReadyToPublish = 'READY_TO_PUBLISH';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';
    case Archived = 'ARCHIVED';
}
