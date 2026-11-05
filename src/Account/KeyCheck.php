<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tries a key with a free request of its provider: a list of models or voices, or the state of a
 * clip that does not exist. Only a 401 or a 403 says the key is wrong.
 */
final class KeyCheck
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * @return array{ok: bool|null, message: string} ok null: it could not be checked
     */
    public function check(string $provider, #[\SensitiveParameter] string $key): array
    {
        [$url, $options] = match ($provider) {
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=1', ['headers' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01']]],
            'openai' => ['https://api.openai.com/v1/models', ['auth_bearer' => $key]],
            'elevenlabs' => ['https://api.elevenlabs.io/v1/models', ['headers' => ['xi-api-key' => $key]]],
            'fal' => ['https://queue.fal.run/fal-ai/kling-video/requests/00000000-0000-0000-0000-000000000000/status', ['headers' => ['Authorization' => 'Key '.$key]]],
            default => [null, []],
        };
        if (null === $url) {
            return ['ok' => null, 'message' => 'Esta clave no se puede comprobar.'];
        }
        try {
            $status = $this->http->request('GET', $url, $options + ['timeout' => 20])->getStatusCode();
        } catch (\Throwable) {
            return ['ok' => null, 'message' => 'No se ha podido conectar para comprobarla.'];
        }
        if (401 === $status || 403 === $status) {
            return ['ok' => false, 'message' => 'La clave no es válida o no tiene permiso.'];
        }
        if ($status >= 500 || 429 === $status) {
            return ['ok' => null, 'message' => 'El servicio no ha contestado bien ('.$status.'): prueba en un rato.'];
        }

        return ['ok' => true, 'message' => 'La clave funciona.'];
    }
}
