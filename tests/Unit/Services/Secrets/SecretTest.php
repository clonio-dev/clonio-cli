<?php

declare(strict_types=1);

use App\Exceptions\SecretEncryptionException;
use App\Services\Secrets\Secret;
use Illuminate\Support\Facades\Crypt;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    putenv('CLONIO_SECRET_TEST=from-env');
    putenv('CLONIO_SECRET_EMPTY=');
    putenv('CLONIO_SECRET_UNSET');
});

afterEach(function (): void {
    putenv('CLONIO_SECRET_TEST');
    putenv('CLONIO_SECRET_EMPTY');
});

describe('resolve', function (): void {
    it('returns a plain value unchanged', function (): void {
        expect(Secret::resolve('plain'))->toBe('plain')
            ->and(Secret::resolve(''))->toBe('');
    });

    it('decrypts an encrypted value', function (): void {
        expect(Secret::resolve('encrypted:'.Crypt::encryptString('shh')))->toBe('shh');
    });

    it('fails with the label when the value cannot be decrypted', function (): void {
        expect(fn (): string => Secret::resolve('encrypted:garbage', 'SMTP password'))
            ->toThrow(RuntimeException::class, 'Could not decrypt SMTP password — check CLONIO_KEY (or APP_KEY).');
    });

    it('uses a generic label by default', function (): void {
        expect(fn (): string => Secret::resolve('encrypted:garbage'))
            ->toThrow(RuntimeException::class, 'Could not decrypt secret — check CLONIO_KEY (or APP_KEY).');
    });

    it('reads an env reference from the environment', function (): void {
        expect(Secret::resolve('env:CLONIO_SECRET_TEST'))->toBe('from-env');
    });

    it('keeps an empty environment variable as an empty secret', function (): void {
        expect(Secret::resolve('env:CLONIO_SECRET_EMPTY'))->toBe('');
    });

    it('does not cast environment values', function (): void {
        putenv('CLONIO_SECRET_TEST=true');

        expect(Secret::resolve('env:CLONIO_SECRET_TEST'))->toBe('true');
    });

    it('fails when the referenced variable is not set', function (): void {
        expect(fn (): string => Secret::resolve('env:CLONIO_SECRET_UNSET', 'password'))
            ->toThrow(RuntimeException::class, 'Could not read password — environment variable CLONIO_SECRET_UNSET is not set.');
    });

    it('fails when the reference is not a valid variable name', function (string $reference): void {
        expect(fn (): string => Secret::resolve($reference, 'password'))
            ->toThrow(RuntimeException::class, sprintf('Could not read password — "%s" is not a valid environment variable reference.', $reference));
    })->with(['env:', 'env:1BAD', 'env:BAD-NAME', 'env:BAD NAME', "env:BAD\n"]);
});

describe('protect', function (): void {
    it('keeps a valid env reference as is', function (string $reference): void {
        expect(Secret::protect($reference))->toBe($reference);
    })->with(['env:A', 'env:_A', 'env:CLONIO_PROD_PASSWORD', 'env:lower_case9']);

    it('rejects an invalid env reference', function (): void {
        expect(fn (): string => Secret::protect('env:1BAD'))
            ->toThrow(InvalidArgumentException::class, '"env:1BAD" is not a valid environment variable reference.');
    });

    it('encrypts anything else', function (): void {
        $stored = Secret::protect('shh');

        expect($stored)->toStartWith('encrypted:')
            ->and(Crypt::decryptString(substr($stored, strlen('encrypted:'))))->toBe('shh');
    });

    it('wraps an encryption failure', function (): void {
        Crypt::shouldReceive('encryptString')->andThrow(new RuntimeException('no key'));

        try {
            Secret::protect('shh');
            $this->fail('Expected an exception.');
        } catch (SecretEncryptionException $secretEncryptionException) {
            expect($secretEncryptionException->getMessage())->toBe('Failed to encrypt the secret. Ensure CLONIO_KEY (or APP_KEY) is set in your environment.')
                ->and($secretEncryptionException->getPrevious()?->getMessage())->toBe('no key');
        }
    });
});

describe('isEncrypted', function (): void {
    it('detects the encrypted prefix', function (): void {
        expect(Secret::isEncrypted('encrypted:x'))->toBeTrue()
            ->and(Secret::isEncrypted('env:X'))->toBeFalse()
            ->and(Secret::isEncrypted('plain'))->toBeFalse()
            ->and(Secret::isEncrypted('xencrypted:'))->toBeFalse();
    });
});
