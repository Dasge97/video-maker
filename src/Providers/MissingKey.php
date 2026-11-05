<?php

declare(strict_types=1);

namespace App\Providers;

use App\Job\StepFailed;

/**
 * The account has no key of a provider. Nothing was sent, so nothing was charged.
 */
final class MissingKey
{
    public static function check(string $value, string $provider): void
    {
        if ('' === trim($value)) {
            throw new StepFailed(\sprintf('Falta tu clave de %s: añádela en Ajustes y pulsa «Reintentar».', $provider));
        }
    }
}
