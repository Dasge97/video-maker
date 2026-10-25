<?php

declare(strict_types=1);

namespace App\Sound;

/**
 * The voice is one continuous track over the pieces.
 *
 * 1. The parts of the voice of each speaker are joined in order and read in one call
 *    (speakerTexts), so a sentence that goes on over a text card keeps its intonation.
 * 2. With the time of every character, the cut between two parts of the same speaker falls in
 *    the middle of the silence between them. A piece lasts as long as its part of the voice
 *    (the pieces adjust to the voice); a piece without voice keeps its planned seconds.
 *    A piece whose part ends a sentence may keep part of its planned seconds as a pause
 *    (up to HOLD_SECONDS more): with little voice the video no longer comes out much shorter
 *    than asked, and a sentence that goes on over the next piece is never cut by a silence.
 * 3. Every piece knows which slice of which speaker's audio sounds on it: the avatar of a
 *    talking_person piece receives only that slice, and the subtitles come from the same times,
 *    so they are never cut between pieces.
 *
 * The first word used to be cut. A video whose first piece has
 * voice starts with LEAD_SECONDS of silence (the «lead» of its slice), and every cut falls at
 * least PRE_ROLL_SECONDS before the next word (the times of ElevenLabs come a little late), but
 * never before the end of the previous one.
 *
 * Pure: no files, no providers.
 */
final class VoiceTimeline
{
    /** A piece with voice is never shorter than this (the rest is silence). */
    public const MIN_VOICED_SECONDS = 1.0;
    public const MIN_SECONDS = 1.0;
    /** The longest pause after a sentence that ends on a piece shorter than planned. */
    public const HOLD_SECONDS = 2.0;
    /** Silence before the voice when the video starts speaking. */
    public const LEAD_SECONDS = 0.3;
    /** A cut between two parts falls at least this long before the next word. */
    public const PRE_ROLL_SECONDS = 0.15;

    /**
     * The whole text of each speaker and where each piece's part is in it.
     *
     * @param list<array{voice: array{speaker: string, text: string}|null}> $pieces
     *
     * @return array<string, array{text: string, parts: array<int, array{0: int, 1: int}>}> by speaker: text and [offset, length] of each piece index
     */
    public static function speakerTexts(array $pieces): array
    {
        $speakers = [];
        foreach ($pieces as $i => $piece) {
            $voice = $piece['voice'] ?? null;
            if (null === $voice || '' === trim($voice['text'])) {
                continue;
            }
            $speaker = $voice['speaker'];
            $text = trim($voice['text']);
            $speakers[$speaker] ??= ['text' => '', 'parts' => []];
            if ('' !== $speakers[$speaker]['text']) {
                $speakers[$speaker]['text'] .= ' ';
            }
            $speakers[$speaker]['parts'][$i] = [mb_strlen($speakers[$speaker]['text']), mb_strlen($text)];
            $speakers[$speaker]['text'] .= $text;
        }

        return $speakers;
    }

    /**
     * @param list<array{key: string, plannedSeconds: float, voice: array{speaker: string, text: string}|null}> $pieces
     * @param array<string, array{characters: list<string>, starts: list<float>, ends: list<float>, duration: float}> $speeches by speaker
     *
     * @return array{pieces: list<array{key: string, startSeconds: float, durationSeconds: float, voice: array{speaker: string, text: string, from: float, to: float, lead?: float}|null}>, words: list<array{text: string, start: float, end: float, piece: int}>, totalSeconds: float}
     */
    public static function build(array $pieces, array $speeches): array
    {
        $texts = self::speakerTexts($pieces);
        $slices = [];
        $words = [];
        foreach ($texts as $speaker => $info) {
            $speech = $speeches[$speaker] ?? null;
            if (null === $speech) {
                continue;
            }
            $times = self::charTimes($info['text'], $speech);
            $indexes = array_keys($info['parts']);
            foreach ($indexes as $n => $i) {
                [$offset, $length] = $info['parts'][$i];
                $from = 0 === $n ? 0.0 : self::cut($times, $info['parts'][$indexes[$n - 1]], [$offset, $length]);
                $to = $n === \count($indexes) - 1 ? max($speech['duration'], $times[$offset + $length - 1][1] ?? 0.0)
                    : self::cut($times, [$offset, $length], $info['parts'][$indexes[$n + 1]]);
                $slices[$i] = ['speaker' => $speaker, 'text' => mb_substr($info['text'], $offset, $length), 'from' => round($from, 3), 'to' => round(max($from, $to), 3)];
                $words[$i] = self::words($info['text'], $offset, $length, $times);
            }
        }

        $out = [];
        $allWords = [];
        $start = 0.0;
        foreach ($pieces as $i => $piece) {
            $slice = $slices[$i] ?? null;
            // The video starts speaking: a short silence first, or its first word is cut.
            $lead = null !== $slice && 0 === $i ? self::LEAD_SECONDS : 0.0;
            if ($lead > 0) {
                $slice['lead'] = $lead;
            }
            $duration = null === $slice
                ? max(self::MIN_SECONDS, (float) $piece['plannedSeconds'])
                : max(self::MIN_VOICED_SECONDS, $lead + $slice['to'] - $slice['from']);
            if (null !== $slice && 1 === preg_match('/[.!?…][»"”)\']*\s*$/u', $slice['text'])) {
                $duration = max($duration, min((float) $piece['plannedSeconds'], $duration + self::HOLD_SECONDS));
            }

            $duration = round($duration, 3);
            foreach ($words[$i] ?? [] as $word) {
                $allWords[] = [
                    'text' => $word['text'],
                    'start' => round($start + $lead + max(0.0, $word['start'] - $slice['from']), 3),
                    'end' => round(min($start + $duration, $start + $lead + max(0.0, $word['end'] - $slice['from'])), 3),
                    'piece' => $i,
                ];
            }
            $out[] = ['key' => $piece['key'], 'startSeconds' => round($start, 3), 'durationSeconds' => $duration, 'voice' => $slice];
            $start += $duration;
        }

        return ['pieces' => $out, 'words' => $allWords, 'totalSeconds' => round($start, 3)];
    }

    /**
     * Start and end of every character of the text, from the alignment returned for it. When the
     * provider counted characters differently, positions are mapped proportionally.
     *
     * @param array{characters: list<string>, starts: list<float>, ends: list<float>, duration: float} $speech
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function charTimes(string $text, array $speech): array
    {
        $length = mb_strlen($text);
        $count = min(\count($speech['starts']), \count($speech['ends']));
        $times = [];
        for ($c = 0; $c < $length; ++$c) {
            if (0 === $count) {
                $times[] = [0.0, 0.0];
                continue;
            }
            $k = $count === $length ? $c : min($count - 1, (int) floor($c * $count / max(1, $length)));
            $times[] = [(float) $speech['starts'][$k], (float) $speech['ends'][$k]];
        }

        return $times;
    }

    /**
     * The moment between two consecutive parts: the middle of the silence between the last
     * spoken character of the first and the first spoken character of the second, and at least
     * PRE_ROLL_SECONDS before the second (never before the end of the first).
     *
     * @param list<array{0: float, 1: float}> $times
     * @param array{0: int, 1: int}           $before
     * @param array{0: int, 1: int}           $after
     */
    private static function cut(array $times, array $before, array $after): float
    {
        $end = $times[$before[0] + $before[1] - 1][1] ?? 0.0;
        $next = max($end, $times[$after[0]][0] ?? $end);

        return max($end, min(($end + $next) / 2, $next - self::PRE_ROLL_SECONDS));
    }

    /**
     * @param list<array{0: float, 1: float}> $times
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    private static function words(string $text, int $offset, int $length, array $times): array
    {
        $part = mb_substr($text, $offset, $length);
        $words = [];
        if (false === preg_match_all('/\S+/u', $part, $matches, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as [$word, $byteOffset]) {
            $first = $offset + mb_strlen(substr($part, 0, $byteOffset));
            $last = $first + mb_strlen($word) - 1;
            $words[] = ['text' => $word, 'start' => $times[$first][0] ?? 0.0, 'end' => $times[$last][1] ?? 0.0];
        }

        return $words;
    }
}
