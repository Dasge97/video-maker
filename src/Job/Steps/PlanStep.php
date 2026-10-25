<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Agent\PlanAgent;
use App\Job\Estimate;
use App\Job\Job;
use App\Job\Step;

/**
 * The agent makes the first plan; the video goes to «proposal» with its estimated cost. A plan
 * already made (a retry after a later failure) is not asked again.
 */
final class PlanStep implements Step
{
    public function __construct(
        private readonly PlanAgent $agent,
        private readonly Estimate $estimate,
    ) {
    }

    public function name(): string
    {
        return 'plan';
    }

    public function run(Job $job): bool
    {
        if (null === $job->plan()) {
            $result = $this->agent->plan($job);
            $job->savePlan($result['plan'], 'agent');
            $job->log(\sprintf('Plan del agente en %d vueltas', $result['rounds']));
        }
        $job->data['estimate'] = $this->estimate->of((array) $job->plan(), $job->input()['model'] ?? null);

        return true;
    }
}
