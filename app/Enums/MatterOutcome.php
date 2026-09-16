<?php

namespace App\Enums;

enum MatterOutcome: string
{
    case Pending = 'pending';
    case Acquitted = 'acquitted';
    case Convicted = 'convicted';
    case Dismissed = 'dismissed';
}
