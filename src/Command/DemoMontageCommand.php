<?php

declare(strict_types=1);

namespace App\Command;

use App\Job\Steps\AssembleStep;
use App\Render\BrandStyle;
use App\Render\Ffmpeg;
use App\Sound\VoiceTimeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A montage of 12 s without providers, to check by eye the safe zone, the contrast, the fades
 * and that there are no black bands: two clips made with ffmpeg (one square, one landscape), a
 * text card, a logo card with a test logo, a hook, the small logo and subtitles from made-up
 * times of the voice; a tone as voice and another as music.
 */
#[AsCommand(name: 'app:demo-montage', description: 'Monta un vídeo de prueba de 12 s sin proveedores')]
final class DemoMontageCommand extends Command
{
    /** Made-up speed of the voice: seconds per character. */
    private const SECONDS_PER_CHAR = 0.062;

    public function __construct(
        private readonly AssembleStep $assemble,
        private readonly Ffmpeg $ffmpeg,
        #[Autowire('%kernel.project_dir%/var/demo')]
        private readonly string $dir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        is_dir($this->dir) || mkdir($this->dir, 0775, true);
        $clipA = $this->dir.'/clip-a.mp4';
        $clipB = $this->dir.'/clip-b.mp4';
        $logo = $this->dir.'/logo.png';
        $voice = $this->dir.'/voice.wav';
        $music = $this->dir.'/music.wav';
        $out = $this->dir.'/video.mp4';

        // A square clip and a landscape one: both must cover 9:16 without bands.
        $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=720x720:rate=24:duration=5', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-y', $clipA]);
        $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=24:duration=5', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-y', $clipB]);
        $this->logo($logo);

        $plan = [
            'pieces' => [
                ['kind' => 'generated_clip', 'title' => 'Gancho', 'voice' => '¿Cansado de perder la mañana buscando aparcamiento?', 'plannedSeconds' => 3.5, 'transition' => 'cut'],
                ['kind' => 'text_card', 'title' => 'Mensaje', 'voice' => 'Con OVE aparcas en un minuto.', 'plannedSeconds' => 2.5, 'transition' => 'cut', 'card' => ['text' => 'Aparca en 1 minuto', 'background' => null]],
                ['kind' => 'generated_clip', 'title' => 'Prueba', 'voice' => 'Abres la app, eliges la plaza y listo.', 'plannedSeconds' => 3.0, 'transition' => 'fade'],
                ['kind' => 'logo_card', 'title' => 'Llamada a la acción', 'voice' => 'Descárgala gratis hoy.', 'plannedSeconds' => 3.0, 'transition' => 'cut', 'logo' => ['text' => 'Descárgala hoy', 'background' => null]],
            ],
            'overlays' => [
                ['kind' => 'hook', 'text' => 'Adiós a dar vueltas', 'fromPiece' => 1, 'toPiece' => 1, 'position' => 'top'],
                ['kind' => 'label', 'text' => 'Sin esperas', 'fromPiece' => 3, 'toPiece' => 3, 'position' => 'bottom'],
                ['kind' => 'logo', 'text' => '', 'fromPiece' => 1, 'toPiece' => 4, 'position' => 'top'],
            ],
            'subtitles' => true,
        ];

        $timelinePieces = [];
        foreach ($plan['pieces'] as $i => $piece) {
            $timelinePieces[] = ['key' => 'p'.$i, 'plannedSeconds' => $piece['plannedSeconds'], 'voice' => ['speaker' => 'narrator', 'text' => $piece['voice']]];
        }
        $texts = VoiceTimeline::speakerTexts($timelinePieces);
        $timeline = VoiceTimeline::build($timelinePieces, ['narrator' => $this->madeUpSpeech($texts['narrator']['text'])]);
        $total = (float) $timeline['totalSeconds'];

        $this->tone($voice, $total, 220, 0.25);
        $this->tone($music, $total + 4, 440, 0.2);

        $style = BrandStyle::from(['name' => 'OVE', 'colors' => '#FF6A00, #1D1D1B', 'fonts' => 'Poppins']);
        $result = $this->assemble->assemble($plan, $timeline, [
            'clips' => [0 => $clipA, 2 => $clipB],
            'voice' => $voice,
            'music' => $music,
            'logo' => $logo,
        ], $style, $out);

        $output->writeln(\sprintf('Vídeo: %s (%s s; la voz pedía %s s)', $out, $result['durationSeconds'], $total));
        foreach ($timeline['pieces'] as $piece) {
            $output->writeln(\sprintf('  %s: desde %s s, %s s', $piece['key'], $piece['startSeconds'], $piece['durationSeconds']));
        }
        foreach ($result['warnings'] as $warning) {
            $output->writeln('Aviso: '.$warning);
        }

        return Command::SUCCESS;
    }

    /**
     * Times of every character as ElevenLabs would return them, with a pause after punctuation.
     *
     * @return array{characters: list<string>, starts: list<float>, ends: list<float>, duration: float}
     */
    private function madeUpSpeech(string $text): array
    {
        $characters = mb_str_split($text);
        $starts = [];
        $ends = [];
        $at = 0.0;
        foreach ($characters as $c) {
            $starts[] = round($at, 3);
            $at += self::SECONDS_PER_CHAR;
            $ends[] = round($at, 3);
            if (\in_array($c, ['.', ',', '?', '!'], true)) {
                $at += 0.3;
            }
        }

        return ['characters' => $characters, 'starts' => $starts, 'ends' => $ends, 'duration' => round($at, 3)];
    }

    /** A test logo: an orange circle with a dark ring on a transparent background. */
    private function logo(string $path): void
    {
        $image = imagecreatetruecolor(600, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagefilledellipse($image, 150, 150, 260, 260, imagecolorallocate($image, 0x1D, 0x1D, 0x1B));
        imagefilledellipse($image, 150, 150, 200, 200, imagecolorallocate($image, 0xFF, 0x6A, 0x00));
        imagefilledrectangle($image, 320, 100, 560, 200, imagecolorallocate($image, 0xFF, 0x6A, 0x00));
        imagepng($image, $path);
    }

    private function tone(string $out, float $seconds, int $frequency, float $volume): void
    {
        $this->ffmpeg->exec(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', \sprintf('sine=frequency=%d:sample_rate=44100:duration=%s', $frequency, Ffmpeg::seconds($seconds)),
            '-af', 'volume='.number_format($volume, 3, '.', ''), '-ac', '1', '-c:a', 'pcm_s16le', '-y', $out]);
    }
}
