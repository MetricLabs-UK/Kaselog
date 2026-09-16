<?php

namespace App\Enums;

enum PortalStatus: string
{
    case NotInvited = 'not_invited';
    case Pending = 'pending';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::NotInvited => 'Not yet invited',
            self::Pending => 'Invited (pending)',
            self::Active => 'Active portal account',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotInvited => 'gray',
            self::Pending => 'warning',
            self::Active => 'success',
        };
    }
}
