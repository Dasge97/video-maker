<?php

declare(strict_types=1);

namespace App\Account;

use App\Job\JobStore;
use App\Library\ImageLibrary;

/**
 * What an account has spent this month, from the costs written in its videos and its images,
 * and its optional limit: nothing new is paid once the month reaches it.
 */
final class Spending
{
    public function __construct(
        private readonly JobStore $jobs,
        private readonly ImageLibrary $library,
    ) {
    }

    /**
     * @return list<\App\Job\Job>
     */
    public function jobsOf(Account $account): array
    {
        return $this->jobs->all($account->id());
    }

    /** Dollars spent in the calendar month of $now (the current one by default). */
    public function month(Account $account, ?\DateTimeInterface $now = null): float
    {
        $month = ($now ?? new \DateTimeImmutable())->format('Y-m');
        $total = 0.0;
        foreach ($this->jobs->all($account->id()) as $job) {
            foreach ($job->data['costs'] ?? [] as $cost) {
                if (str_starts_with((string) ($cost['at'] ?? ''), $month)) {
                    $total += (float) $cost['usd'];
                }
            }
        }
        foreach (glob($this->library->dirOf($account->id()).'/*.json') ?: [] as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            if (\is_array($entry) && str_starts_with((string) ($entry['createdAt'] ?? ''), $month)) {
                $total += (float) ($entry['usd'] ?? 0);
            }
        }

        return round($total, 4);
    }

    /**
     * Why $more dollars cannot be spent now, or null when they can.
     */
    public function refusal(Account $account, float $more = 0.0): ?string
    {
        $limit = $account->monthlyLimit();
        if (null === $limit) {
            return null;
        }
        $spent = $this->month($account);
        if ($spent >= $limit) {
            return \sprintf('Has llegado a tu límite de gasto de este mes (%s de %s). Súbelo en Ajustes para seguir.', self::money($spent), self::money($limit));
        }
        if ($spent + $more > $limit) {
            return \sprintf('Esto cuesta unos %s y solo te quedan %s de tu límite de este mes. Súbelo en Ajustes para seguir.', self::money($more), self::money($limit - $spent));
        }

        return null;
    }

    public static function money(float $usd): string
    {
        return number_format($usd, 2, ',', '.').' $';
    }
}
