<?php

declare(strict_types=1);

namespace App\Providers;

use App\Job\StepFailed;

/**
 * A provider key is not in .env.local. Nothing was sent, so nothing was charged.
 */
final class MissingKey
{
    public static function check(string $value, string $variable, string $provider): void
    {
        if ('' === trim($value)) {
            throw new StepFailed(\sprintf('Falta la clave de %s: añade %s a .env.local y reinicia el worker.', $provider, $variable));
        }
    }
}
