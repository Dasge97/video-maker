<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agent\PlanChecks;
use App\Agent\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * One test per rule of docs/04-agente.md, each breaking a valid plan in one place.
 */
final class PlanChecksTest extends TestCase
{
    public function testAValidPlanPassesTheSchemaAndTheChecks(): void
    {
        self::assertSame([], (new SchemaValidator(\dirname(__DIR__, 2).'/prompts'))->errors(self::plan()));
        self::assertSame([], PlanChecks::errors(self::plan(), self::data()));
    }

    public function testTheSchemaRejectsAFieldThatDoesNotExist(): void
    {
        $plan = self::plan();
        $plan['pieces'][0]['font'] = 'Arial';
        self::assertNotSame([], (new SchemaValidator(\dirname(__DIR__, 2).'/prompts'))->errors($plan));
    }

    public function testTheVoiceFitsInItsPiece(): void
    {
        $plan = self::plan();
        // 3 s fit 7 words at most (2,5 per second).
        $plan['pieces'][1]['voice'] = 'Abres la app, eliges tu plaza libre y listo.';
        $errors = PlanChecks::errors($plan, self::data());
        self::assertArrayHasKey('pieces/1/voice', $errors);
        self::assertStringContainsString('9 palabras', $errors['pieces/1/voice']);
        // A figure is one word.
        self::assertSame(3, PlanChecks::words('Son 14 euros.'));
    }

    public function testThePiecesAddUpToTheDurationAsked(): void
    {
        $plan = self::plan();
        $plan['pieces'][3]['plannedSeconds'] = 7.0;
        self::assertStringContainsString('suman 18 s', PlanChecks::errors($plan, self::data())['pieces']);
        // Within 10 % (1,5 s of 15) it is right.
        $plan['pieces'][3]['plannedSeconds'] = 5.0;
        self::assertArrayNotHasKey('pieces', PlanChecks::duration($plan['pieces'], 15.0));
    }

    public function testTheVideoTheVoiceMakesFitsTheDuration(): void
    {
        $pieces = [
            ['kind' => 'generated_clip', 'voice' => str_repeat('palabra ', 10), 'plannedSeconds' => 4.0],
            ['kind' => 'generated_clip', 'voice' => str_repeat('palabra ', 10), 'plannedSeconds' => 4.0],
            ['kind' => 'generated_clip', 'voice' => str_repeat('palabra ', 10), 'plannedSeconds' => 4.0],
        ];
        // 30 words at 2 per second are 15 s, against 12 asked (margin 1,2 s).
        self::assertStringContainsString('unos 15 s', PlanChecks::duration($pieces, 12.0)['pieces']);
    }

    public function testALabelCanBeRead(): void
    {
        $plan = self::plan();
        $plan['overlays'][0]['text'] = 'Aparca en un minuto sin dar vueltas por el centro';
        self::assertStringContainsString('caracteres', PlanChecks::errors($plan, self::data())['overlays/0/text']);

        $plan = self::plan();
        $plan['pieces'][1]['plannedSeconds'] = 1.5;
        $plan['pieces'][1]['voice'] = null;
        $plan['pieces'][3]['plannedSeconds'] = 5.5;
        $plan['overlays'][] = ['kind' => 'label', 'text' => 'Elige plaza, paga y aparca ya', 'fromPiece' => 2, 'toPiece' => 2, 'position' => 'bottom'];
        self::assertStringContainsString('6 palabras', PlanChecks::errors($plan, self::data())['overlays/2/text']);
    }

    public function testNoLabelCoversTheFaceOfThePersonSpeaking(): void
    {
        $plan = self::plan();
        $plan['overlays'][0]['position'] = 'top';
        self::assertStringContainsString('la cara', PlanChecks::errors($plan, self::data())['overlays/0']);
    }

    public function testTwoLabelsDoNotShareAPositionNorRepeatAText(): void
    {
        $plan = self::plan();
        $plan['overlays'][] = ['kind' => 'label', 'text' => 'Sin esperas', 'fromPiece' => 1, 'toPiece' => 2, 'position' => 'bottom'];
        self::assertStringContainsString('misma posición', PlanChecks::errors($plan, self::data())['overlays/2']);

        $plan = self::plan();
        $plan['overlays'][] = ['kind' => 'cta', 'text' => 'Descárgala gratis', 'fromPiece' => 4, 'toPiece' => 4, 'position' => 'bottom'];
        self::assertStringContainsString('Repite el texto', PlanChecks::errors($plan, self::data())['overlays/2']);
    }

    public function testTheSmallLogoIsNotOverTheLogoCardAndNeedsALogo(): void
    {
        $plan = self::plan();
        $plan['overlays'][1]['toPiece'] = 4;
        self::assertStringContainsString('ya es el logo', PlanChecks::errors($plan, self::data())['overlays/1']);

        $errors = PlanChecks::errors(self::plan(), ['hasLogo' => false] + self::data());
        self::assertArrayHasKey('pieces/3/kind', $errors);
        self::assertArrayHasKey('overlays/1', $errors);
    }

    public function testEachKindOfPieceCarriesItsFields(): void
    {
        $plan = self::plan();
        $plan['pieces'][3]['scene'] = $plan['pieces'][1]['scene'];
        $plan['pieces'][2]['scene']['startImage'] = null;
        $errors = PlanChecks::errors($plan, self::data());
        self::assertStringContainsString('no lleva «scene»', $errors['pieces/3/scene']);
        self::assertArrayHasKey('pieces/2/scene/startImage', $errors);

        $plan = self::plan();
        $plan['pieces'][1]['kind'] = 'image_motion';
        self::assertStringContainsString('necesita «motion»', PlanChecks::errors($plan, self::data())['pieces/1/motion']);
    }

    public function testTheModeThePersonChose(): void
    {
        $errors = PlanChecks::errors(self::plan(), ['mode' => 'voice_over'] + self::data());
        self::assertArrayHasKey('pieces/0/kind', $errors);
        self::assertArrayHasKey('pieces/2/kind', $errors);

        // Talking at least half of the video.
        $plan = self::plan();
        $plan['pieces'][2]['kind'] = 'generated_clip';
        self::assertStringContainsString('al menos la mitad', PlanChecks::errors($plan, self::data())['pieces']);

        // A talking piece has voice.
        $plan = self::plan();
        $plan['pieces'][0]['voice'] = null;
        self::assertStringContainsString('necesita voz', PlanChecks::errors($plan, self::data())['pieces/0/voice']);
    }

    public function testNoFigureNobodyWrote(): void
    {
        $plan = self::plan();
        $plan['pieces'][2]['voice'] = 'Me ahorra 20 minutos cada día.';
        self::assertStringContainsString('«20»', PlanChecks::errors($plan, self::data())['pieces/2/voice']);

        // «5,99» written by the person is «5.99» too.
        $plan['pieces'][2]['voice'] = 'Solo 5.99 al mes.';
        self::assertArrayNotHasKey('pieces/2/voice', PlanChecks::errors($plan, self::data()));
    }

    public function testNoForbiddenWordInAnyGenderNorNumber(): void
    {
        $plan = self::plan();
        $plan['overlays'][0]['text'] = 'Plazas baratas';
        self::assertStringContainsString('«barato»', PlanChecks::errors($plan, self::data())['overlays/0/text']);
        self::assertSame('barato', PlanChecks::findForbidden('Los más BARATOS', ['barato']));
        self::assertSame('sin coste', PlanChecks::findForbidden('Aparca sin costes extra', ['sin coste']));
        self::assertNull(PlanChecks::findForbidden('Es un barco', ['barato']));
    }

    public function testNothingWrittenForTheVideoModel(): void
    {
        $plan = self::plan();
        $plan['pieces'][1]['scene']['action'] = 'Una mano toca un botón que dice «Reservar».';
        self::assertArrayHasKey('pieces/1/scene/action', PlanChecks::errors($plan, self::data()));
    }

    public function testTheFramesExist(): void
    {
        $plan = self::plan();
        $plan['pieces'][1]['scene']['imitateFrame'] = 9;
        $plan['reference']['beats'][0]['frame'] = 5;
        $errors = PlanChecks::errors($plan, self::data());
        self::assertArrayHasKey('pieces/1/scene/imitateFrame', $errors);
        self::assertArrayHasKey('reference/beats/0/frame', $errors);
    }

    public function testALimitOfPaidClips(): void
    {
        self::assertStringContainsString('el límite es 2', PlanChecks::errors(self::plan(), ['maxPaidClips' => 2] + self::data())['pieces']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        return [
            'mode' => 'talking_person',
            'durationSeconds' => 15,
            'product' => 'La app de OVE para aparcar en el centro. Descarga gratis. Plan premium a 5,99 € al mes.',
            'note' => '',
            'brand' => ['name' => 'OVE', 'forbiddenWords' => ['barato', 'sin coste']],
            'hasLogo' => true,
            'frames' => 5,
            'maxPaidClips' => 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(): array
    {
        $scene = static fn (string $action, ?string $startImage, ?int $frame, bool $person) => [
            'action' => $action, 'framing' => 'plano medio', 'camera' => 'fija', 'lighting' => 'luz natural de día',
            'withPerson' => $person, 'startImage' => $startImage, 'imitateFrame' => $frame,
        ];

        return [
            'summary' => 'Un anuncio con la persona hablando a cámara y un plano de la app.',
            'reference' => [
                'summary' => 'Una presentadora habla a cámara y enseña el móvil.',
                'rhythm' => 'Cuatro planos de 3 a 5 s.',
                'beats' => [['start' => 0, 'end' => 4, 'what' => 'La presentadora saluda.', 'frame' => 0]],
            ],
            'pieces' => [
                ['kind' => 'talking_person', 'title' => 'Gancho', 'voice' => '¿Sabías que puedes aparcar en un minuto?', 'plannedSeconds' => 4.0, 'transition' => 'cut',
                    'scene' => $scene('Habla a cámara con naturalidad.', 'La persona en una calle del centro, de día, mirando a cámara.', 0, true)],
                ['kind' => 'generated_clip', 'title' => 'La app', 'voice' => 'Abres la app y eliges plaza.', 'plannedSeconds' => 3.0, 'transition' => 'cut',
                    'scene' => $scene('Un coche entra despacio en una plaza libre.', null, 1, false)],
                ['kind' => 'talking_person', 'title' => 'Prueba', 'voice' => 'Yo la uso cada día y me ahorra tiempo.', 'plannedSeconds' => 4.0, 'transition' => 'fade',
                    'scene' => $scene('Sonríe y asiente mientras habla.', 'La persona junto a su coche aparcado.', 2, true)],
                ['kind' => 'logo_card', 'title' => 'Llamada a la acción', 'voice' => 'Descárgala gratis hoy.', 'plannedSeconds' => 4.0, 'transition' => 'cut',
                    'logo' => ['text' => 'Descárgala gratis', 'background' => null]],
            ],
            'overlays' => [
                ['kind' => 'hook', 'text' => 'Aparca sin vueltas', 'fromPiece' => 1, 'toPiece' => 1, 'position' => 'bottom'],
                ['kind' => 'logo', 'text' => '', 'fromPiece' => 1, 'toPiece' => 3, 'position' => 'top'],
            ],
            'subtitles' => true,
            'music' => ['style' => 'Pop alegre', 'mood' => 'Optimista'],
        ];
    }
}
