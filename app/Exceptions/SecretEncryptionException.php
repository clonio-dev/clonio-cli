<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a secret cannot be encrypted, usually because no encryption key is set.
 */
final class SecretEncryptionException extends RuntimeException {}
