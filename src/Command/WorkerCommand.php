<?php

declare(strict_types=1);

namespace App\Command;

use App\Job\Job;
use App\Job\JobStore;
use App\Job\Step;
use App\Job\StepFailed;
use App\Providers\ProviderUncertain;
use App\Providers\ProviderUnavailable;
use App\Providers\VideoRejected;
use App\Reference\InputRejected;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The only background process. Every TICK seconds it reads var/jobs/*\/job.json and, for each
 * video in a status with steps, does its steps in order with the lock of the video, until one
 * waits (a clip in fal's queue) or the status is done. The state is always the file: closing
 * the worker and opening it again goes on where it was.
 */
#[AsCommand(name: 'app:worker', description: 'Hace los pasos pendientes de los vídeos')]
final class WorkerCommand extends Command
{
    private const TICK = 2;
    /** A provider busy or unreachable: wait this long and try again, this many times. */
    private const UNAVAILABLE_WAIT = 30;
    private const MAX_UNAVAILABLE = 5;

    /** @var array<string, Step> */
    private array $steps = [];

    /**
     * @param iterable<Step> $steps
     */
    public function __construct(
        private readonly JobStore $store,
        #[AutowireIterator('app.step')]
        iterable $steps,
    ) {
        parent::__construct();
        foreach ($steps as $step) {
            $this->steps[$step->name()] = $step;
        }
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Una sola vuelta y termina');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Worker en marcha. Ctrl+C para pararlo.');
        do {
            foreach ($this->store->all() as $job) {
                if (isset(Job::STEPS[$job->status()])) {
                    $this->store->locked($job->id(), fn (Job $job) => $this->work($job, $output), false);
                }
            }
            if (!$input->getOption('once')) {
                sleep(self::TICK);
            }
        } while (!$input->getOption('once'));

        return Command::SUCCESS;
    }

    /** Steps in order until one waits, fails, or the status has no more steps. */
    public function work(Job $job, ?OutputInterface $output = null): void
    {
        while (isset(Job::STEPS[$job->status()])) {
            $name = (string) $job->step();
            $step = $this->steps[$name] ?? null;
            if (null === $step) {
                $job->fail(\sprintf('El paso «%s» no existe.', $name));
                break;
            }
            if (($job->data['waitUntil'] ?? 0) > time()) {
                break;
            }
            $output?->writeln(\sprintf('[%s] %s: %s', date('H:i:s'), $job->id(), $name));
            try {
                if (!$step->run($job)) {
                    break;
                }
                $job->log('Paso hecho: '.$name);
                unset($job->data['retries'][$name], $job->data['waitUntil']);
                $job->advance();
            } catch (ProviderUnavailable $e) {
                // Nothing reached the provider: safe to try again a little later.
                $tries = ($job->data['retries'][$name] ?? 0) + 1;
                $job->log(\sprintf('Proveedor no disponible en %s (intento %d): %s', $name, $tries, $e->getMessage()));
                if ($tries >= self::MAX_UNAVAILABLE) {
                    $job->fail($e->getMessage());
                    break;
                }
                $job->data['retries'][$name] = $tries;
                $job->data['waitUntil'] = time() + self::UNAVAILABLE_WAIT;
                break;
            } catch (StepFailed|InputRejected|VideoRejected|ProviderUncertain $e) {
                $job->log(\sprintf('Paso fallido: %s: %s', $name, $e->getMessage()));
                $job->fail($e->getMessage());
            } catch (\Throwable $e) {
                $job->log(\sprintf('Error en %s: %s %s (%s:%d)', $name, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
                $job->fail(\sprintf('Error inesperado en el paso «%s»: %s', $name, mb_substr($e->getMessage(), 0, 300)));
            }
        }
    }
}
