<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class VerifactuException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message');

        if (! is_string($message)) {
            $message = "The Verifactu service answered {$response->status()}.";
        }

        /** @var ?string $code */
        $code = $response->json('code');

        /** @var array<string, list<string>> $errors */
        $errors = $response->json('errors', []);

        $retryAfter = null;

        $header = $response->header('Retry-After');

        // At least a second, as the service's own examples wait.
        if (is_numeric($header)) {
            $retryAfter = max(1, (int) $header);
        }

        return new self($message, $response->status(), $code, $errors, $retryAfter);
    }

    public static function fromConnection(ConnectionException $exception): self
    {
        return new self('The Verifactu service did not answer.', previous: $exception);
    }

    /**
     * Whether the same request may be sent again, with the same Idempotency-Key.
     */
    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        if ($this->status === 429 || $this->status >= 500) {
            return true;
        }

        return $this->status === 409 && $this->errorCode === 'idempotency_key_in_progress';
    }

    public function isNotRetryable(): bool
    {
        return ! $this->isRetryable();
    }
}
