<?php

declare(strict_types=1);

use App\Logging\CreateAuditBufferLogger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | The `clonio` stack writes warnings/errors to stderr and captures every
    | record (debug and above) into the in-memory AuditBuffer so the cloning
    | run can embed the structured event log in its audit artefact.
    |
    | Override anything below by adding a `logging` section to clonio.json —
    | AppServiceProvider merges it over these defaults at boot.
    |
    */

    'default' => env('LOG_CHANNEL', 'clonio'),

    'channels' => [

        'clonio' => [
            'driver' => 'stack',
            'channels' => ['stderr', 'audit_buffer'],
            'ignore_exceptions' => false,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'warning'),
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => LineFormatter::class,
            'formatter_with' => [
                'format' => "[%datetime%] %level_name%: %message% %context%\n",
                'dateFormat' => 'Y-m-d\TH:i:s.uP',
                'allowInlineLineBreaks' => true,
                'ignoreEmptyContextAndExtra' => true,
            ],
        ],

        'audit_buffer' => [
            'driver' => 'custom',
            'via' => CreateAuditBufferLogger::class,
            'level' => 'debug',
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

    ],

];
