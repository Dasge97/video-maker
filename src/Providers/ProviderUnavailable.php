<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * The call did not reach the provider (connection refused, service busy): nothing was consumed
 * and a technical retry is safe.
 */
final class ProviderUnavailable extends \RuntimeException
{
}
