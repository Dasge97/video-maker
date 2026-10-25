<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Objective checks of a plan, after its schema. They run inside the conversation with the agent
 * (it gets the errors back and fixes them) and when the person edits the texts by hand. Each
 * error is in Spanish and says which piece and what to change, keyed by a pointer to its field.
 *
 * The figures come from the real runs of new-studio: the same text came out twice, one layer on
 * top of the other; the video lasted 15 s instead of 12 because the voice did not fit; a label
 * of 46 characters for 2,5 s could not be read next to the subtitle; a label covered the face of
 * the person speaking; the video model painted instructions as text; figures nobody wrote.
 *
 * Pure: the plan and the data of the video, nothing else.
 */
final class PlanChecks
{
    /**
     * Speech rate used to estimate how long the voice lasts, in words per second, pauses between
     * sentences included. Measured on the first real ad with ElevenLabs: 29 words in Spanish took
     * 15,1 s (1,9 words/s); 2,5 words/s would have let that voice through as 11,6 s.
     */
    public const WORDS_PER_SECOND = 2.0;
    /**
     * Ceiling of one piece: more words than this do not fit even said fast (the fastest piece of
     * that ad, a single sentence, went at 2,8 words/s; the others at 2,0–2,3 with their pauses).
     */
    public const MAX_WORDS_PER_SECOND = 2.5;
    /** The duration asked may be missed by 10 %, and never by less than one second. */
    public const DURATION_TOLERANCE = 0.1;
    public const MIN_TOLERANCE_SECONDS = 1.0;
    /** Reading speed of a text on screen while the voice and the subtitles go on. */
    public const READ_WORDS_PER_SECOND = 2.0;
    /** A text on screen is never shown for less than this. */
    public const MIN_TEXT_SECONDS = 1.5;
    /** Longest overlay: two short lines on a vertical phone. */
    public const OVERLAY_MAX_CHARS = 40;
    /** A talking_person piece: Kling AI Avatar makes 2 to 10 s. */
    public const TALKING_MIN_SECONDS = 2.0;
    public const TALKING_MAX_SECONDS = 10.0;
    public const DEFAULT_MAX_PAID_CLIPS = 5;

    private const WITH_SCENE = ['talking_person', 'generated_clip', 'image_motion'];
    private const PAID = ['talking_person', 'generated_clip'];
    /** Where the montage already draws a text of the piece itself (AssBuilder). */
    private const RESERVED = [
        'text_card' => ['center', 'su tarjeta de texto ocupa el centro'],
        'logo_card' => ['center', 'el logo y su texto ocupan el centro'],
    ];
    private const POSITIONS = ['top' => 'arriba', 'center' => 'en el centro', 'bottom' => 'abajo'];
    private const NAMES = [
        'talking_person' => 'persona a cámara',
        'generated_clip' => 'clip',
        'image_motion' => 'imagen con movimiento',
        'text_card' => 'tarjeta de texto',
        'logo_card' => 'logo',
    ];

    /**
     * @param array<string, mixed> $plan a plan that meets plan.schema.json
     * @param array{mode: string, durationSeconds: int|float, product: string, note?: string, brand?: array{name?: string, forbiddenWords?: list<string>}, hasLogo: bool, frames: int, maxPaidClips?: int} $data
     *
     * @return array<string, string> errors by pointer («pieces/2/voice»); empty when the plan is right
     */
    public static function errors(array $plan, array $data): array
    {
        $pieces = array_values($plan['pieces'] ?? []);

        return self::fields($pieces)
            + self::mode($pieces, $data)
            + self::voice($pieces)
            + self::duration($pieces, (float) $data['durationSeconds'])
            + self::overlays($plan, $pieces, (bool) $data['hasLogo'])
            + self::readability($plan, $pieces)
            + self::written($pieces)
            + self::frames($plan, (int) $data['frames'])
            + self::cost($pieces, (int) ($data['maxPaidClips'] ?? self::DEFAULT_MAX_PAID_CLIPS))
            + self::onlyWhatIsGiven($plan, $data);
    }

    /**
     * Each kind of piece carries its own fields, and only those.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function fields(array $pieces): array
    {
        $errors = [];
        foreach ($pieces as $j => $piece) {
            $kind = (string) $piece['kind'];
            $n = $j + 1;
            $needs = [
                'scene' => \in_array($kind, self::WITH_SCENE, true),
                'motion' => 'image_motion' === $kind,
                'card' => 'text_card' === $kind,
                'logo' => 'logo_card' === $kind,
            ];
            foreach ($needs as $field => $needed) {
                $has = \array_key_exists($field, $piece) && null !== $piece[$field];
                if ($needed && !$has) {
                    $errors["pieces/$j/$field"] = \sprintf('La pieza %d (%s) necesita «%s».', $n, self::NAMES[$kind] ?? $kind, $field);
                } elseif (!$needed && $has) {
                    $errors["pieces/$j/$field"] = \sprintf('La pieza %d (%s) no lleva «%s»: quítalo.', $n, self::NAMES[$kind] ?? $kind, $field);
                }
            }
            if (\in_array($kind, ['talking_person', 'image_motion'], true) && \is_array($piece['scene'] ?? null) && '' === trim((string) ($piece['scene']['startImage'] ?? ''))) {
                $errors["pieces/$j/scene/startImage"] = \sprintf('La pieza %d (%s) necesita startImage: describe su primer fotograma.', $n, self::NAMES[$kind]);
            }
        }

        return $errors;
    }

    /**
     * talking_person or voice_over, as the person chose.
     *
     * @param list<array<string, mixed>> $pieces
     * @param array<string, mixed>       $data
     *
     * @return array<string, string>
     */
    public static function mode(array $pieces, array $data): array
    {
        $errors = [];
        $talking = array_filter($pieces, static fn (array $p) => 'talking_person' === $p['kind']);
        if ('voice_over' === $data['mode']) {
            foreach (array_keys($talking) as $j) {
                $errors["pieces/$j/kind"] = \sprintf('La pieza %d es talking_person, pero el vídeo es con voz en off: la persona no habla a cámara. Usa generated_clip con withPerson si quieres que salga.', $j + 1);
            }

            return $errors;
        }
        if ([] === $talking) {
            $errors['pieces'] = 'El vídeo es de persona hablando: hace falta al menos una pieza talking_person.';

            return $errors;
        }
        $asked = (float) $data['durationSeconds'];
        $seconds = array_sum(array_map(static fn (array $p) => (float) $p['plannedSeconds'], $talking));
        if ($seconds < $asked / 2) {
            $errors['pieces'] = \sprintf('Las piezas talking_person suman %s s y el vídeo dura %s s: la persona tiene que hablar a cámara al menos la mitad (%s s).', self::n($seconds), self::n($asked), self::n($asked / 2));
        }
        foreach ($talking as $j => $piece) {
            if ('' === trim((string) ($piece['voice'] ?? ''))) {
                $errors["pieces/$j/voice"] = \sprintf('La pieza %d es la persona hablando a cámara: necesita voz.', $j + 1);
            }
            $planned = (float) $piece['plannedSeconds'];
            if ($planned < self::TALKING_MIN_SECONDS || $planned > self::TALKING_MAX_SECONDS) {
                $errors["pieces/$j/plannedSeconds"] = \sprintf('La pieza %d dura %s s y una pieza talking_person dura de %s a %s s.', $j + 1, self::n($planned), self::n(self::TALKING_MIN_SECONDS), self::n(self::TALKING_MAX_SECONDS));
            }
        }

        return $errors;
    }

    /**
     * The voice of each piece fits in its seconds.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function voice(array $pieces): array
    {
        $errors = [];
        foreach ($pieces as $j => $piece) {
            $words = self::words((string) ($piece['voice'] ?? ''));
            $seconds = (float) $piece['plannedSeconds'];
            $max = (int) floor($seconds * self::MAX_WORDS_PER_SECOND + 1e-9);
            if ($words > $max) {
                $errors["pieces/$j/voice"] = \sprintf('La voz de la pieza %d tiene %d palabras y la pieza dura %s s: en castellano se dicen unas %s palabras por segundo, así que caben unas %d (%d como mucho). Acórtala o reparte la frase con la pieza siguiente.',
                    $j + 1, $words, self::n($seconds), self::n(self::WORDS_PER_SECOND), (int) floor($seconds * self::WORDS_PER_SECOND), $max);
            }
        }

        return $errors;
    }

    /**
     * The pieces add up to the duration asked, and so does the video the voice will make.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function duration(array $pieces, float $asked): array
    {
        $tolerance = self::tolerance($asked);
        $planned = array_sum(array_map(static fn (array $p) => (float) $p['plannedSeconds'], $pieces));
        if (abs($planned - $asked) > $tolerance + 1e-9) {
            return ['pieces' => \sprintf('Las piezas suman %s s y la persona pidió %s s (margen: %s s). Ajusta plannedSeconds para que sumen la duración pedida.', self::n($planned), self::n($asked), self::n($tolerance))];
        }
        $estimated = array_sum(array_map(static fn (array $p) => self::estimatedSeconds($p), $pieces));
        if ($estimated > $asked + $tolerance + 1e-9) {
            $words = array_sum(array_map(static fn (array $p) => self::words((string) ($p['voice'] ?? '')), $pieces));

            return ['pieces' => \sprintf('Con la voz, el vídeo duraría unos %s s y la persona pidió %s s: cada pieza con voz dura lo que su voz, a unas %s palabras por segundo. La voz tiene %d palabras. Acórtala.',
                self::n($estimated), self::n($asked), self::n(self::WORDS_PER_SECOND), $words)];
        }

        return [];
    }

    /** How long a piece will last: with voice, at least what its voice takes. */
    public static function estimatedSeconds(array $piece): float
    {
        $planned = (float) $piece['plannedSeconds'];
        $words = self::words((string) ($piece['voice'] ?? ''));

        return 0 === $words ? $planned : max($planned, $words / self::WORDS_PER_SECOND);
    }

    /**
     * Texts on screen that would come out on top of each other, twice, or over a face.
     *
     * @param array<string, mixed>       $plan
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function overlays(array $plan, array $pieces, bool $hasLogo): array
    {
        $errors = [];
        $count = \count($pieces);
        if (!$hasLogo) {
            foreach ($pieces as $j => $piece) {
                if ('logo_card' === $piece['kind']) {
                    $errors["pieces/$j/kind"] = \sprintf('No hay logo: la pieza %d no puede ser un logo_card. Usa una text_card para el cierre.', $j + 1);
                }
            }
        }
        $texts = [];
        foreach ($plan['overlays'] ?? [] as $k => $overlay) {
            $from = (int) $overlay['fromPiece'];
            $to = (int) $overlay['toPiece'];
            if ($from < 1 || $to < $from || $to > $count) {
                $errors["overlays/$k"] = \sprintf('fromPiece y toPiece van de 1 a %d (las piezas), y fromPiece no es mayor que toPiece.', $count);
                continue;
            }
            if ('logo' === $overlay['kind']) {
                if (!$hasLogo) {
                    $errors["overlays/$k"] = 'No hay logo: quita este rótulo de tipo logo.';
                    continue;
                }
                for ($p = $from; $p <= $to; ++$p) {
                    if ('logo_card' === $pieces[$p - 1]['kind']) {
                        $errors["overlays/$k"] ??= \sprintf('La pieza %d ya es el logo (logo_card): el logo pequeño encima lo repetiría. Que no cubra esa pieza.', $p);
                    }
                }
                continue;
            }
            $text = trim((string) $overlay['text']);
            if ('' === $text) {
                continue;
            }
            $position = (string) $overlay['position'];
            for ($p = $from; $p <= $to; ++$p) {
                $piece = $pieces[$p - 1];
                if ('talking_person' === $piece['kind'] && 'bottom' !== $position) {
                    $errors["overlays/$k"] ??= \sprintf('La pieza %d es la persona hablando a cámara: un rótulo %s le taparía la cara. Ponlo abajo (bottom) o en otras piezas.', $p, self::POSITIONS[$position] ?? $position);
                }
                $reserved = self::RESERVED[$piece['kind']] ?? null;
                if (null !== $reserved && $reserved[0] === $position) {
                    $errors["overlays/$k"] ??= \sprintf('En la pieza %d %s: este rótulo saldría encima. Ponlo en otra posición o quítalo.', $p, $reserved[1]);
                }
                foreach ([['card', 'la tarjeta'], ['logo', 'el texto bajo el logo']] as [$field, $what]) {
                    $own = trim((string) ($piece[$field]['text'] ?? ''));
                    if (self::repeats($text, $own)) {
                        $errors["overlays/$k"] ??= \sprintf('Repite el texto de %s de la pieza %d («%s»): saldrían los dos a la vez. Quita el rótulo o cambia uno de los textos.', $what, $p, mb_substr($own, 0, 80));
                    }
                }
            }
            foreach ($texts as $m => $other) {
                if ($from > $other['to'] || $to < $other['from']) {
                    continue;
                }
                if ($position === $other['position']) {
                    $errors["overlays/$k"] ??= \sprintf('Coincide con el rótulo %d en la misma posición (%s) en las piezas %d a %d: saldrían uno encima del otro.', $m + 1, self::POSITIONS[$position] ?? $position, max($from, $other['from']), min($to, $other['to']));
                } elseif (self::repeats($text, $other['text'])) {
                    $errors["overlays/$k"] ??= \sprintf('Repite el texto del rótulo %d («%s»), que sale a la vez.', $m + 1, mb_substr($other['text'], 0, 80));
                }
            }
            $texts[$k] = ['from' => $from, 'to' => $to, 'position' => $position, 'text' => $text];
        }

        return $errors;
    }

    /**
     * Texts on screen that cannot be read in the time they are shown.
     *
     * @param array<string, mixed>       $plan
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function readability(array $plan, array $pieces): array
    {
        $errors = [];
        $seconds = array_map(static fn (array $p) => self::estimatedSeconds($p), $pieces);
        foreach ($pieces as $j => $piece) {
            $text = 'text_card' === $piece['kind'] ? trim((string) ($piece['card']['text'] ?? '')) : '';
            $needed = self::readingSeconds($text);
            if ('' !== $text && $seconds[$j] + 0.05 < $needed) {
                $errors["pieces/$j/card/text"] = \sprintf('La tarjeta de la pieza %d dice %d palabras y la pieza dura unos %s s: hacen falta %s s para leerla. Acorta el texto o alarga la pieza.',
                    $j + 1, self::words($text), self::n($seconds[$j]), self::n($needed));
            }
        }
        foreach ($plan['overlays'] ?? [] as $k => $overlay) {
            $text = trim((string) $overlay['text']);
            $from = (int) $overlay['fromPiece'];
            $to = (int) $overlay['toPiece'];
            if ('logo' === $overlay['kind'] || '' === $text || $from < 1 || $to < $from || $to > \count($pieces)) {
                continue;
            }
            if (mb_strlen($text) > self::OVERLAY_MAX_CHARS) {
                $errors["overlays/$k/text"] = \sprintf('El rótulo %d tiene %d caracteres y caben %d en la pantalla de un móvil: déjalo en una idea de pocas palabras.', $k + 1, mb_strlen($text), self::OVERLAY_MAX_CHARS);
                continue;
            }
            $shown = array_sum(\array_slice($seconds, $from - 1, $to - $from + 1));
            $needed = self::readingSeconds($text);
            if ($shown + 0.05 < $needed) {
                $errors["overlays/$k/text"] = \sprintf('El rótulo %d tiene %d palabras y se ve unos %s s (piezas %d a %d): hacen falta %s s para leerlo a la vez que el subtítulo. Acórtalo o haz que cubra más piezas.',
                    $k + 1, self::words($text), self::n($shown), $from, $to, self::n($needed));
            }
        }

        return $errors;
    }

    /** Seconds a text on screen needs to be read. */
    public static function readingSeconds(string $text): float
    {
        return max(self::MIN_TEXT_SECONDS, self::words($text) / self::READ_WORDS_PER_SECOND);
    }

    /**
     * Nothing written for the video model: it paints quoted texts as letters.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function written(array $pieces): array
    {
        $errors = [];
        foreach ($pieces as $j => $piece) {
            foreach (['action', 'framing', 'camera', 'lighting'] as $field) {
                $value = (string) ($piece['scene'][$field] ?? '');
                if (1 === preg_match('/[«»"“”]|\'[^\']+\'/u', $value)) {
                    $errors["pieces/$j/scene/$field"] = \sprintf('La pieza %d lleva texto entre comillas en «%s»: el modelo de vídeo lo pintaría como letras. Describe solo lo que se ve, sin textos que se lean.', $j + 1, $field);
                }
            }
        }

        return $errors;
    }

    /**
     * The frames cited exist among the ones sent.
     *
     * @param array<string, mixed>       $plan
     *
     * @return array<string, string>
     */
    public static function frames(array $plan, int $frames): array
    {
        $errors = [];
        $bad = static fn (mixed $frame): bool => null !== $frame && ((int) $frame < 0 || (int) $frame >= $frames);
        foreach ($plan['reference']['beats'] ?? [] as $b => $beat) {
            if ($bad($beat['frame'] ?? null)) {
                $errors["reference/beats/$b/frame"] = \sprintf('El fotograma %d no existe: van del 0 al %d.', $beat['frame'], $frames - 1);
            }
        }
        foreach ($plan['pieces'] ?? [] as $j => $piece) {
            if ($bad($piece['scene']['imitateFrame'] ?? null)) {
                $errors["pieces/$j/scene/imitateFrame"] = \sprintf('La pieza %d imita el fotograma %d, que no existe: van del 0 al %d.', $j + 1, $piece['scene']['imitateFrame'], $frames - 1);
            }
        }

        return $errors;
    }

    /**
     * A safety limit on the pieces that pay a clip.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<string, string>
     */
    public static function cost(array $pieces, int $max): array
    {
        $paid = \count(array_filter($pieces, static fn (array $p) => \in_array($p['kind'], self::PAID, true)));
        if ($paid > $max) {
            return ['pieces' => \sprintf('Hay %d piezas que generan un clip (talking_person y generated_clip) y el límite es %d. Usa image_motion o text_card en las demás.', $paid, $max)];
        }

        return [];
    }

    /**
     * No figure nobody wrote, no forbidden word of the brand.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function onlyWhatIsGiven(array $plan, array $data): array
    {
        $texts = [];
        foreach ($plan['pieces'] ?? [] as $j => $piece) {
            $texts["pieces/$j/voice"] = (string) ($piece['voice'] ?? '');
            $texts["pieces/$j/scene/startImage"] = (string) ($piece['scene']['startImage'] ?? '');
            $texts["pieces/$j/card/text"] = (string) ($piece['card']['text'] ?? '');
            $texts["pieces/$j/logo/text"] = (string) ($piece['logo']['text'] ?? '');
        }
        foreach ($plan['overlays'] ?? [] as $k => $overlay) {
            $texts["overlays/$k/text"] = (string) $overlay['text'];
        }
        $known = self::figuresIn(implode("\n", [(string) $data['product'], (string) ($data['note'] ?? ''), (string) ($data['brand']['name'] ?? '')]));
        $forbidden = array_values(array_filter(array_map('trim', $data['brand']['forbiddenWords'] ?? []), static fn (string $w) => '' !== $w));

        $errors = [];
        foreach ($texts as $pointer => $text) {
            if ('' === trim($text)) {
                continue;
            }
            $word = self::findForbidden($text, $forbidden);
            if (null !== $word) {
                $errors[$pointer] = \sprintf('«%s» es una palabra prohibida de la marca (también en otro género o número). Cámbiala.', $word);
                continue;
            }
            foreach (array_keys(self::figuresIn($text)) as $figure) {
                if (!isset($known[$figure])) {
                    $errors[$pointer] = \sprintf('La cifra «%s» no está en lo que escribió la persona: no inventes datos. Quítala o pregúntala en questions.', str_replace('.', ',', (string) $figure));
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * The first forbidden word (or phrase) found in a text, compared without accents, case, nor
     * the endings of gender and number («barato» also forbids «baratas»).
     *
     * @param list<string> $forbidden
     */
    public static function findForbidden(string $text, array $forbidden): ?string
    {
        $stems = array_map(self::stem(...), self::tokens($text));
        foreach ($forbidden as $word) {
            $wanted = array_map(self::stem(...), self::tokens($word));
            if ([] === $wanted) {
                continue;
            }
            for ($i = 0; $i + \count($wanted) <= \count($stems); ++$i) {
                if (\array_slice($stems, $i, \count($wanted)) === $wanted) {
                    return $word;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, true> the figures of a text («5,99» and «5.99» are the same)
     */
    public static function figuresIn(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $m);
        $known = [];
        foreach ($m[0] as $figure) {
            $known[str_replace(',', '.', $figure)] = true;
        }

        return $known;
    }

    /**
     * The same text, or one contained in the other as whole words (two words at least: the name
     * of the brand alone in both is not a repetition). Case, punctuation and spacing do not count.
     */
    public static function repeats(string $a, string $b): bool
    {
        $a = implode(' ', self::tokens($a));
        $b = implode(' ', self::tokens($b));
        if ('' === $a || '' === $b) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        return substr_count($short, ' ') >= 1 && str_contains(' '.$long.' ', ' '.$short.' ');
    }

    /** Words said aloud: a figure («14») is one word. */
    public static function words(string $text): int
    {
        return \count(array_filter(preg_split('/\s+/u', trim($text)) ?: [], static fn (string $w) => 1 === preg_match('/[\p{L}\p{N}]/u', $w)));
    }

    public static function tolerance(float $asked): float
    {
        return max(self::MIN_TOLERANCE_SECONDS, $asked * self::DURATION_TOLERANCE);
    }

    /**
     * Lower case words without accents nor punctuation.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $plain = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = (string) preg_replace('/\p{Mn}+/u', '', false === $plain ? $text : $plain);

        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], static fn (string $w) => '' !== $w));
    }

    /** The word without its ending of gender and number, when enough is left. */
    private static function stem(string $word): string
    {
        // «-e» too, so «coste» and «costes» meet.
        foreach (['os', 'as', 'es', 'o', 'a', 'e', 's'] as $ending) {
            if (str_ends_with($word, $ending) && mb_strlen($word) - mb_strlen($ending) >= 3) {
                return mb_substr($word, 0, -mb_strlen($ending));
            }
        }

        return $word;
    }

    private static function n(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }
}
