<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\Step;
use App\Reference\Transcriber;
use App\Reference\VideoTools;

/**
 * What is said in the reference, with AI Hub. Without audio, or when AI Hub fails, the agent
 * works with the frames only and the proposal says so: it never stops the video.
 */
final class TranscribeStep implements Step
{
    public function __construct(
        private readonly Transcriber $transcriber,
        private readonly VideoTools $tools,
    ) {
    }

    public function name(): string
    {
        return 'transcribe';
    }

    public function run(Job $job): bool
    {
        $file = $job->path('reference/transcript.json');
        if (!is_file($file)) {
            $result = ['available' => false, 'reason' => 'El vídeo de referencia no tiene sonido.'];
            if (true === $job->data['reference']['hasAudio']) {
                $audio = $job->path('reference/audio.wav');
                $result = $this->tools->audio($job->path((string) $job->input()['referenceFile']), $audio)
                    ? $this->transcriber->transcribe($audio)
                    : ['available' => false, 'reason' => 'No se ha podido sacar el audio de la referencia.'];
                @unlink($audio);
                $job->log('Transcripción: '.(true === $result['available'] ? \count($result['segments']).' tramos' : $result['reason']));
            }
            file_put_contents($file, json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }
        $result = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $job->data['reference']['transcript'] = true === $result['available'];
        if (true !== $result['available'] && true === $job->data['reference']['hasAudio']) {
            $job->warn('Sin transcripción de la referencia: '.$result['reason'].' El agente ha trabajado solo con los fotogramas.');
        }

        return true;
    }
}
