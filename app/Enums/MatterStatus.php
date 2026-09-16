<?php

namespace App\Enums;

enum MatterStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Won = 'won';
    case Lost = 'lost';
}
