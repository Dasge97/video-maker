<?php

declare(strict_types=1);

namespace App\Render;

/**
 * The engine that composes the final video by layers.
 * ffmpeg today (free); interchangeable for a motor with richer animations later (Remotion…).
 *
 * The job is a plain description of the montage:
 *
 *   width, height                 size of the video
 *   pieces                        in order, each {kind: clip|image|card|product|logo, duration,
 *                                 source (clip or image file; in card and logo, an optional
 *                                 generated background), motion (zoom_in, pan_left…),
 *                                 background (#RRGGBB), logo (image file, kind logo), fadeIn, fadeOut}
 *   ass                           file with every text on screen and the subtitles, or null
 *   fontsDir                      folder of the fonts of the texts (VideoFonts), or null
 *   logo                          {path, start, end, windows?: list of {start, end}} or null
 *   voice, music                  audio files (the voice track already placed on the timeline)
 *   musicVolume                   volume of the music (it goes lower still while there is voice)
 *   out                           MP4 to write
 */
interface Renderer
{
    /**
     * @param array<string, mixed> $job
     *
     * @return array{duration: float, hasAudio: bool}
     */
    public function render(array $job): array;
}
