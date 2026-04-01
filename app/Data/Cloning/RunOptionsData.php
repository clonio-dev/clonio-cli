<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class RunOptionsData
{
    /**
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     * @param  list<string>  $auditChannels
     */
    public function __construct(
        public string $yamlPath,
        public string $targetConnectionName,
        public bool $allowFailure,
        public bool $dryRun,
        public bool $skipSchema,
        public array $skipTables,
        public array $onlyTables,
        public array $auditChannels,
    ) {}
}
