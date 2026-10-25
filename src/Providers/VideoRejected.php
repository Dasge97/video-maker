<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * The provider refused the request (invalid input, wrong key, unknown model): nothing ran and
 * nothing was charged. The message is shown to the person.
 */
final class VideoRejected extends \RuntimeException
{
}
