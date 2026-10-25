<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One step of a video. Idempotent: what it made is on disk or in job.json, and running it again
 * reuses it instead of paying twice. A step that fails throws; its message (in Spanish) goes to
 * the screen when it is a StepFailed or an InputRejected.
 */
#[AutoconfigureTag('app.step')]
interface Step
{
    /** The name in Job::STEPS. */
    public function name(): string;

    /**
     * @return bool true when done; false when it waits for something (a clip in fal's queue)
     *              and must be called again later
     */
    public function run(Job $job): bool;
}
