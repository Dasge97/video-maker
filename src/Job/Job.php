<?php

declare(strict_types=1);

namespace App\Job;

/**
 * One video: its folder in var/jobs/<id>/ and the state kept in its job.json. Nothing else holds
 * the state of a video; the worker and the web read it again every time.
 */
final class Job
{
    /** Status → the steps the worker does in it, in order. */
    public const STEPS = [
        'analysing' => ['check', 'frames', 'transcribe', 'plan'],
        'revising' => ['revise'],
        'generating' => ['voice', 'images', 'clips', 'music', 'assemble'],
    ];
    /** Status reached when the steps of a status are done. */
    public const NEXT = ['analysing' => 'proposal', 'revising' => 'proposal', 'generating' => 'ready'];

    /**
     * @param array<string, mixed> $data the contents of job.json
     */
    public function __construct(
        public readonly string $dir,
        public array $data,
    ) {
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function status(): string
    {
        return (string) $this->data['status'];
    }

    public function step(): ?string
    {
        return $this->data['step'] ?? null;
    }

    /** Absolute path of a file of the video. */
    public function path(string $relative): string
    {
        return $this->dir.'/'.$relative;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->data['input'];
    }

    /** Enters a status that has steps, from its first step. */
    public function start(string $status): void
    {
        $this->data['status'] = $status;
        $this->data['step'] = self::STEPS[$status][0] ?? null;
        $this->data['error'] = null;
        $this->data['failedIn'] = null;
    }

    /** The current step is done: the next one, or the status that follows. */
    public function advance(): void
    {
        $steps = self::STEPS[$this->status()] ?? [];
        $at = array_search($this->step(), $steps, true);
        $next = false === $at ? null : ($steps[$at + 1] ?? null);
        if (null !== $next) {
            $this->data['step'] = $next;

            return;
        }
        $this->data['status'] = self::NEXT[$this->status()] ?? $this->status();
        $this->data['step'] = null;
    }

    /** The current step failed: the message, in Spanish, for the screen. */
    public function fail(string $message): void
    {
        $this->data['failedIn'] = $this->status();
        $this->data['status'] = 'failed';
        $this->data['error'] = ['step' => $this->step(), 'message' => $message];
    }

    /** Back to the status and the step that failed. What is on disk is not done again. */
    public function retry(): void
    {
        if ('failed' !== $this->status() || null === ($this->data['failedIn'] ?? null)) {
            return;
        }
        $this->data['status'] = $this->data['failedIn'];
        $this->data['failedIn'] = null;
        $this->data['error'] = null;
        unset($this->data['retries'], $this->data['waitUntil']);
        // The person was told a clip whose send is uncertain may be charged twice: retrying
        // asks for it again.
        $this->data['providerJobs'] = array_filter($this->data['providerJobs'] ?? [], static fn (array $e) => 'uncertain' !== ($e['state'] ?? null));
    }

    /**
     * @return array<string, mixed>|null the plan in force
     */
    public function plan(): ?array
    {
        $file = $this->data['plan']['file'] ?? null;
        if (!\is_string($file) || !is_file($this->path($file))) {
            return null;
        }

        return json_decode((string) file_get_contents($this->path($file)), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Saves a new version of the plan (agent/plan-NNN.json) and makes it the one in force.
     *
     * @param array<string, mixed> $plan
     * @param list<string>         $warnings
     */
    public function savePlan(array $plan, string $by, array $warnings = []): int
    {
        $version = (int) ($this->data['plan']['version'] ?? 0) + 1;
        $file = \sprintf('agent/plan-%03d.json', $version);
        is_dir($this->path('agent')) || mkdir($this->path('agent'), 0775, true);
        file_put_contents($this->path($file), json_encode($plan, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        $this->data['plan'] = ['version' => $version, 'file' => $file, 'by' => $by, 'warnings' => $warnings];

        return $version;
    }

    public function addCost(string $what, float $usd): void
    {
        $this->data['costs'][] = ['at' => date(\DATE_ATOM), 'what' => $what, 'usd' => round($usd, 4)];
    }

    public function totalCost(): float
    {
        return round(array_sum(array_column($this->data['costs'] ?? [], 'usd')), 4);
    }

    public function warn(string $message): void
    {
        $warnings = $this->data['warnings'] ?? [];
        if (!\in_array($message, $warnings, true)) {
            $warnings[] = $message;
        }
        $this->data['warnings'] = $warnings;
    }

    /** One line in log.txt. Never a key: callers log what they asked, not the headers. */
    public function log(string $line): void
    {
        file_put_contents($this->path('log.txt'), date('Y-m-d H:i:s').' '.$line."\n", \FILE_APPEND);
    }
}
