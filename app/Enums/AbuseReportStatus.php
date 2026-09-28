<?php

namespace App\Enums;

/**
 * Lifecycle of an `abuse_reports` row (R137), worked only from the Filament Safeguarding
 * queue. `Open` is the state a new filing always starts in.
 */
enum AbuseReportStatus: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case Closed = 'closed';
}
