<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class KeyRemappingExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $columnType,
        public readonly bool $unsigned,
        public readonly int $typeMax,
        public readonly int $rowCount,
        public readonly int $upperSlots,
        public readonly int $gapSlots,
    ) {
        parent::__construct(sprintf(
            "Cannot remap table '%s': column '%s' is %s (%s, ceiling %d); %d rows requested, only %d slots available (%d above MAX(id), %d gaps below).",
            $table,
            $column,
            strtoupper($columnType),
            $unsigned ? 'unsigned' : 'signed',
            $typeMax,
            $rowCount,
            $upperSlots + $gapSlots,
            $upperSlots,
            $gapSlots,
        ));
    }
}
