<?php

namespace App\Enums;

enum BranchReviewStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case NeedMoreInfo = 'NEED_MORE_INFO';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
