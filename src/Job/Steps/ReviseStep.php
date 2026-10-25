<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Agent\PlanAgent;
use App\Job\Estimate;
use App\Job\Job;
use App\Job\Step;
use App\Job\StepFailed;

/**
 * The change the person asked from the proposal: a new conversation with the same data, the
 * plan in force and the request. The result is a new version of the plan.
 */
final class ReviseStep implements Step
{
    public function __construct(
        private readonly PlanAgent $agent,
        private readonly Estimate $estimate,
    ) {
    }

    public function name(): string
    {
        return 'revise';
    }

    public function run(Job $job): bool
    {
        $change = trim((string) ($job->data['revision']['request'] ?? ''));
        $current = $job->plan();
        if ('' === $change || null === $current) {
            throw new StepFailed('No hay ningún cambio pedido.');
        }
        // Done already (the worker stopped after saving it): not asked twice.
        if (($job->data['revision']['doneInVersion'] ?? null) !== $job->data['plan']['version']) {
            $result = $this->agent->plan($job, $current, $change);
            $job->savePlan($result['plan'], 'agent');
            $job->data['revision']['doneInVersion'] = $job->data['plan']['version'];
        }
        $job->data['estimate'] = $this->estimate->of((array) $job->plan(), $job->input()['model'] ?? null);

        return true;
    }
}
