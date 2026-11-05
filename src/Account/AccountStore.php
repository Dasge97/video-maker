<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The accounts in var/users/<id>/account.json, written like job.json (a temporary file renamed
 * over it). Also the user provider of the login: there are few accounts, so finding one by
 * its email reads them all.
 *
 * @implements UserProviderInterface<Account>
 */
final class AccountStore implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly string $usersDir)
    {
    }

    public static function normalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function create(string $email, string $name): Account
    {
        $id = strtolower((string) new Ulid());
        mkdir($this->usersDir.'/'.$id.'/brands', 0775, true);
        $account = new Account($this->usersDir.'/'.$id, [
            'id' => $id,
            'email' => self::normalEmail($email),
            'name' => trim($name),
            'passwordHash' => null,
            'createdAt' => date(\DATE_ATOM),
            'keys' => [],
            'monthlyLimitUsd' => null,
            'brands' => [],
        ]);
        $this->save($account);

        return $account;
    }

    public function get(string $id): ?Account
    {
        if (1 !== preg_match('/^[0-9a-z]{26}$/', $id)) {
            return null;
        }
        $file = $this->usersDir.'/'.$id.'/account.json';
        if (!is_file($file)) {
            return null;
        }

        return new Account(\dirname($file), json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function byEmail(string $email): ?Account
    {
        $email = self::normalEmail($email);
        foreach ($this->all() as $account) {
            if ($account->email() === $email) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return list<Account>
     */
    public function all(): array
    {
        $accounts = [];
        foreach (glob($this->usersDir.'/*/account.json') ?: [] as $file) {
            $account = $this->get(basename(\dirname($file)));
            if (null !== $account) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    public function save(Account $account): void
    {
        $file = $account->path('account.json');
        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, json_encode($account->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        for ($try = 0; !@rename($temp, $file); ++$try) {
            if ($try >= 20) {
                @unlink($temp);
                throw new \RuntimeException('No se ha podido guardar la cuenta.');
            }
            usleep(50_000);
        }
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->byEmail($identifier) ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Account) {
            throw new UnsupportedUserException();
        }

        return $this->get($user->id()) ?? throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return Account::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof Account) {
            $account = $this->get($user->id()) ?? $user;
            $account->data['passwordHash'] = $newHashedPassword;
            $this->save($account);
        }
    }
}
