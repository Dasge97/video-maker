<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the provider keys of the accounts with libsodium (secretbox) and the secret of the
 * server, APP_KEYS_SECRET: 32 random bytes in base64, in .env.local, never versioned. Without
 * that secret the saved keys cannot be read again, and each person must enter them anew.
 */
final class KeyCipher
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[Autowire('%env(default::APP_KEYS_SECRET)%')]
        #[\SensitiveParameter]
        private readonly ?string $secret,
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function decrypt(string $encrypted): ?string
    {
        if (!str_starts_with($encrypted, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($encrypted, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());

        return false === $plain ? null : $plain;
    }

    private function key(): string
    {
        $key = base64_decode((string) $this->secret, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException('Falta APP_KEYS_SECRET en .env.local (32 bytes en base64): sin ella no se pueden guardar ni leer las claves de las cuentas.');
        }

        return $key;
    }
}
