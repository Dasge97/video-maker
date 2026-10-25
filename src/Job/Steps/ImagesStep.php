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
        foreach (array_values($plan['pieces']) as $i => $piece) {
            $kind = $piece['kind'];
            $scene = \is_array($piece['scene'] ?? null) ? $piece['scene'] : null;
            $start = trim((string) ($scene['startImage'] ?? ''));
            if (null !== $scene && '' !== $start) {
                $withPerson = 'talking_person' === $kind || true === ($scene['withPerson'] ?? false);
                $prompt = $this->prompts->picturePrompt(
                    ['use' => 'start', 'description' => $start, 'baseAssetId' => $withPerson ? 'person' : null, 'baseKind' => $withPerson ? 'person' : null, 'kind' => $kind],
                    ['spec' => ['action' => $scene['action'], 'framing' => $scene['framing'], 'lighting' => $scene['lighting']]],
                    $brand,
                );
                $file = $this->image($job, $i, $prompt, $withPerson ? $job->path('input/person.jpg') : null, 'imagen de arranque');
                if (null !== $file) {
                    $made[$i]['start'] = $file;
                }
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

    private function image(Job $job, int $i, string $prompt, ?string $base, string $what): ?string
    {
        $size = PromptBuilder::pictureSize(self::SIZE);
        $hash = substr(hash('sha256', implode('|', ['openai', $prompt, $size, null === $base ? '' : (string) hash_file('sha256', $base)])), 0, 24);
        $file = 'images/'.$hash.'.jpg';
        if (is_file($job->path($file))) {
            return $file;
        }
        try {
            $result = null === $base ? $this->images->generate($prompt, $size) : $this->images->edit($prompt, $size, $base);
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
