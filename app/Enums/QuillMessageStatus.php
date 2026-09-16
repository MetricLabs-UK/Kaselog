<?php

namespace App\Enums;

enum QuillMessageStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
