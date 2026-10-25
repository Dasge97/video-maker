<?php

declare(strict_types=1);

namespace App\Library;

use App\Providers\OpenAiImages;
use App\Reference\Images;
use App\Reference\InputRejected;

/**
 * The images of «Crear imagen», in var/library: <hash>.<ext> and <hash>.json beside it (prompt,
 * kind, model, cost, date). The hash is the one of the kind, the prompt and the image it starts
 * from, so the same request is never paid twice. They can be used as the photo of the person
 * or the logo of a video.
 */
final class ImageLibrary
{
    /** Kind → size asked and whether the background is transparent. */
    public const KINDS = [
        'person' => ['size' => '1024x1536', 'transparent' => false, 'label' => 'Persona'],
        'logo' => ['size' => '1024x1024', 'transparent' => true, 'label' => 'Logo'],
        'other' => ['size' => '1024x1024', 'transparent' => false, 'label' => 'Otra'],
    ];
    /** What each kind adds to the prompt of the person. */
    private const HINTS = [
        'person' => 'Retrato fotográfico realista y natural de una sola persona, de frente o de tres cuartos, de medio cuerpo, mirando a cámara, con luz suave. Sin textos.',
        'logo' => 'Logotipo limpio y simple, plano, centrado, sobre fondo transparente, legible a tamaño pequeño.',
        'other' => '',
    ];

    public function __construct(
        private readonly OpenAiImages $images,
        private readonly string $libraryDir,
    ) {
    }

    /**
     * @return array<string, mixed> the entry of the image made (or the one already there)
     */
    public function create(string $kind, string $prompt, ?string $sourcePath = null): array
    {
        // A client that does not send UTF-8 (a Windows console) would break the JSON to OpenAI.
        $prompt = trim(mb_check_encoding($prompt, 'UTF-8') ? $prompt : mb_convert_encoding($prompt, 'UTF-8', 'Windows-1252'));
        if (!isset(self::KINDS[$kind])) {
            throw new InputRejected('Elige si es una persona, un logo u otra imagen.');
        }
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 2000) {
            throw new InputRejected('Describe la imagen en entre 3 y 2.000 caracteres.');
        }
        $source = null;
        if (null !== $sourcePath) {
            $source = $this->libraryDir.'/source-'.bin2hex(random_bytes(6)).'.png';
            is_dir($this->libraryDir) || mkdir($this->libraryDir, 0775, true);
            Images::logo($sourcePath, $source);
        }
        try {
            $hash = substr(hash('sha256', implode('|', [$kind, $prompt, null === $source ? '' : (string) hash_file('sha256', $source)])), 0, 24);
            $existing = $this->get($hash);
            if (null !== $existing) {
                return $existing;
            }
            $kindInfo = self::KINDS[$kind];
            $full = trim(self::HINTS[$kind].' '.$prompt);
            $made = null === $source
                ? $this->images->generate($full, $kindInfo['size'], $kindInfo['transparent'])
                : $this->images->edit($full, $kindInfo['size'], $source, $kindInfo['transparent']);
        } finally {
            null === $source || @unlink($source);
        }
        is_dir($this->libraryDir) || mkdir($this->libraryDir, 0775, true);
        $file = $hash.'.'.$made['extension'];
        file_put_contents($this->libraryDir.'/'.$file, $made['bytes']);
        $entry = ['hash' => $hash, 'file' => $file, 'kind' => $kind, 'prompt' => $prompt, 'edited' => null !== $sourcePath, 'usd' => $made['usd'], 'createdAt' => date(\DATE_ATOM)];
        file_put_contents($this->libraryDir.'/'.$hash.'.json', json_encode($entry, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return $entry;
    }

    /**
     * @return list<array<string, mixed>> the newest first
     */
    public function all(?string $kind = null): array
    {
        $entries = [];
        foreach (glob($this->libraryDir.'/*.json') ?: [] as $json) {
            $entry = json_decode((string) file_get_contents($json), true);
            if (\is_array($entry) && is_file($this->libraryDir.'/'.$entry['file']) && (null === $kind || $kind === $entry['kind'])) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (array $a, array $b) => strcmp($b['createdAt'], $a['createdAt']));

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $hash): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{24}$/', $hash) || !is_file($this->libraryDir.'/'.$hash.'.json')) {
            return null;
        }
        $entry = json_decode((string) file_get_contents($this->libraryDir.'/'.$hash.'.json'), true);

        return \is_array($entry) && is_file($this->libraryDir.'/'.$entry['file']) ? $entry : null;
    }

    public function path(array $entry): string
    {
        return $this->libraryDir.'/'.$entry['file'];
    }

    public function delete(string $hash): bool
    {
        $entry = $this->get($hash);
        if (null === $entry) {
            return false;
        }
        @unlink($this->path($entry));
        @unlink($this->libraryDir.'/'.$hash.'.json');

        return true;
    }
}
