<?php

namespace App\Services\Video;

/**
 * A created room: the provider's name for it (which is ours, `lesson-<id>`) and the URL both
 * parties open. The URL carries no credential — access is by per-party join token.
 */
final readonly class VideoRoom
{
    private const PREFIX = 'lesson-';

    public function __construct(
        public string $name,
        public string $url,
    ) {}

    /**
     * The room name for a lesson. One deterministic name per lesson is what makes creating the
     * room twice return the same room (7c).
     */
    public static function nameFor(int $lessonId): string
    {
        return self::PREFIX.$lessonId;
    }

    public static function lessonIdFrom(string $roomName): ?int
    {
        if (preg_match('/^'.self::PREFIX.'([1-9][0-9]{0,17})$/', $roomName, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
