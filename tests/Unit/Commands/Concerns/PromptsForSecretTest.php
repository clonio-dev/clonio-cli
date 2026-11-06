<?php

declare(strict_types=1);

use App\Commands\Concerns\PromptsForSecret;
use Illuminate\Console\Command;

function promptsForSecretSubject(): object
{
    return new class extends Command
    {
        use PromptsForSecret;

        public function envName(string ...$parts): string
        {
            return $this->secretEnvName(...$parts);
        }
    };
}

it('builds an environment variable name from the parts', function (array $parts, string $expected): void {
    expect(promptsForSecretSubject()->envName(...$parts))->toBe($expected);
})->with([
    'simple' => [['prod', 'password'], 'CLONIO_PROD_PASSWORD'],
    'separators' => [['my-db.eu', 'zip_password'], 'CLONIO_MY_DB_EU_ZIP_PASSWORD'],
    'edge separators' => [['-prod-', 'password'], 'CLONIO_PROD_PASSWORD'],
    'empty part' => [['audit', '---', 'token'], 'CLONIO_AUDIT_TOKEN'],
]);
