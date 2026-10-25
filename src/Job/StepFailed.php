<?php

declare(strict_types=1);

namespace App\Job;

/**
 * A step cannot go on. The message is in Spanish and says what failed and what to do.
 */
final class StepFailed extends \RuntimeException
{
}
