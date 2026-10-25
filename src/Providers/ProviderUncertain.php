<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * The call was sent but no result came back (timeout, cut connection): it may have been processed
 * and consumed. It is recorded as uncertain and never repeated silently.
 */
final class ProviderUncertain extends \RuntimeException
{
}
