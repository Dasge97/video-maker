<?php

declare(strict_types=1);

namespace App\Command;

use App\Prompt\PromptBuilder;
use App\Providers\ElevenLabsMusicGateway;
use App\Providers\ElevenLabsVoiceGateway;
use App\Providers\FalAvatarGateway;
use App\Providers\FalVideoGateway;
use App\Providers\GatewayJob;
use App\Providers\JobState;
use App\Providers\OpenAiImages;
use App\Providers\Submitted;
use App\Providers\VideoCatalog;
use App\Providers\VideoTask;
use App\Render\Ffmpeg;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One paid call of each kind, before putting them together, to check what the documentation of
 * the providers does not say. Results in var/probe/:
 *
 *   voice   ElevenLabs, a sentence of 10 words: the times of each character
 *   image   OpenAI, an edit of the photo: is the face hers?
 *   avatar  Kling AI Avatar with that image and that voice: does it take the audio as data URI,
 *           does the mouth follow Spanish, how long does the video last?
 *   clip    Kling O3 Standard with the person as element and a start image together: does fal
 *           take both?
 *   music   ElevenLabs, 19 s
 */
#[AsCommand(name: 'app:probe', description: 'Prueba un proveedor con una sola llamada de pago')]
final class ProbeCommand extends Command
{
    private const SENTENCE = 'Hola, esta botella quita el sabor a cloro del agua.';

    public function __construct(
        private readonly ElevenLabsVoiceGateway $voices,
        private readonly ElevenLabsMusicGateway $music,
        private readonly OpenAiImages $images,
        private readonly FalVideoGateway $fal,
        private readonly FalAvatarGateway $avatar,
        private readonly VideoCatalog $videos,
        private readonly PromptBuilder $prompts,
        private readonly Ffmpeg $ffmpeg,
        #[Autowire('%kernel.project_dir%/var/probe')]
        private readonly string $dir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'voice, image, avatar, clip o music')
            ->addOption('person', null, InputOption::VALUE_REQUIRED, 'Foto de la persona (para image y clip)')
            ->addOption('voice', null, InputOption::VALUE_REQUIRED, 'Voz de ElevenLabs', 'f9DFWr0Y8aHd6VNMEdTt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        is_dir($this->dir) || mkdir($this->dir, 0775, true);
        $result = match ((string) $input->getArgument('provider')) {
            'voice' => $this->voice((string) $input->getOption('voice')),
            'image' => $this->image((string) $input->getOption('person')),
            'avatar' => $this->avatarClip(),
            'clip' => $this->clip((string) $input->getOption('person')),
            'music' => $this->musicClip(),
            default => ['error' => 'Proveedor desconocido.'],
        };
        file_put_contents($this->dir.'/'.$input->getArgument('provider').'.json', json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return isset($result['error']) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function voice(string $voiceId): array
    {
        $speech = $this->voices->speak($voiceId, self::SENTENCE);
        rename($speech->path, $this->dir.'/voice.mp3');
        $seconds = $this->ffmpeg->duration($this->dir.'/voice.mp3');

        return [
            'file' => 'var/probe/voice.mp3',
            'characters' => \count($speech->characters),
            'textCharacters' => mb_strlen(self::SENTENCE),
            'lastEnd' => $speech->duration(),
            'firstStarts' => \array_slice($speech->starts, 0, 6),
            'audioSeconds' => $seconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $person): array
    {
        $prompt = $this->prompts->picturePrompt(
            ['use' => 'start', 'description' => 'La persona en su cocina luminosa, en primer plano, mirando a cámara, con una botella de agua transparente en la mano.', 'baseAssetId' => 'person', 'baseKind' => 'person'],
            ['spec' => ['action' => 'Habla a cámara con naturalidad.', 'framing' => 'primer plano', 'lighting' => 'luz natural de ventana']],
            ['name' => 'Brisa', 'colors' => '#01A1CB, #0B2A3A'],
        );
        $made = $this->images->edit($prompt, PromptBuilder::pictureSize('9:16'), $person);
        file_put_contents($this->dir.'/start.jpg', $made['bytes']);

        return ['file' => 'var/probe/start.jpg', 'usd' => $made['usd'], 'size' => getimagesize($this->dir.'/start.jpg')[0].'x'.getimagesize($this->dir.'/start.jpg')[1], 'prompt' => $prompt];
    }

    /**
     * @return array<string, mixed>
     */
    private function avatarClip(): array
    {
        $sent = $this->avatar->submit($this->dir.'/start.jpg', $this->dir.'/voice.mp3', 'Habla a cámara con naturalidad, con gestos suaves.');

        return $this->wait($sent['endpoint'], $sent['submitted'], 'avatar.mp4') + ['audioSeconds' => $this->ffmpeg->duration($this->dir.'/voice.mp3')];
    }

    /**
     * @return array<string, mixed>
     */
    private function clip(string $person): array
    {
        $model = $this->videos->get('balanced');
        $references = [
            ['role' => 'first_frame', 'path' => $this->dir.'/start.jpg', 'mime' => 'image/jpeg'],
            ['role' => 'element', 'path' => $person, 'mime' => 'image/jpeg'],
        ];
        $prompt = $this->prompts->clipPrompt(
            ['spec' => ['action' => '@Element1 levanta la botella hacia la cámara y sonríe.', 'framing' => 'primer plano', 'cameraMovement' => 'acercamiento lento', 'lighting' => 'luz natural']],
            [['role' => 'first_frame', 'type' => 'start_frame', 'name' => 'the start image', 'citation' => null], ['role' => 'element', 'name' => 'the person', 'citation' => '@Element1']],
        );
        $task = new VideoTask($model['video']['endpoint'], $prompt, $references, 3, '9:16', $model);

        return $this->wait($task->endpoint, $this->fal->submit($task), 'clip.mp4') + ['prompt' => $prompt];
    }

    /**
     * @return array<string, mixed>
     */
    private function musicClip(): array
    {
        $made = $this->music->compose('Pop acústico ligero. Fresco y optimista. Música instrumental, sin voces, para un vídeo corto de redes sociales, con energía constante hasta el final.', 19);
        rename($made['path'], $this->dir.'/music.mp3');
        $seconds = $this->ffmpeg->duration($this->dir.'/music.mp3');

        return ['file' => 'var/probe/music.mp3', 'seconds' => $seconds];
    }

    /**
     * Asks fal every 10 s until the job finishes, then downloads it.
     *
     * @return array<string, mixed>
     */
    private function wait(string $endpoint, Submitted $submitted, string $name): array
    {
        $job = new GatewayJob($endpoint, $submitted->requestId, $submitted->meta);
        $started = time();
        do {
            sleep(10);
            $state = $this->fal->status($job);
        } while (!$state->isFinished() && time() - $started < 1200);
        if (JobState::COMPLETED !== $state->state) {
            return ['error' => $state->error ?? 'Sin terminar a los 20 minutos.', 'requestId' => $submitted->requestId];
        }
        $out = $this->fal->output($job);
        rename($out->files[0]['path'], $this->dir.'/'.$name);
        $probe = $this->ffmpeg->probe($this->dir.'/'.$name);

        return ['file' => 'var/probe/'.$name, 'requestId' => $submitted->requestId, 'waitedSeconds' => time() - $started, 'videoSeconds' => $probe['duration'], 'size' => $probe['width'].'x'.$probe['height'], 'raw' => $out->raw];
    }
}
