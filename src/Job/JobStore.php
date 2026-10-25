<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Component\Uid\Ulid;

/**
 * The folders of the videos in var/jobs. job.json is written atomically (a temporary file
 * renamed over it), so a reader never sees half a file; job.lock (flock) keeps two processes
 * from working on the same video at once.
 */
final class JobStore
{
    public function __construct(private readonly string $jobsDir)
    {
    }

    /**
     * A new folder with its job.json.
     *
     * @param array<string, mixed> $input
     */
    public function create(array $input): Job
    {
        $id = strtolower((string) new Ulid());
        $dir = $this->jobsDir.'/'.$id;
        foreach (['', '/input', '/reference', '/agent', '/audio', '/images', '/clips', '/out'] as $sub) {
            mkdir($dir.$sub, 0775, true);
        }
        $job = new Job($dir, [
            'id' => $id,
            'createdAt' => date(\DATE_ATOM),
            'status' => 'analysing',
            'step' => Job::STEPS['analysing'][0],
            'error' => null,
            'failedIn' => null,
            'input' => $input,
            'reference' => null,
            'plan' => null,
            'estimate' => null,
            'providerJobs' => [],
            'outputs' => [],
            'costs' => [],
            'warnings' => [],
            'result' => null,
        ]);
        $this->save($job);

        return $job;
    }

    public function get(string $id): ?Job
    {
        if (1 !== preg_match('/^[0-9a-z]{26}$/', $id)) {
            return null;
        }
        $file = $this->jobsDir.'/'.$id.'/job.json';
        if (!is_file($file)) {
            return null;
        }

        return new Job(\dirname($file), json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * Every video, the newest first.
     *
     * @return list<Job>
     */
    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->jobsDir.'/*/job.json') ?: [] as $file) {
            $job = $this->get(basename(\dirname($file)));
            if (null !== $job) {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (Job $a, Job $b) => strcmp($b->id(), $a->id()));

        return $jobs;
    }

    public function save(Job $job): void
    {
        $file = $job->path('job.json');
        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, json_encode($job->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        // On Windows rename() over an open file can fail for a moment: a few tries.
        for ($try = 0; !@rename($temp, $file); ++$try) {
            if ($try >= 20) {
                @unlink($temp);
                throw new \RuntimeException('No se ha podido guardar job.json.');
            }
            usleep(50_000);
        }
    }

    /**
     * Runs $work with the lock of the video, on a fresh copy of its job.json, and saves it after.
     * Returns false without running it when another process holds the lock and $wait is false.
     *
     * @param callable(Job): void $work
     */
    public function locked(string $id, callable $work, bool $wait = true): bool
    {
        $dir = $this->jobsDir.'/'.$id;
        if (!is_dir($dir)) {
            return false;
        }
        $handle = fopen($dir.'/job.lock', 'c');
        if (false === $handle) {
            return false;
        }
        try {
            if (!flock($handle, $wait ? \LOCK_EX : \LOCK_EX | \LOCK_NB)) {
                return false;
            }
            $job = $this->get($id);
            if (null === $job) {
                return false;
            }
            try {
                $work($job);
            } finally {
                $this->save($job);
            }

            return true;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function delete(string $id): bool
    {
        $job = $this->get($id);
        if (null === $job || \in_array($job->status(), array_keys(Job::STEPS), true)) {
            return false;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($job->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($job->dir);

        return true;
    }
}
