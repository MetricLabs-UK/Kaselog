<?php

namespace App\Enums;

enum ClientSource: string
{
    case Phone = 'phone';
    case Web = 'web';
    case Referral = 'referral';
}
