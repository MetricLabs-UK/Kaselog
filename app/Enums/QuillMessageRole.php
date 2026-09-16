<?php

namespace App\Enums;

enum QuillMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
