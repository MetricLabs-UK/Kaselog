<?php

namespace App\Enums;

enum InstalmentStatus: string
{
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Waived = 'waived';
}
