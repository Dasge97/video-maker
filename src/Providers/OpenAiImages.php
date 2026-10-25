<?php

declare(strict_types=1);

namespace App\Providers;

use App\Job\StepFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The image API of OpenAI: /v1/images/generations, or /v1/images/edits when it starts from an
 * image (the photo of the person, so the face is hers). The image comes back in base64 inside
 * the response; there is no link to download. OpenAI warns that an image may take up to two
 * minutes.
 */
final class OpenAiImages
{
    /** Rough price of one image, for the estimate: 0,01 to 0,03 $ in the first real runs (medium quality). */
    public const ESTIMATED_USD = 0.025;
    /** Dollars per million tokens: text input, image input, image output. */
    private const PRICES = [5.0, 8.0, 30.0];
    private const WAIT = 180.0;
    private const QUALITY = 'medium';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(OPENAI_API_KEY)%')]
        #[\SensitiveParameter]
        private readonly string $apiKey,
        #[Autowire('%env(OPENAI_IMAGE_MODEL)%')]
        private readonly string $model,
    ) {
    }

    /**
     * A new image from a prompt.
     *
     * @return array{bytes: string, usd: float, extension: string}
     */
    public function generate(string $prompt, string $size, bool $transparent = false): array
    {
        return $this->call($prompt, $size, [], $transparent);
    }

    /**
     * An image made from another one.
     *
     * @return array{bytes: string, usd: float, extension: string}
     */
    public function edit(string $prompt, string $size, string $imagePath, bool $transparent = false): array
    {
        return $this->call($prompt, $size, [$imagePath], $transparent);
    }

    /**
     * @param list<string> $images
     *
     * @return array{bytes: string, usd: float, extension: string}
     */
    private function call(string $prompt, string $size, array $images, bool $transparent): array
    {
        MissingKey::check($this->apiKey, 'OPENAI_API_KEY', 'OpenAI');
        // PNG keeps the transparency of a logo; JPEG weighs much less for the rest.
        $fields = [
            'model' => $this->model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => self::QUALITY,
            'output_format' => $transparent ? 'png' : 'jpeg',
            'n' => 1,
        ] + ($transparent ? ['background' => 'transparent'] : ['output_compression' => 90]);
        $options = ['auth_bearer' => $this->apiKey, 'timeout' => self::WAIT, 'max_duration' => self::WAIT];
        if ([] !== $images) {
            // Editing is another route, in multipart: the images travel as files.
            $parts = array_map('strval', $fields);
            foreach ($images as $i => $path) {
                $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
                $parts[] = ['image[]' => new DataPart((string) file_get_contents($path), \sprintf('image-%d.%s', $i + 1, 'image/png' === $mime ? 'png' : ('image/webp' === $mime ? 'webp' : 'jpg')), $mime)];
            }
            $form = new FormDataPart($parts);
            $route = '/v1/images/edits';
            $options += ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()];
        } else {
            $route = '/v1/images/generations';
            $options['json'] = $fields;
        }

        try {
            $response = $this->http->request('POST', 'https://api.openai.com'.$route, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface) {
            throw new StepFailed('No se ha podido conectar con OpenAI, o ha tardado demasiado. Prueba otra vez.');
        } catch (\JsonException) {
            throw new StepFailed('OpenAI ha devuelto una respuesta que no se entiende.');
        }
        if ($status >= 400) {
            $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
            throw new StepFailed(match (true) {
                401 === $status, 403 === $status => 'La clave de OpenAI de .env.local no es válida o no tiene permiso.',
                'moderation_blocked' === ($error['code'] ?? null) => 'OpenAI no ha querido generar esta imagen por su política de contenido. Cambia la descripción.',
                429 === $status => 'OpenAI ha puesto un límite de peticiones o la cuenta no tiene saldo. Prueba otra vez en un rato.',
                default => \sprintf('OpenAI ha devuelto un error (%d): %s', $status, mb_strimwidth((string) ($error['message'] ?? 'sin detalle'), 0, 200, '…')),
            });
        }
        $bytes = base64_decode((string) ($data['data'][0]['b64_json'] ?? ''), true);
        if (false === $bytes || '' === $bytes) {
            throw new StepFailed('OpenAI ha terminado sin devolver ninguna imagen.');
        }
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $details = \is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        [$text, $image, $out] = self::PRICES;
        $usd = ((int) ($details['text_tokens'] ?? $usage['input_tokens'] ?? 0) * $text + (int) ($details['image_tokens'] ?? 0) * $image + (int) ($usage['output_tokens'] ?? 0) * $out) / 1_000_000;

        return ['bytes' => $bytes, 'usd' => round($usd, 4), 'extension' => $transparent ? 'png' : 'jpg'];
    }
}
