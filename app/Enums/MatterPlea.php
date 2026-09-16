<?php

namespace App\Enums;

enum MatterPlea: string
{
    case Guilty = 'guilty';
    case NotGuilty = 'not_guilty';
    case ToBeAdvised = 'to_be_advised';
}
