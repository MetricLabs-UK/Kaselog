<?php

namespace App\Enums;

enum ChaseLogChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
