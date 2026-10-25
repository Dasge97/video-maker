<?php

declare(strict_types=1);

namespace App\Prompt;

/**
 * Prompts for the providers, built from the plan of the agent: for each clip its action,
 * framing, camera and light, citing the images it uses; for each image made before a piece, its
 * description. Labels are in English (what video models follow best); the content stays as
 * written.
 *
 * The video model never writes. Texts, fonts and logos are laid by the montage, so the prompt of
 * a clip leaves out every quoted text, every font name and every clause that speaks of texts,
 * letters or logos, and always ends saying that nothing is written on screen. A clip that must
 * show a screen starts on an image made before (startImage), where the image model writes the
 * interface; the video model only animates it.
 */
final class PromptBuilder
{
    private const IMITATE = ['movement' => 'its movement', 'framing' => 'its framing', 'style' => 'its style'];

    /** A clause that speaks of something written on screen (Spanish and English). */
    private const WRITTEN = '/(tipogr[aá]f|fuentes? (tipogr|de letra)|\bfont|typeface|typograph|\bletras?\b|lettering|\btextos?\b|\btext\b|r[oó]tulo|subt[ií]tul|caption|\blog(o|otipo)s?\b|semibold|\bbold\b|negrita|eslogan|slogan|hashtag|watermark|marca de agua|\bescrit[oa]s?\b|\bwritten\b|\btitular\b|headline)/iu';

    /** Font names that models paint as words; the fonts of the brand are added to these. */
    private const FONTS = ['Poppins', 'Montserrat', 'Roboto', 'Helvetica', 'Arial', 'Lato', 'Open Sans', 'Raleway', 'Futura', 'Gotham', 'Inter', 'Nunito', 'Oswald', 'Playfair', 'Avenir', 'Proxima Nova', 'Source Sans'];

    public const NO_TEXT = 'No text of any kind on screen: no letters, words, numbers, captions, subtitles, signs, labels, logos nor watermarks; any screen shows only soft abstract shapes and colours.';
    public const KEEP_START = 'The clip starts exactly on the start image and animates it: keep everything in it as it is, and do not add, change nor animate any text, letters or logos; a screen keeps exactly what the start image shows.';

    /** Wider or taller than this, an image is a logo or a banner, never a reference of a clip. */
    public const MAX_REFERENCE_RATIO = 2.0;

    /** A description that speaks of a screen (Spanish and English). */
    private const SCREEN = '/(pantalla|m[oó]vil|tel[eé]fono|smartphone|ordenador|port[aá]til|tablet|tableta|monitor|\bapp\b|aplicaci[oó]n|interfaz|\bweb\b|screen|phone|laptop|computer|interface|dashboard)/iu';

    /**
     * An image that must not go to the video model as a reference: one as wide or as tall as a
     * logo or a banner. In a real run of new-studio the logo of a brand (2560×600) went as «the
     * product» and the model drew it as an object.
     */
    public static function unusableReference(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $ratio = $width / $height;

        return $ratio > self::MAX_REFERENCE_RATIO || $ratio < 1 / self::MAX_REFERENCE_RATIO;
    }

    /**
     * @param array{spec: array<string, mixed>} $shot       spec: action, framing, cameraMovement, lighting
     * @param list<array<string, mixed>>        $references role (element, first_frame, reference), type, citation, name, imitate
     * @param list<string>                      $fonts      fonts of the brand, never written in the prompt
     */
    public function clipPrompt(array $shot, array $references, string $note = '', array $fonts = []): string
    {
        $spec = $shot['spec'];
        $action = self::withoutWriting((string) ($spec['action'] ?? ''), $fonts);
        if ('' === $action) {
            // Everything spoke of texts: the action without quotes nor fonts, said once more below.
            $action = self::withoutQuotesNorFonts((string) ($spec['action'] ?? ''), $fonts);
        }
        $parts = [self::sentence($action)];
        $startsOnImage = [] !== array_filter($references, static fn ($r) => 'first_frame' === $r['role'] && 'start_frame' === ($r['type'] ?? null));
        $guion = array_values(array_filter($references, static fn ($r) => 'reference' === $r['role']));
        $references = array_values(array_filter($references, static fn ($r) => 'reference' !== $r['role'] && 'start_frame' !== ($r['type'] ?? null)));
        $cited = array_filter($references, static fn ($r) => null !== ($r['citation'] ?? null));
        if ($startsOnImage) {
            $parts[] = 'It starts on the start image given as its first frame.';
        }
        if ([] !== $cited) {
            $parts[] = 'References: '.implode('; ', array_map(
                static fn ($r) => \sprintf('%s is %s', $r['citation'], $r['name']),
                $cited,
            )).'.';
        } elseif ([] !== $references && !$startsOnImage) {
            $parts[] = \sprintf('The first frame shows %s.', $references[0]['name']);
        }
        foreach (['visibleState' => 'Visible state', 'framing' => 'Framing', 'cameraMovement' => 'Camera', 'lighting' => 'Lighting'] as $field => $label) {
            // Every field without what speaks of writing (a framing «with room for the text» is a
            // blank sign for the video model).
            $value = trim(self::withoutWriting((string) ($spec[$field] ?? ''), $fonts));
            if ('' !== $value) {
                $parts[] = $label.': '.self::sentence($value);
            }
        }
        foreach ($guion as $r) {
            $imitate = array_map(static fn ($i) => self::IMITATE[$i] ?? $i, \is_array($r['imitate'] ?? null) ? $r['imitate'] : []);
            $what = [] === $imitate ? 'its look' : implode(' and ', $imitate);
            $parts[] = \sprintf('Reference «%s»%s: imitate %s.', $r['name'], null === ($r['citation'] ?? null) ? '' : ' ('.$r['citation'].')', $what);
        }
        // The transition to the next shot is never in the prompt: the model drew it as a cut
        // inside the clip. The montage makes it (cut or fade). Constraints never go either: the
        // model painted them as text.
        if ('' !== trim($note)) {
            $parts[] = 'Change from the previous take: '.self::sentence(self::withoutQuotesNorFonts($note, $fonts));
        }
        $parts[] = 'One single continuous shot from start to end: no cuts, no transitions, no change of scene nor of camera angle; cinematic.';
        $parts[] = $startsOnImage ? self::KEEP_START : self::NO_TEXT;

        // The rule goes last and is never cut by the length limit.
        $rule = array_pop($parts);

        return mb_substr(self::join($parts), 0, 2490 - mb_strlen($rule)).' '.$rule;
    }

    /**
     * The prompt of an image made before a piece: the first frame of a clip (where a screen is
     * written by the image model, never by the video model) or the background of a logo_card or
     * a text_card (without any text: the montage lays the logo and the texts over it).
     *
     * @param array{use: string, description: string, baseAssetId?: string|null, baseKind?: string|null, kind?: string} $picture
     * @param array{spec: array<string, mixed>}|null                                                                     $shot
     * @param array{name?: string, colors?: string, fonts?: string}|null                                                 $brand
     */
    public function picturePrompt(array $picture, ?array $shot, ?array $brand): string
    {
        $colors = trim((string) ($brand['colors'] ?? ''));
        $name = trim((string) ($brand['name'] ?? ''));
        $description = self::sentence((string) $picture['description']);
        if ('background' === $picture['use']) {
            $room = 'logo_card' === ($picture['kind'] ?? null) ? 'a logo' : 'a short text';

            return self::join([
                \sprintf('Background image for a vertical 9:16 social media video%s.', '' === $name ? '' : ' of the brand '.$name),
                $description,
                '' === $colors ? '' : 'Colours of the brand: '.$colors.'.',
                \sprintf('Soft and uncluttered, with calm empty space in the centre where %s is laid later.', $room),
                'No text, no letters, no numbers, no logos, no watermark.',
            ]);
        }

        $spec = \is_array($shot['spec'] ?? null) ? $shot['spec'] : [];
        $fonts = array_values(array_filter(array_map('trim', explode(',', (string) ($brand['fonts'] ?? '')))));
        $person = 'person' === ($picture['baseKind'] ?? null);
        $parts = [
            'First frame of a vertical 9:16 video clip, as a photograph.',
            match (true) {
                null === ($picture['baseAssetId'] ?? null) => $description,
                // The photo of the person is edited, so the face is hers.
                $person => 'The person of the given image, with exactly the same face, hair, skin and build, in this scene: '.$description,
                default => 'Change the given image: '.$description,
            },
            self::sentence(self::withoutWriting((string) ($spec['action'] ?? ''), $fonts)),
        ];
        foreach (['framing' => 'Framing', 'lighting' => 'Lighting'] as $field => $label) {
            $value = trim(self::withoutWriting((string) ($spec[$field] ?? ''), $fonts));
            if ('' !== $value) {
                $parts[] = $label.': '.self::sentence($value);
            }
        }
        // Not with the person: the model dressed her in the colours of the brand, and she came out
        // in other clothes in each piece (first probe of video-maker).
        if ('' !== $colors && !$person) {
            $parts[] = \sprintf('Colours of the brand%s: %s.', '' === $name ? '' : ' '.$name, $colors);
        }
        if ($person) {
            $parts[] = 'Same clothes as in the given image.';
        }
        $parts[] = 'Photorealistic, natural light, sharp focus. No watermark.';
        if (1 === preg_match(self::SCREEN, $description)) {
            // Only a screen is written by the image model, and never with the name of a font:
            // the models write that name as one more word.
            $parts[] = 'The screen shows a clean, realistic interface, and every text in it is written exactly as given here, spelled correctly; nothing else is written.';
        } else {
            $parts[] = 'No text, no letters, no numbers, no logos.';
        }

        return self::join($parts);
    }

    /** The size the image generator is asked for a format of the video (multiples of 16). */
    public static function pictureSize(string $format): string
    {
        return match ($format) {
            '16:9' => '1536x864',
            '1:1' => '1024x1024',
            '4:5' => '1024x1280',
            default => '864x1536',
        };
    }

    /**
     * A text of the plan without what speaks of writing: quoted texts, font names and the clauses
     * about texts, letters, fonts or logos (the montage lays them; a video model paints them as
     * invented words).
     *
     * @param list<string> $fonts the fonts of the brand
     */
    public static function withoutWriting(string $text, array $fonts = []): string
    {
        $text = self::withoutQuotesNorFonts($text, $fonts);
        $kept = [];
        foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [] as $sentence) {
            $clauses = array_filter(
                array_map('trim', explode(',', $sentence)),
                static fn (string $c) => '' !== trim($c, ' .;') && 1 !== preg_match(self::WRITTEN, $c),
            );
            if ([] !== $clauses) {
                // The sentence keeps its full stop even when its last clause went away.
                $end = 1 === preg_match('/([.!?;])\s*$/u', $sentence, $m) ? $m[1] : '';
                $kept[] = rtrim(implode(', ', $clauses), ' .!?;').$end;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $kept)) ?? '');
    }

    /**
     * @param list<string> $fonts
     */
    private static function withoutQuotesNorFonts(string $text, array $fonts): string
    {
        $text = (string) preg_replace('/«[^»]*»|"[^"]*"|“[^”]*”|‘[^’]*’/u', '', $text);
        $names = array_filter(array_map('trim', array_merge(self::FONTS, $fonts)), static fn ($f) => mb_strlen($f) > 2);
        if ([] !== $names) {
            $text = (string) preg_replace('/\b('.implode('|', array_map(static fn ($f) => preg_quote($f, '/'), $names)).')\b(\s+(semibold|bold|regular|medium|light|black))?/u', '', $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function sentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ('' === $text) {
            return '';
        }

        return preg_match('/[.!?]$/u', $text) ? $text : $text.'.';
    }

    /**
     * @param list<string> $parts
     */
    private static function join(array $parts): string
    {
        return mb_substr(implode(' ', array_filter($parts, static fn ($p) => '' !== $p)), 0, 2500);
    }
}
