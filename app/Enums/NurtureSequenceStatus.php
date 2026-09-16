<?php

namespace App\Enums;

enum NurtureSequenceStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
