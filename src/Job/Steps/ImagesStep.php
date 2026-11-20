<?php

declare(strict_types=1);

namespace App\Job\Steps;

use App\Job\Job;
use App\Job\JobStore;
use App\Job\Step;
use App\Job\StepFailed;
use App\Prompt\PromptBuilder;
use App\Providers\OpenAiImages;

/**
 * The images made before the pieces, with OpenAI: the start image of a talking_person, an
 * image_motion or a generated_clip that asks for one, and the backgrounds of text_card and
 * logo_card. A start image where the person appears is an edit of her photo, so the face is
 * hers. Each image is kept by the hash of what was asked (images/<hash>.jpg) and never paid
 * twice.
 *
 * One that cannot be made (OpenAI refuses it) does not stop the video: the piece goes on the
 * colour of the brand, the clip without start image, or the person speaking from her photo, and
 * the video says so.
 */
final class ImagesStep implements Step
{
    private const SIZE = '9:16';

    public function __construct(
        private readonly OpenAiImages $images,
        private readonly PromptBuilder $prompts,
        private readonly JobStore $store,
    ) {
    }

    public function name(): string
    {
        return 'images';
    }

    public function run(Job $job): bool
    {
        $plan = (array) $job->plan();
        $input = $job->input();
        $brand = ['name' => (string) ($input['brand']['name'] ?? ''), 'colors' => (string) ($input['brand']['colors'] ?? ''), 'fonts' => (string) ($input['brand']['font'] ?? '')];
        $made = [];
        $elements = self::elementsByName($input);
        // A place marked for it is the background of every card: nothing to generate.
        $cardPlace = array_values(array_filter($elements, static fn (array $e) => true === ($e['cards'] ?? false)))[0] ?? null;
        foreach (array_values($plan['pieces']) as $i => $piece) {
            $kind = $piece['kind'];
            $scene = \is_array($piece['scene'] ?? null) ? $piece['scene'] : null;
            $start = trim((string) ($scene['startImage'] ?? ''));
            if (null !== $scene && '' !== $start) {
                $withPerson = 'talking_person' === $kind || true === ($scene['withPerson'] ?? false);
                $shown = array_values(array_filter(array_map(static fn ($n) => $elements[$n] ?? null, $scene['elements'] ?? [])));
                $prompt = $this->prompts->picturePrompt(
                    ['use' => 'start', 'description' => $start, 'baseAssetId' => $withPerson ? 'person' : null, 'baseKind' => $withPerson ? 'person' : null, 'kind' => $kind],
                    ['spec' => ['action' => $scene['action'], 'framing' => $scene['framing'], 'lighting' => $scene['lighting']]],
                    $brand,
                );
                $bases = $withPerson ? [$job->path('input/person.jpg')] : [];
                foreach ($shown as $element) {
                    // Each image after the first is named in the prompt, so the product or the place is that one.
                    $prompt .= \sprintf(' Image %d is %s «%s»: it must appear exactly as in that image.', \count($bases) + 1, 'place' === $element['kind'] ? 'the place' : 'the product', $element['name']);
                    $bases[] = $job->path((string) $element['path']);
                }
                $file = $this->image($job, $i, $prompt, $bases, 'imagen de arranque');
                if (null !== $file) {
                    $made[$i]['start'] = $file;
                }
            }
            if (null !== $cardPlace && \in_array($kind, ['text_card', 'logo_card'], true)) {
                $made[$i]['background'] = (string) $cardPlace['path'];
                $job->data['outputs']['images'] = $made;
                continue;
            }
            $background = match ($kind) {
                'text_card' => $piece['card']['background'] ?? null,
                'logo_card' => $piece['logo']['background'] ?? null,
                default => null,
            };
            if (\is_string($background) && '' !== trim($background)) {
                $prompt = $this->prompts->picturePrompt(['use' => 'background', 'description' => $background, 'kind' => $kind], null, $brand);
                $file = $this->image($job, $i, $prompt, null, 'fondo');
                if (null !== $file) {
                    $made[$i]['background'] = $file;
                }
            }
            // The screen shows each image as soon as it is made.
            $job->data['outputs']['images'] = $made;
            $this->store->save($job);
        }
        $job->data['outputs']['images'] = $made;

        return true;
    }

    /**
     * The products and places of the video by their name.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, array<string, mixed>>
     */
    public static function elementsByName(array $input): array
    {
        $byName = [];
        foreach ($input['elements'] ?? [] as $element) {
            if (\is_string($element['path'] ?? null)) {
                $byName[(string) $element['name']] = $element;
            }
        }

        return $byName;
    }

    /**
     * @param list<string>|string|null $bases the images it starts from: none, one, or several
     */
    private function image(Job $job, int $i, string $prompt, array|string|null $bases, string $what): ?string
    {
        $bases = null === $bases ? [] : (array) $bases;
        $size = PromptBuilder::pictureSize(self::SIZE);
        // Without a base the last part is empty, as before: the images already made keep their name.
        $parts = [] === $bases ? [''] : array_map(static fn (string $b) => (string) hash_file('sha256', $b), $bases);
        $hash = substr(hash('sha256', implode('|', array_merge(['openai', $prompt, $size], $parts))), 0, 24);
        $file = 'images/'.$hash.'.jpg';
        if (is_file($job->path($file))) {
            return $file;
        }
        try {
            $result = match (\count($bases)) {
                0 => $this->images->generate($prompt, $size),
                1 => $this->images->edit($prompt, $size, $bases[0]),
                default => $this->images->editMany($prompt, $size, $bases),
            };
        } catch (StepFailed $e) {
            $job->warn(\sprintf('La %s de la pieza %d no se pudo hacer: %s', $what, $i + 1, $e->getMessage()));
            $job->log(\sprintf('Imagen de la pieza %d fallida: %s', $i + 1, $e->getMessage()));

            return null;
        }
        file_put_contents($job->path($file), $result['bytes']);
        $job->addCost(\sprintf('Pieza %d: %s', $i + 1, $what), $result['usd']);
        $job->log(\sprintf('Imagen de la pieza %d hecha (%s $)', $i + 1, $result['usd']));

        return $file;
    }
}
