<?php

declare(strict_types=1);

namespace App\Account;

use App\Render\VideoFonts;
use Symfony\Component\Uid\Ulid;

/**
 * The saved brands of an account: name, two colours, font, forbidden words and logo, so a new
 * video does not ask for them again. They live in account.json; the logo in brands/<id>.<ext>.
 * Saving a brand with the name of one already saved updates it.
 */
final class Brands
{
    public const MAX = 30;

    public function __construct(private readonly AccountStore $accounts)
    {
    }

    /**
     * @param list<string> $colors          hex colours, #RRGGBB
     * @param list<string> $forbiddenWords
     * @param string|null  $logo            path of the logo to keep, or null to keep the one it had
     *
     * @return array<string, mixed> the brand saved
     */
    public function save(Account $account, string $name, array $colors, string $font, array $forbiddenWords, ?string $logo): array
    {
        $account = $this->accounts->get($account->id()) ?? $account;
        $brands = $account->brands();
        $at = null;
        foreach ($brands as $i => $brand) {
            if (mb_strtolower($brand['name']) === mb_strtolower($name)) {
                $at = $i;
            }
        }
        if (null === $at && \count($brands) >= self::MAX) {
            throw new \RangeException(\sprintf('Ya tienes %d marcas guardadas: borra alguna en Ajustes.', self::MAX));
        }
        $brand = (null === $at ? ['id' => strtolower((string) new Ulid()), 'logo' => null] : $brands[$at]);
        $brand['name'] = $name;
        $brand['colors'] = array_values(array_slice($colors, 0, 2));
        $brand['font'] = VideoFonts::resolve($font) ?? VideoFonts::DEFAULT;
        $brand['forbiddenWords'] = array_values($forbiddenWords);
        $brand['updatedAt'] = date(\DATE_ATOM);
        if (null !== $logo && is_file($logo)) {
            is_dir($account->path('brands')) || mkdir($account->path('brands'), 0775, true);
            $file = 'brands/'.$brand['id'].'.'.strtolower(pathinfo($logo, \PATHINFO_EXTENSION) ?: 'png');
            if (null !== $brand['logo'] && $brand['logo'] !== $file) {
                @unlink($account->path($brand['logo']));
            }
            copy($logo, $account->path($file));
            $brand['logo'] = $file;
        }
        null === $at ? array_unshift($brands, $brand) : $brands[$at] = $brand;
        $account->data['brands'] = array_values($brands);
        $this->accounts->save($account);

        return $brand;
    }

    public function delete(Account $account, string $id): bool
    {
        $account = $this->accounts->get($account->id()) ?? $account;
        $brand = $account->brand($id);
        if (null === $brand) {
            return false;
        }
        if (null !== $brand['logo']) {
            @unlink($account->path($brand['logo']));
        }
        $account->data['brands'] = array_values(array_filter($account->brands(), static fn (array $b) => $b['id'] !== $id));
        $this->accounts->save($account);

        return true;
    }

    /** The logo file of a brand, or null. */
    public function logo(Account $account, string $id): ?string
    {
        $brand = $account->brand($id);
        $file = null === $brand || null === $brand['logo'] ? null : $account->path($brand['logo']);

        return null !== $file && is_file($file) ? $file : null;
    }
}
