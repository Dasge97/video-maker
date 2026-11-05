<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whose video or image is being made, and so whose keys pay for it: the person logged in on
 * the web, or the owner of the video the worker is working on (runAs). Nothing falls back to
 * keys of the server: an account without a key cannot use that provider.
 */
final class CurrentAccount
{
    /** Provider → what the settings screen says of it. All four are needed to make a video. */
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic', 'for' => 'El agente que prepara la propuesta', 'url' => 'https://console.anthropic.com/settings/keys'],
        'elevenlabs' => ['label' => 'ElevenLabs', 'for' => 'La voz, la música y la transcripción de la referencia', 'url' => 'https://elevenlabs.io/app/settings/api-keys'],
        'fal' => ['label' => 'fal.ai', 'for' => 'Los clips de vídeo y la persona hablando', 'url' => 'https://fal.ai/dashboard/keys'],
        'openai' => ['label' => 'OpenAI', 'for' => 'Las imágenes de arranque y «Crear imagen»', 'url' => 'https://platform.openai.com/api-keys'],
    ];
    /** Optional: an AI Hub of its own transcribes the reference instead of ElevenLabs. */
    public const OPTIONAL = [
        'aihub' => ['label' => 'AI Hub', 'for' => 'Transcribir la referencia con tu propio AI Hub en vez de ElevenLabs'],
    ];

    private ?Account $forced = null;

    public function __construct(
        private readonly Security $security,
        private readonly KeyCipher $cipher,
    ) {
    }

    public function get(): ?Account
    {
        if (null !== $this->forced) {
            return $this->forced;
        }
        $user = $this->security->getUser();

        return $user instanceof Account ? $user : null;
    }

    public function require(): Account
    {
        return $this->get() ?? throw new \LogicException('No hay ninguna cuenta activa.');
    }

    /**
     * Runs $work as the account (the worker, with the owner of a video).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runAs(Account $account, callable $work): mixed
    {
        $before = $this->forced;
        $this->forced = $account;
        try {
            return $work();
        } finally {
            $this->forced = $before;
        }
    }

    /** The key of a provider of the account, or '' when it has none. */
    public function key(string $provider): string
    {
        $encrypted = $this->get()?->data['keys'][$provider] ?? null;

        return \is_string($encrypted) ? ($this->cipher->decrypt($encrypted) ?? '') : '';
    }

    /** The address of the AI Hub of the account, or '' without one. */
    public function aihubUrl(): string
    {
        return (string) ($this->get()?->data['aihubUrl'] ?? '');
    }

    /**
     * @return list<string> the names of the providers whose key the account lacks
     */
    public function missingKeys(?Account $account = null): array
    {
        $keys = ($account ?? $this->get())?->data['keys'] ?? [];
        $missing = [];
        foreach (self::PROVIDERS as $provider => $info) {
            if (!\is_string($keys[$provider] ?? null) || '' === $keys[$provider]) {
                $missing[] = $info['label'];
            }
        }

        return $missing;
    }
}
