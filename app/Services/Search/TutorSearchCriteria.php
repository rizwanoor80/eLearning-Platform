<?php

namespace App\Services\Search;

use App\Support\Money;

/**
 * What a visitor asked for. Every field is optional; `sort` is `rating` or `price`.
 */
final readonly class TutorSearchCriteria
{
    public const SORT_RATING = 'rating';

    public const SORT_PRICE = 'price';

    public const MORNING = 'morning';

    public const AFTERNOON = 'afternoon';

    public const EVENING = 'evening';

    public function __construct(
        public ?int $curriculumId = null,
        public ?int $subjectId = null,
        public ?string $yearGroup = null,
        public ?Money $minRate = null,
        public ?Money $maxRate = null,
        public ?int $day = null,
        public ?string $timeOfDay = null,
        public ?string $minRating = null,
        public string $sort = self::SORT_RATING,
    ) {}
}
