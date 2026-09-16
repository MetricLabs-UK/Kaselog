<?php

namespace App\Enums;

enum ImpersonationStatus: string
{
    case Pending = 'pending';
    case Declined = 'declined';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Active = 'active';
    case Ended = 'ended';
}
