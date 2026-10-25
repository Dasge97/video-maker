<?php

declare(strict_types=1);

namespace App\Providers;

final class Submitted
{
    /**
     * @param array<string, mixed> $meta URLs needed later (status, response, cancel)
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $meta = [],
    ) {
    }
}
