<?php

declare(strict_types=1);

namespace App\Reference;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * The photo and the logo the person gives, made ready once: turned as the camera says (EXIF),
 * reduced, and in one format. Also the copies the agent sees.
 */
final class Images
{
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
    public const PERSON_SIDE = 1536;
    public const LOGO_SIDE = 1024;

    /** The photo of the person as person.jpg, at most PERSON_SIDE px, on white. */
    public static function person(string $source, string $out): void
    {
        $image = self::open($source, 'La foto de la persona')->fillTransparentAreas('ffffff');
        $image->scaleDown(self::PERSON_SIDE, self::PERSON_SIDE);
        file_put_contents($out, (string) $image->encode(new JpegEncoder(90)));
    }

    /** The logo as logo.png, at most LOGO_SIDE px, keeping its transparency. */
    public static function logo(string $source, string $out): void
    {
        $image = self::open($source, 'El logo');
        $image->scaleDown(self::LOGO_SIDE, self::LOGO_SIDE);
        file_put_contents($out, (string) $image->encode(new PngEncoder()));
    }

    /** A JPEG copy for the agent, its long side at most $maxSide, flattened on white. */
    public static function jpegBase64(string $path, int $maxSide): string
    {
        $image = ImageManager::usingDriver(Driver::class)->decodePath($path)->fillTransparentAreas('ffffff');
        $image->scaleDown($maxSide, $maxSide);

        return base64_encode((string) $image->encode(new JpegEncoder(85)));
    }

    /**
     * @return array{0: int, 1: int} width and height
     */
    public static function size(string $path): array
    {
        $size = @getimagesize($path);

        return false === $size ? [0, 0] : [(int) $size[0], (int) $size[1]];
    }

    private static function open(string $path, string $what): ImageInterface
    {
        $mime = is_file($path) ? (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($path) : '';
        if (!\in_array($mime, self::ACCEPTED, true)) {
            throw new InputRejected($what.' debe ser una imagen JPEG, PNG o WebP.');
        }
        try {
            return ImageManager::usingDriver(Driver::class)->decodePath($path);
        } catch (\Throwable) {
            throw new InputRejected($what.' no se puede abrir: puede que el fichero esté dañado.');
        }
    }
}
