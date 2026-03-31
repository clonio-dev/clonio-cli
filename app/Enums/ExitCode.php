<?php

declare(strict_types=1);

namespace App\Enums;

enum ExitCode: int
{
    case Success = 0;
    case GeneralError = 1;
    case ConfigError = 2;
    case ConnectionError = 3;
    case ValidationError = 4;
    case IoError = 5;
}
