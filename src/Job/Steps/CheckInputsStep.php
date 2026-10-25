<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Reference\Images;
use App\Reference\InputRejected;
use App\Reference\VideoTools;
use App\Render\LogoColors;
use Symfony\Component\Process\Process;

/**
 * The files the person gave: the reference (downloaded first when it came as a link), checked
 * with ffprobe; the photo as person.jpg and the logo as logo.png. Without colours of the brand,
 * the two main colours of the logo.
 */
final class CheckInputsStep implements Step
{
    public function __construct(private readonly VideoTools $tools)
    {
    }

    public function name(): string
    {
        return 'check';
    }

    public function run(Job $job): bool
    {
        $input = $job->input();
        $reference = $input['referenceFile'] ?? null;
        if (null === $reference && \is_string($input['referenceUrl'] ?? null)) {
            $reference = $this->download($job, $input['referenceUrl']);
            $job->data['input']['referenceFile'] = $reference;
        }
        if (!\is_string($reference) || !is_file($job->path($reference))) {
            throw new InputRejected('Falta el vídeo de referencia.');
        }
        $probe = $this->tools->probe($job->path($reference));
        $job->data['reference'] = ['durationSeconds' => round($probe['duration'], 2), 'hasAudio' => $probe['hasAudio'], 'frames' => 0, 'transcript' => false];

        Images::person($job->path((string) $input['personFile']), $job->path('input/person.jpg'));
        $job->data['input']['hasLogo'] = false;
        if (\is_string($input['logoFile'] ?? null)) {
            Images::logo($job->path($input['logoFile']), $job->path('input/logo.png'));
            $job->data['input']['hasLogo'] = true;
            if ('' === trim((string) ($input['brand']['colors'] ?? ''))) {
                $job->data['input']['brand']['colors'] = implode(', ', LogoColors::main($job->path('input/logo.png')));
            }
        }

        return true;
    }

    /**
     * The video of a public link with yt-dlp. Instagram sometimes asks to log in: then the
     * person downloads it and uploads the file.
     */
    private function download(Job $job, string $url): string
    {
        if (1 !== preg_match('#^https://[^\s]+$#', $url)) {
            throw new InputRejected('El enlace del vídeo de referencia debe empezar por https://.');
        }
        $relative = 'input/reference.mp4';
        $job->log('Descargando la referencia: '.$url);
        $process = new Process([
            'yt-dlp', '--no-playlist', '--no-progress', '--max-filesize', (string) $this->tools->maxBytes(),
            '-f', 'bv*[height<=1920]+ba/b', '--merge-output-format', 'mp4', '--force-overwrites', '-o', $job->path($relative), '--', $url,
        ]);
        $process->setTimeout(600);
        $process->run();
        if (!$process->isSuccessful() || !is_file($job->path($relative))) {
            $job->log('yt-dlp: '.mb_substr(trim($process->getErrorOutput()), -500));
            throw new InputRejected('No se ha podido descargar el vídeo del enlace. Descárgalo y súbelo como fichero.');
        }

        return $relative;
    }
}
