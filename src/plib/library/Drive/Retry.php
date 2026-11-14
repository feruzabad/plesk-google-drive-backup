<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_Retry
{
    private function __construct()
    {
    }

    /**
     * Run a callable with bounded exponential backoff for retryable Drive errors.
     *
     * @template T
     * @param callable(): T $callable
     * @return T
     * @throws Modules_GdriveBackup_Drive_Exception
     */
    public static function run(callable $callable, int $attempts = 4): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callable();
            } catch (Modules_GdriveBackup_Drive_Exception $e) {
                $attempt++;
                if (!$e->isRetryable() || $attempt >= $attempts) {
                    throw $e;
                }
                $delay = min(30, 1 << ($attempt - 1));
                $delay += random_int(0, 1000) / 1000;
                usleep((int)($delay * 1_000_000));
            }
        }
    }
}
