<?php

namespace App\Enums;

enum DocumentAiSummaryStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
