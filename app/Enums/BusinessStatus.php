<?php

namespace App\Enums;

enum BusinessStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case NeedMoreInfo = 'NEED_MORE_INFO';
    case Approved = 'APPROVED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Rejected = 'REJECTED';
}
