<?php

namespace App\Enums;

enum ChaseLogStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
