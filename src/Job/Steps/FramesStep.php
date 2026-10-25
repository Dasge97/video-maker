<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Reference\Frames;

/**
 * The frames of the reference, one per shot, with reference/frames.json.
 */
final class FramesStep implements Step
{
    public function __construct(private readonly Frames $frames)
    {
    }

    public function name(): string
    {
        return 'frames';
    }

    public function run(Job $job): bool
    {
        $index = $job->path('reference/frames.json');
        if (!is_file($index)) {
            $frames = $this->frames->extract($job->path((string) $job->input()['referenceFile']), (float) $job->data['reference']['durationSeconds'], $job->path('reference'));
            file_put_contents($index, json_encode($frames, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        }
        $job->data['reference']['frames'] = \count(json_decode((string) file_get_contents($index), true, 512, \JSON_THROW_ON_ERROR));

        return true;
    }
}
