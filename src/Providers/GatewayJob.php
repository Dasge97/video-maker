<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * A job fal accepted, as job.json keeps it in providerJobs: its endpoint, its request_id and the
 * URLs fal returned at submit. Enough to ask for its state, its result or to cancel it.
 */
final class GatewayJob
{
    /**
     * @param array<string, mixed> $meta statusUrl, responseUrl, cancelUrl
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $requestId,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $entry an entry of providerJobs
     */
    public static function fromArray(array $entry): self
    {
        return new self((string) $entry['endpoint'], (string) $entry['requestId'], array_filter([
            'statusUrl' => $entry['statusUrl'] ?? null,
            'responseUrl' => $entry['responseUrl'] ?? null,
            'cancelUrl' => $entry['cancelUrl'] ?? null,
        ]));
    }
}
