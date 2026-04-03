<?php

declare(strict_types=1);

namespace App\Enums;

enum ClearMode: string
{
    case None = 'none';
    case Truncate = 'truncate';
    case Delete = 'delete';
}
