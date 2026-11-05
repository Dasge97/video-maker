<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One person who uses the program: var/users/<id>/account.json. Their provider keys are kept
 * there encrypted (KeyCipher), with their spending limit and their saved brands. Each person
 * pays their own videos: without their keys nothing is generated.
 */
final class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param array<string, mixed> $data the contents of account.json
     */
    public function __construct(
        public readonly string $dir,
        public array $data,
    ) {
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function email(): string
    {
        return (string) $this->data['email'];
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? '') ?: strstr($this->email(), '@', true) ?: $this->email();
    }

    /** Dollars a month, or null without a limit. */
    public function monthlyLimit(): ?float
    {
        $limit = $this->data['monthlyLimitUsd'] ?? null;

        return null === $limit ? null : (float) $limit;
    }

    /**
     * @return list<array<string, mixed>> {id, name, colors, font, forbiddenWords, logo, updatedAt}
     */
    public function brands(): array
    {
        return array_values($this->data['brands'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function brand(string $id): ?array
    {
        foreach ($this->brands() as $brand) {
            if ($brand['id'] === $id) {
                return $brand;
            }
        }

        return null;
    }

    public function path(string $relative): string
    {
        return $this->dir.'/'.$relative;
    }

    public function getUserIdentifier(): string
    {
        return $this->email();
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->data['passwordHash'] ?? null;
    }

    public function eraseCredentials(): void
    {
    }

    /** Only what the session needs: the account is read again from its file on each request. */
    public function __serialize(): array
    {
        // Never the keys in the session file.
        return ['dir' => $this->dir, 'data' => ['id' => $this->id(), 'email' => $this->email(), 'passwordHash' => $this->getPassword()]];
    }

    public function __unserialize(array $data): void
    {
        $this->dir = $data['dir'];
        $this->data = $data['data'];
    }
}
