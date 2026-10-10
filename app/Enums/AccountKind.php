<?php

namespace App\Enums;

enum AccountKind: string
{
    case Customer = 'CUSTOMER';
    case Operational = 'OPERATIONAL';
}
