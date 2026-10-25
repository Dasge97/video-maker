<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Job\Steps\AssembleStep;
use App\Render\AssBuilder;
use App\Render\BrandStyle;
use App\Render\FfmpegRenderer;
use App\Render\LogoColors;
use App\Render\VideoFonts;
use App\Sound\VoiceTimeline;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the montage: the continuous voice and its cuts, the subtitles word by word,
 * the colours that must stand out, the safe zone, the audio mix and the windows of the logo.
 */
final class MontageTest extends TestCase
{
    public function testTheVoiceIsOneTrackOverThePiecesAndThePiecesAdjustToIt(): void
    {
        $pieces = [
            ['key' => 'a', 'plannedSeconds' => 3.0, 'voice' => ['speaker' => 'marta', 'text' => 'Hola, soy Marta y']],
            ['key' => 'b', 'plannedSeconds' => 3.0, 'voice' => ['speaker' => 'marta', 'text' => 'hoy te traigo']],
            ['key' => 'c', 'plannedSeconds' => 3.0, 'voice' => ['speaker' => 'narrator', 'text' => 'Mira esto.']],
            ['key' => 'd', 'plannedSeconds' => 3.0, 'voice' => ['speaker' => 'marta', 'text' => 'un termo increíble.']],
            ['key' => 'e', 'plannedSeconds' => 2.0, 'voice' => null],
        ];
        $texts = VoiceTimeline::speakerTexts($pieces);
        // Each person is read once, with all her parts in order.
        self::assertSame('Hola, soy Marta y hoy te traigo un termo increíble.', $texts['marta']['text']);
        self::assertSame('Mira esto.', $texts['narrator']['text']);

        $speech = static function (string $text): array {
            [$characters, $starts, $ends] = self::timings($text);

            return ['characters' => $characters, 'starts' => $starts, 'ends' => $ends, 'duration' => max($ends) + 0.1];
        };
        $timeline = VoiceTimeline::build($pieces, ['marta' => $speech($texts['marta']['text']), 'narrator' => $speech('Mira esto.')]);
        [$a, $b, $c, $d, $e] = $timeline['pieces'];

        // The cut between «y» and «hoy» falls in the silence between them, at least 0,15 s before
        // «hoy» (its start was cut) but never inside «y»; the slices of Marta are contiguous, so
        // her sentence is not cut when it goes on over another piece.
        [, $starts, $ends] = self::timings($texts['marta']['text']);
        $y = mb_strlen('Hola, soy Marta y') - 1;
        self::assertGreaterThanOrEqual($ends[$y], $a['voice']['to']);
        self::assertLessThanOrEqual(max($ends[$y], $starts[$y + 2] - VoiceTimeline::PRE_ROLL_SECONDS) + 0.001, $a['voice']['to']);
        self::assertSame(0.0, $a['voice']['from']);
        self::assertSame($a['voice']['to'], $b['voice']['from']);
        self::assertSame($b['voice']['to'], $d['voice']['from']);
        self::assertSame('Hola, soy Marta y', $a['voice']['text']);
        self::assertSame('narrator', $c['voice']['speaker']);

        // The video starts with 0,3 s of silence before the first word.
        self::assertSame(VoiceTimeline::LEAD_SECONDS, $a['voice']['lead']);
        self::assertArrayNotHasKey('lead', $b['voice']);
        self::assertEqualsWithDelta(VoiceTimeline::LEAD_SECONDS + $a['voice']['to'] - $a['voice']['from'], $a['durationSeconds'], 0.001);
        self::assertEqualsWithDelta(VoiceTimeline::LEAD_SECONDS, $timeline['words'][0]['start'], 0.001);
        // A very short part still lasts one second (the rest is silence).
        self::assertEqualsWithDelta(max(VoiceTimeline::MIN_VOICED_SECONDS, $b['voice']['to'] - $b['voice']['from']), $b['durationSeconds'], 0.001);
        // A part that ends a sentence keeps its planned seconds as a pause, up to 2 s more.
        self::assertEqualsWithDelta(min(3.0, $d['voice']['to'] - $d['voice']['from'] + VoiceTimeline::HOLD_SECONDS), $d['durationSeconds'], 0.001);
        self::assertGreaterThan($d['voice']['to'] - $d['voice']['from'], $d['durationSeconds']);

        // The piece without voice keeps its seconds.
        self::assertSame(2.0, $e['durationSeconds']);
        self::assertNull($e['voice']);
        self::assertEqualsWithDelta($a['durationSeconds'], $b['startSeconds'], 0.001);
        self::assertEqualsWithDelta(array_sum(array_column($timeline['pieces'], 'durationSeconds')), $timeline['totalSeconds'], 0.01);

        // The words of the subtitles, in order and inside their piece.
        self::assertSame(['Hola,', 'soy', 'Marta', 'y', 'hoy', 'te', 'traigo', 'Mira', 'esto.', 'un', 'termo', 'increíble.'], array_column($timeline['words'], 'text'));
        $previous = -1.0;
        foreach ($timeline['words'] as $word) {
            self::assertGreaterThanOrEqual($previous, $word['start']);
            self::assertGreaterThanOrEqual($word['start'], $word['end']);
            $piece = $timeline['pieces'][$word['piece']];
            self::assertGreaterThanOrEqual($piece['startSeconds'] - 0.001, $word['start']);
            self::assertLessThanOrEqual($piece['startSeconds'] + $piece['durationSeconds'] + 0.001, $word['end']);
            $previous = $word['start'];
        }
    }

    public function testAVideoThatStartsWithoutVoiceHasNoLead(): void
    {
        $pieces = [
            ['key' => 'a', 'plannedSeconds' => 2.5, 'voice' => null],
            ['key' => 'b', 'plannedSeconds' => 3.0, 'voice' => ['speaker' => 'n', 'text' => 'Ahora sí.']],
        ];
        [$characters, $starts, $ends] = self::timings('Ahora sí.');
        $timeline = VoiceTimeline::build($pieces, ['n' => ['characters' => $characters, 'starts' => $starts, 'ends' => $ends, 'duration' => max($ends)]]);
        self::assertSame(2.5, $timeline['pieces'][0]['durationSeconds']);
        self::assertArrayNotHasKey('lead', $timeline['pieces'][1]['voice']);
        self::assertEqualsWithDelta(2.5, $timeline['words'][0]['start'], 0.001);
    }

    public function testTheSubtitlesAreWordByWordWithTheBrandColour(): void
    {
        $style = BrandStyle::from(['colors' => 'Morado #6D4AFF y blanco #fff', 'fonts' => 'Montserrat, Arial']);
        self::assertSame('#6D4AFF', $style->primary);
        self::assertSame('#FFFFFF', $style->secondary);
        self::assertSame('Montserrat', $style->font);
        self::assertSame('&H00FF4A6D', BrandStyle::ass('#6D4AFF'));
        self::assertSame('0:00:01.25', AssBuilder::time(1.25));

        $words = [
            ['text' => 'Hola,', 'start' => 0.0, 'end' => 0.3], ['text' => 'soy', 'start' => 0.55, 'end' => 0.75], ['text' => 'Marta', 'start' => 0.8, 'end' => 1.1],
            ['text' => 'y', 'start' => 1.15, 'end' => 1.2], ['text' => 'hoy', 'start' => 1.25, 'end' => 1.45],
        ];
        // A comma ends a group; at most three words together.
        self::assertSame([['Hola,'], ['soy', 'Marta', 'y'], ['hoy']], array_map(static fn ($g) => array_column($g['words'], 'text'), AssBuilder::groups($words)));

        $ass = AssBuilder::build(720, 1280, $style, [['kind' => 'hook', 'text' => '¿Lo has probado?', 'start' => 0.0, 'end' => 2.0, 'position' => 'top']], [['text' => '12 horas', 'start' => 2.0, 'end' => 4.0]], [], $words);
        self::assertStringContainsString('PlayResX: 720', $ass);
        self::assertStringContainsString('Style: Sub,Montserrat,', $ass);
        self::assertStringContainsString(',Top,,0,0,0,,{\fad(200,200)}¿Lo has probado?', $ass);
        self::assertStringContainsString(',Card,,0,0,0,,{\fad(150,150)}12 horas', $ass);
        // One event per word: the group, with the word being said in the colour of the brand.
        self::assertStringContainsString('Dialogue: 3,0:00:00.55,0:00:00.80,Sub,,0,0,0,,{\1c&H00FF4A6D&}soy{\1c&H00FFFFFF&} Marta y', $ass);
        self::assertSame(5, substr_count($ass, ',Sub,,'));
        self::assertSame(0, substr_count(AssBuilder::build(720, 1280, $style, [], [], [], []), ',Sub,,'));
    }

    public function testTheHighlightAlwaysStandsOut(): void
    {
        // A light brand: the word said over its own colour was not seen.
        $style = BrandStyle::from(['colors' => '#FFD9A0, #FFFFFF']);
        $on = $style->highlightOn($style->primary);
        self::assertGreaterThanOrEqual(3.0, BrandStyle::contrast($on, $style->primary));
        self::assertNotSame('#FFFFFF', $on);

        $dark = BrandStyle::from(['colors' => '#0A7C5A, #1D1D1B']);
        $words = [['text' => 'Hola', 'start' => 0.5, 'end' => 0.9], ['text' => 'otra', 'start' => 3.0, 'end' => 3.4], ['text' => 'fin', 'start' => 6.0, 'end' => 6.4]];
        $ass = AssBuilder::build(720, 1280, $dark, [], [], [], $words, ['plain' => [[0.0, 2.0], [5.0, 7.0, '#FFFFFF']]]);
        // Over the colour of the brand the word takes another colour; over a clip, the brand's;
        // over a logo_card on white, one that stands out from white.
        self::assertStringContainsString('{\1c'.BrandStyle::ass($dark->highlightOn('#0A7C5A')).'&}Hola', $ass);
        self::assertStringContainsString('{\1c'.BrandStyle::ass('#0A7C5A').'&}otra', $ass);
        self::assertStringContainsString('{\1c'.BrandStyle::ass($dark->highlightOn('#FFFFFF')).'&}fin', $ass);
        self::assertGreaterThanOrEqual(3.0, BrandStyle::contrast($dark->highlightOn('#FFFFFF'), '#FFFFFF'));
    }

    public function testTheTextsStayInTheSafeZoneAndFadeWithThePicture(): void
    {
        $style = BrandStyle::from(['colors' => '#0A7C5A']);
        $ass = AssBuilder::build(1080, 1920, $style, [['kind' => 'hook', 'text' => 'Hola', 'start' => 0.0, 'end' => 4.0, 'position' => 'top']], [], [], [], ['logo' => true, 'fades' => [['at' => 2.0, 'out' => 0.4, 'in' => 0.4]]]);
        // Sides at 12 % (130 px of 1080), the hook under the small logo, the subtitles at 20 %.
        self::assertStringContainsString(',8,130,130,403,1', $ass);
        self::assertStringContainsString(',2,130,130,384,1', $ass);
        self::assertStringContainsString('{\t(1600,2000,\alpha&HFF&)\t(2000,2400,\alpha&H00&)}{\fad(200,200)}Hola', $ass);
    }

    public function testThreeLongWordsAreNotOneGroupOfSubtitles(): void
    {
        $words = [['text' => 'Contabilidad', 'start' => 0.0, 'end' => 0.5], ['text' => 'automatizada', 'start' => 0.5, 'end' => 1.0], ['text' => 'siempre', 'start' => 1.0, 'end' => 1.3]];
        self::assertSame([['Contabilidad'], ['automatizada'], ['siempre']], array_map(static fn ($g) => array_column($g['words'], 'text'), AssBuilder::groups($words, 14)));
    }

    public function testTheFontsAreThoseOfTheProgram(): void
    {
        $dir = \dirname(__DIR__, 2).'/resources/fonts';
        foreach (['Poppins-Bold.ttf', 'Montserrat-Bold.ttf', 'Inter-Bold.otf', 'Roboto-Bold.ttf', 'OpenSans-Bold.ttf', 'Lato-Bold.ttf', 'Oswald-Bold.ttf', 'SourceSans3-Bold.ttf'] as $file) {
            self::assertFileExists($dir.'/'.$file);
        }
        self::assertSame('Source Sans 3', VideoFonts::resolve('Source Sans Pro'));
        self::assertNull(VideoFonts::resolve('Helvetica Neue'));
        self::assertSame('Helvetica Neue', BrandStyle::from(['fonts' => 'Helvetica Neue'])->missingFont);
    }

    public function testTheMusicGoesDownWhileThereIsVoice(): void
    {
        $both = (string) FfmpegRenderer::audioFilter(1, 2, 12.5, 0.35);
        self::assertStringContainsString('[mu][sc]sidechaincompress=', $both);
        self::assertStringContainsString('loudnorm=I=-14:TP=-1.5', $both);
        self::assertStringContainsString('afade=t=out:st=11.000:d=1.500', $both);
        self::assertStringNotContainsString('sidechain', (string) FfmpegRenderer::audioFilter(1, null, 12.5, 0.35));
        self::assertNull(FfmpegRenderer::audioFilter(null, null, 12.5, 0.35));
    }

    public function testTheSmallLogoIsNotOverALogoCardNorAPlainPiece(): void
    {
        // Logo cards at 0-2 and 8-10; a text card on the colour of the brand at 6-8.
        $blocked = [[0.0, 2.0], [8.0, 10.0], [6.0, 8.0, '#FF6A00']];
        self::assertSame([['start' => 2.0, 'end' => 6.0]], AssembleStep::logoWindows(['start' => 0.0, 'end' => 10.0], $blocked));
        self::assertSame([], AssembleStep::logoWindows(['start' => 8.0, 'end' => 10.0], $blocked));
        // A piece in the middle splits the window in two.
        self::assertSame([['start' => 0.0, 'end' => 3.0], ['start' => 5.0, 'end' => 9.0]], AssembleStep::logoWindows(['start' => 0.0, 'end' => 9.0], [[3.0, 5.0]]));
        self::assertSame('between(t,0.000,3.000)+between(t,5.000,9.000)', FfmpegRenderer::logoEnable([['start' => 0.0, 'end' => 3.0], ['start' => 5.0, 'end' => 9.0]]));
    }

    public function testTheColoursOfTheLogo(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logo').'.png';
        // An orange circle with a dark blue bar on a white square: white does not count.
        $image = imagecreatetruecolor(200, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, 100, 80, 120, 120, imagecolorallocate($image, 0xFF, 0x6A, 0x00));
        imagefilledrectangle($image, 20, 160, 180, 180, imagecolorallocate($image, 0x12, 0x2B, 0x6E));
        imagepng($image, $path);
        try {
            $colors = LogoColors::main($path);
            self::assertCount(2, $colors);
            self::assertLessThan(20.0, self::distance($colors[0], '#FF6A00'));
            self::assertLessThan(20.0, self::distance($colors[1], '#122B6E'));
            self::assertNotNull(LogoColors::average($path));
        } finally {
            @unlink($path);
        }

        // An orange logo over the orange of the brand: the dark colour of the brand instead.
        $style = BrandStyle::from(['colors' => '#FF6A00, #1D1D1B']);
        self::assertSame('#1D1D1B', LogoColors::backgroundFor('#FF6A00', $style));
        // No colour of the brand stands out: white or almost black.
        $grey = BrandStyle::from(['colors' => '#777777, #888888']);
        self::assertSame('#111111', LogoColors::backgroundFor('#999999', $grey));
    }

    /**
     * Times of every character: 0,06 s each, 0,25 s of pause after punctuation.
     *
     * @return array{0: list<string>, 1: list<float>, 2: list<float>}
     */
    private static function timings(string $text): array
    {
        $characters = mb_str_split($text);
        $starts = [];
        $ends = [];
        $at = 0.0;
        foreach ($characters as $c) {
            $starts[] = round($at, 3);
            $at += 0.06;
            $ends[] = round($at, 3);
            if (\in_array($c, ['.', ',', '?', '!'], true)) {
                $at += 0.25;
            }
        }

        return [$characters, $starts, $ends];
    }

    private static function distance(string $a, string $b): float
    {
        [$a, $b] = [sscanf($a, '#%02x%02x%02x'), sscanf($b, '#%02x%02x%02x')];

        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}
