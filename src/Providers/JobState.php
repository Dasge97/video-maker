<?php

declare(strict_types=1);

namespace App\Providers;

final class JobState
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly string $providerStatus,
        public readonly ?string $error = null,
    ) {
    }

    public function isFinished(): bool
    {
        return self::COMPLETED === $this->state || self::FAILED === $this->state;
    }
}
