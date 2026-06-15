<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Dialects;

/**
 * MariaDB shares MySQL's dump syntax; only the header label differs.
 */
class MariaDbDumpDialect extends MySqlDumpDialect
{
    protected function dialectDisplayName(): string
    {
        return 'MariaDB';
    }
}
