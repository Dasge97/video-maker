<?php

declare(strict_types=1);

namespace App\Job;

use App\Agent\PlanChecks;
use App\Providers\OpenAiImages;
use App\Providers\VideoCatalog;
use App\Sound\SoundCatalog;

/**
 * What a plan will cost to generate, line by line, with the catalogs. The duration of each piece
 * is the one its voice will give it (PlanChecks::estimatedSeconds); what is already on disk is
 * not subtracted: it is the price of generating it from scratch.
 */
final class Estimate
{
    public function __construct(
        private readonly VideoCatalog $videos,
        private readonly SoundCatalog $sound,
    ) {
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array{usd: float, lines: list<array{what: string, usd: float, piece: int|null}>}
     */
    public function of(array $plan, ?string $videoModel): array
    {
        $model = $this->videos->get($videoModel);
        $lines = [];
        $characters = 0;
        $total = 0.0;
        foreach (array_values($plan['pieces']) as $j => $piece) {
            $n = $j + 1;
            $seconds = PlanChecks::estimatedSeconds($piece);
            $total += $seconds;
            $characters += mb_strlen(trim((string) ($piece['voice'] ?? '')));
            $image = match ($piece['kind']) {
                'talking_person', 'image_motion' => true,
                'generated_clip' => '' !== trim((string) ($piece['scene']['startImage'] ?? '')),
                'text_card' => null !== ($piece['card']['background'] ?? null),
                'logo_card' => null !== ($piece['logo']['background'] ?? null),
                default => false,
            };
            if ($image) {
                $what = \in_array($piece['kind'], ['text_card', 'logo_card'], true) ? 'fondo' : 'imagen de arranque';
                $lines[] = ['what' => \sprintf('Pieza %d: %s', $n, $what), 'usd' => OpenAiImages::ESTIMATED_USD, 'piece' => $j];
            }
            if ('talking_person' === $piece['kind']) {
                $lines[] = ['what' => \sprintf('Pieza %d: persona hablando (Kling AI Avatar, %s s)', $n, self::n($seconds)), 'usd' => round($this->sound->avatarUsd($seconds), 4), 'piece' => $j];
            } elseif ('generated_clip' === $piece['kind']) {
                $billed = VideoCatalog::billedSeconds($model, $seconds);
                $lines[] = ['what' => \sprintf('Pieza %d: clip (%s, %d s)', $n, $model['model'], $billed), 'usd' => round(VideoCatalog::clipUsd($model, $billed), 4), 'piece' => $j];
            }
        }
        if ($characters > 0) {
            $lines[] = ['what' => \sprintf('Voz (%d caracteres)', $characters), 'usd' => round($this->sound->voiceUsd($characters), 4), 'piece' => null];
        }
        if (null !== ($plan['music'] ?? null)) {
            $lines[] = ['what' => 'Música', 'usd' => round($this->sound->musicUsd($total + SoundCatalog::MUSIC_TAIL_SECONDS), 4), 'piece' => null];
        }
        return ['usd' => round(array_sum(array_column($lines, 'usd')), 2), 'lines' => $lines];
    }

    private static function n(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }
}
