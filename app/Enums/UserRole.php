<?php

namespace App\Enums;

enum UserRole: string
{
    case Director = 'director';
    case Admin = 'admin';
    case Solicitor = 'solicitor';
    case Accounts = 'accounts';
}
