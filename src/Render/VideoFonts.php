<?php

declare(strict_types=1);

namespace App\Render;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The fonts of the texts of the montage travel with the program, in resources/fonts, and libass
 * reads them from there (ass=…:fontsdir=…), so a video looks the same on any machine; before,
 * the fonts were the ones installed in the computer that made it (Oswald came out in Arial).
 * Their licences are in resources/fonts/licenses.
 *
 * Only fonts with a free licence (SIL Open Font License, Roboto with Apache 2.0), in their bold
 * weight, the one every style of AssBuilder uses. A font of a brand that is not here goes in the
 * default one, and the version says so.
 */
final class VideoFonts
{
    /** Families in the folder (the name libass matches) and other names a brand may write. */
    public const FAMILIES = [
        'Poppins' => [],
        'Montserrat' => [],
        'Inter' => [],
        'Roboto' => [],
        'Open Sans' => ['OpenSans'],
        'Lato' => [],
        'Oswald' => [],
        'Source Sans 3' => ['Source Sans', 'Source Sans Pro', 'SourceSans3'],
    ];
    public const DEFAULT = 'Poppins';

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/fonts')]
        private readonly string $dir,
    ) {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * The family of the folder a brand asks for («montserrat bold» → Montserrat), or null when it
     * is not here.
     */
    public static function resolve(string $wanted): ?string
    {
        $key = self::key((string) preg_replace('/\s+(thin|extralight|light|regular|book|medium|semibold|bold|extrabold|black|italic)\b/iu', '', $wanted));
        foreach (self::FAMILIES as $family => $aliases) {
            foreach (array_merge([$family], $aliases) as $name) {
                if (self::key($name) === $key) {
                    return $family;
                }
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
    }
}
