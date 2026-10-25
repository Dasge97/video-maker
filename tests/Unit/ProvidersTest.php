<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Job\Estimate;
use App\Job\Steps\MusicStep;
use App\Prompt\PromptBuilder;
use App\Providers\FalAvatarGateway;
use App\Providers\FalVideoGateway;
use App\Providers\VideoCatalog;
use App\Providers\VideoTask;
use App\Sound\SoundCatalog;
use PHPUnit\Framework\TestCase;

/**
 * What goes to the providers, without calling them: prompts, bodies, durations and prices.
 */
final class ProvidersTest extends TestCase
{
    private static string $image;

    public static function setUpBeforeClass(): void
    {
        self::$image = (string) tempnam(sys_get_temp_dir(), 'img').'.png';
        $gd = imagecreatetruecolor(400, 600);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 120, 40));
        imagepng($gd, self::$image);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$image);
    }

    public function testTheVideoModelNeverGetsWriting(): void
    {
        $prompt = (new PromptBuilder())->clipPrompt(['spec' => [
            'action' => 'Una mano coge la botella. Un cartel dice «Oferta», en Poppins bold.',
            'framing' => 'primer plano, con hueco para el texto',
            'cameraMovement' => 'acercamiento lento',
            'lighting' => 'luz de mañana',
        ]], [['role' => 'element', 'name' => 'the person', 'citation' => '@Element1']]);
        self::assertStringContainsString('Una mano coge la botella.', $prompt);
        self::assertStringNotContainsString('Oferta', $prompt);
        self::assertStringNotContainsString('Poppins', $prompt);
        self::assertStringNotContainsString('texto', $prompt);
        self::assertStringContainsString('References: @Element1 is the person.', $prompt);
        self::assertStringContainsString('One single continuous shot', $prompt);
        self::assertStringEndsWith(PromptBuilder::NO_TEXT, $prompt);

        $onStart = (new PromptBuilder())->clipPrompt(['spec' => ['action' => 'Sonríe.']], [['role' => 'first_frame', 'type' => 'start_frame', 'name' => 'the start image', 'citation' => null]]);
        self::assertStringEndsWith(PromptBuilder::KEEP_START, $onStart);
    }

    public function testTheStartImageOfThePersonIsAnEditOfHerPhotoInHerClothes(): void
    {
        $brand = ['name' => 'Brisa', 'colors' => '#01A1CB'];
        $person = (new PromptBuilder())->picturePrompt(['use' => 'start', 'description' => 'En su cocina.', 'baseAssetId' => 'person', 'baseKind' => 'person'], ['spec' => ['action' => 'Habla.']], $brand);
        self::assertStringContainsString('exactly the same face', $person);
        self::assertStringContainsString('Same clothes', $person);
        self::assertStringNotContainsString('#01A1CB', $person);
        $object = (new PromptBuilder())->picturePrompt(['use' => 'start', 'description' => 'Una botella en la encimera.'], null, $brand);
        self::assertStringContainsString('#01A1CB', $object);
        $screen = (new PromptBuilder())->picturePrompt(['use' => 'start', 'description' => 'Un móvil con la web abierta.'], null, $brand);
        self::assertStringContainsString('every text in it is written exactly', $screen);
        self::assertSame('864x1536', PromptBuilder::pictureSize('9:16'));
        self::assertTrue(PromptBuilder::unusableReference(2560, 600));
        self::assertFalse(PromptBuilder::unusableReference(1024, 1536));
    }

    public function testTheBodyOfAKlingClip(): void
    {
        $model = (new VideoCatalog(\dirname(__DIR__, 2).'/config/models.yaml'))->get('balanced');
        $body = FalVideoGateway::body(new VideoTask($model['video']['endpoint'], 'Sonríe.', [
            ['role' => 'element', 'path' => self::$image, 'mime' => 'image/png'],
            ['role' => 'first_frame', 'path' => self::$image, 'mime' => 'image/png'],
            ['role' => 'image', 'path' => self::$image, 'mime' => 'image/png'],
        ], 3, '9:16', $model));
        self::assertSame('3', $body['duration']);
        self::assertSame('9:16', $body['aspect_ratio']);
        self::assertFalse($body['generate_audio']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $body['elements'][0]['frontal_image_url']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $body['start_image_url']);
        self::assertCount(1, $body['image_urls']);

        // A piece of 2,4 s is asked as 3 s, the minimum; one of 3,2 s as 4 s.
        self::assertSame(3, VideoCatalog::billedSeconds($model, 2.4));
        self::assertSame(4, VideoCatalog::billedSeconds($model, 3.2));
        self::assertEqualsWithDelta(0.42, VideoCatalog::clipUsd($model, 3), 0.0001);
    }

    public function testTheBodyOfTheTalkingPerson(): void
    {
        $audio = (string) tempnam(sys_get_temp_dir(), 'aud').'.wav';
        file_put_contents($audio, 'RIFF');
        try {
            $body = FalAvatarGateway::body(self::$image, $audio, 'Habla con naturalidad.');
            self::assertStringStartsWith('data:image/jpeg;base64,', $body['image_url']);
            self::assertStringStartsWith('data:audio/wav;base64,', $body['audio_url']);
            self::assertSame('Habla con naturalidad.', $body['prompt']);
        } finally {
            @unlink($audio);
        }
    }

    public function testTheMusicIsAskedWithoutSeconds(): void
    {
        self::assertSame('Pop alegre, Sube al final', MusicStep::withoutSeconds('Pop alegre, de 15 segundos. Sube al final'));
        self::assertStringStartsWith('Pop alegre. Optimista. Música instrumental', MusicStep::prompt('Pop alegre', 'Optimista', 'Pop'));
    }

    public function testTheEstimateLineByLine(): void
    {
        $plan = json_decode((string) file_get_contents(\dirname(__DIR__).'/fixtures/talking_person/plan.json'), true);
        $estimate = (new Estimate(new VideoCatalog(\dirname(__DIR__, 2).'/config/models.yaml'), new SoundCatalog(\dirname(__DIR__, 2).'/config/sound.yaml')))->of($plan, 'balanced');
        $whats = array_column($estimate['lines'], 'what');
        self::assertContains('Pieza 1: persona hablando (Kling AI Avatar, 3,5 s)', $whats);
        self::assertContains('Pieza 2: clip (Kling O3 Standard, 3 s)', $whats);
        self::assertContains('Música', $whats);
        self::assertEqualsWithDelta(array_sum(array_column($estimate['lines'], 'usd')), $estimate['usd'], 0.01);
        self::assertGreaterThan(0.5, $estimate['usd']);
    }
}
