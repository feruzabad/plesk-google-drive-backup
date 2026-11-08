<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_Exception extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        private readonly bool $retryable = false,
        private readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
