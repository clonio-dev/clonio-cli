<?php

declare(strict_types=1);

namespace App\Services\SqlDump;

use App\Data\Cloning\CloningOptionsData;
use App\Data\ConnectionData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Data\SqlDump\DumpResultData;
use App\Enums\DatabaseConnectionType;
use App\Services\SqlDump\Contracts\SqlDumpDialect;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Streams a dialect-correct SQL dump to a `.sql` file in the working directory,
 * then compresses it into a (optionally AES-256 encrypted) ZIP archive.
 *
 * Lifecycle: begin() → writeRows()* → finish().
 */
class SqlDumpService
{
    private ?SqlDumpDialect $dialect = null;

    private string $sqlFileName = '';

    private bool $disableFkChecks = false;

    private ?string $zipPassword = null;

    /** @var list<TableSchemaData> */
    private array $orderedTables = [];

    /** @var array<string, TableSchemaData> */
    private array $tablesByName = [];

    public function __construct(
        private readonly DumpDialectFactory $factory,
        private readonly DumpArchiver $archiver,
    ) {}

    /**
     * Validate the dump target and write the file header, FK preamble, and all DDL.
     *
     * @param  list<string>  $orderedTableNames  Tables in dependency order
     */
    public function begin(
        ConnectionData $dumpConn,
        ConnectionData $source,
        DatabaseSchemaData $sourceSchema,
        array $orderedTableNames,
        CloningOptionsData $options,
        DateTimeImmutable $at,
        bool $emitSchema = true,
    ): void {
        $dialectType = $dumpConn->dialect;

        throw_if(! $dialectType instanceof DatabaseConnectionType || ! $dialectType->isDialect(), RuntimeException::class, 'Dump connection has no valid dialect.');

        $this->dialect = $this->factory->make($dialectType, $this->resolveSqlServerSchema($source));
        $this->disableFkChecks = $options->disableForeignKeyChecks;
        $this->zipPassword = $this->resolveZipPassword($dumpConn);

        $sourceDb = $this->deriveSourceDbName($source);
        $this->sqlFileName = sprintf('%s_%s.sql', $sourceDb, $at->format('Ymd_His'));

        $this->orderedTables = [];
        $this->tablesByName = [];

        foreach ($orderedTableNames as $tableName) {
            $table = $sourceSchema->getTable($tableName);

            if ($table instanceof TableSchemaData) {
                $this->orderedTables[] = $table;
                $this->tablesByName[$tableName] = $table;
            }
        }

        $ifNotExists = ! $options->dropUnknownTables;

        $sql = $this->dialect->fileHeader($sourceDb, $at);
        $sql .= $this->dialect->preamble($this->disableFkChecks);

        if ($emitSchema) {
            foreach ($this->orderedTables as $table) {
                if ($options->dropUnknownTables) {
                    $sql .= $this->dialect->dropTable($table->name);
                }

                $sql .= $this->dialect->createTable($table, $ifNotExists);
                $sql .= "\n";
            }
        }

        $ok = Storage::disk('local')->put($this->sqlFileName, $sql);

        if ($ok === false) {
            throw new RuntimeException(sprintf('Cannot write dump file: %s', $this->sqlFileName));
        }
    }

    /**
     * Append one INSERT batch for the given table.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeRows(string $table, array $rows): void
    {
        if ($rows === [] || ! $this->dialect instanceof SqlDumpDialect) {
            return;
        }

        $schema = $this->tablesByName[$table] ?? null;

        if (! $schema instanceof TableSchemaData) {
            return;
        }

        /** @var list<string> $columnNames */
        $columnNames = array_values(array_filter(array_keys($rows[0]), is_string(...)));

        $sql = $this->dialect->insertBatch($schema, $columnNames, $rows);

        if ($sql !== '') {
            Storage::disk('local')->append($this->sqlFileName, $sql, '');
        }
    }

    /** Append the postamble, compress to ZIP, delete the intermediate `.sql`. */
    public function finish(): DumpResultData
    {
        throw_if(! $this->dialect instanceof SqlDumpDialect, RuntimeException::class, 'SqlDumpService::finish() called before begin().');

        $postamble = $this->dialect->postamble($this->disableFkChecks, $this->orderedTables);

        if ($postamble !== '') {
            Storage::disk('local')->append($this->sqlFileName, $postamble, '');
        }

        return $this->archiver->archive($this->sqlFileName, $this->zipPassword);
    }

    public function sqlFileName(): string
    {
        return $this->sqlFileName;
    }

    private function resolveSqlServerSchema(ConnectionData $source): string
    {
        if ($source->type === DatabaseConnectionType::SqlServer && $source->schema !== null && $source->schema !== '') {
            return $source->schema;
        }

        return 'dbo';
    }

    private function resolveZipPassword(ConnectionData $dumpConn): ?string
    {
        $password = $dumpConn->password;

        if ($password === '') {
            return null;
        }

        if (str_starts_with($password, 'encrypted:')) {
            try {
                return Crypt::decryptString(substr($password, 10));
            } catch (Throwable) {
                throw new RuntimeException('Could not decrypt dump ZIP password — check APP_KEY.');
            }
        }

        return $password;
    }

    private function deriveSourceDbName(ConnectionData $source): string
    {
        $db = $source->database ?? '';

        if ($db === '') {
            return 'dump';
        }

        if ($source->type === DatabaseConnectionType::Sqlite) {
            $base = basename($db);
            $dot = strrpos($base, '.');

            return $dot === false ? $base : substr($base, 0, $dot);
        }

        return $db;
    }
}
