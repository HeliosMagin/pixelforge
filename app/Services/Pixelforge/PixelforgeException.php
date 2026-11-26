<?php

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * An error response (or transport failure) from the Pixelforge API, keyed by the
 * documented `error.code` so callers can branch on it.
 */
class PixelforgeException extends RuntimeException
{
    /** Codes where nothing was created and retrying the same request later can succeed. */
    private const RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'connection_error', 'server_error'];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }

    /** True when we are being throttled for capacity, as opposed to a transient glitch. */
    public function isCapacityLimit(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true);
    }
}
