<?php

namespace App\Services\Video;

/**
 * There is no active, usable registry row. The app still runs (PRD §12); the caller shows an
 * admin notice instead of a room.
 */
class NoActiveVideoProvider extends VideoProviderException {}
