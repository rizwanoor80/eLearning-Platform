<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `BookLesson` throws this for every rejectable input: the learner does not
 * belong to the caller, the tutor is not `bookable()`, the tutor does not
 * teach the requested (curriculum, subject) pair, the tutor's rate is out of
 * band, the requested slot is no longer available, or the database's
 * `lessons_tutor_slot_unique`/`lessons_tutor_no_overlap` constraints reject a
 * race that the app-level slot check missed.
 */
class BookingException extends RuntimeException {}
