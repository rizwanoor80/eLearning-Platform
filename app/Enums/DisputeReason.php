<?php

namespace App\Enums;

/**
 * DATA_MODEL §disputes. No `no_show_tutor`/`no_show_student` split: a
 * tutor no-show auto-advances `no_show_tutor -> refunded` (terminal) before
 * a dispute could ever open on it (see docs/CYCLE-LOG.md, the 9b defaults
 * DECISION) — `NoShow` here always means the account holder's own claim on
 * a `completed`/`completed_reported` lesson, party unspecified.
 */
enum DisputeReason: string
{
    case NoShow = 'no_show';
    case Quality = 'quality';
    case Technical = 'technical';
    case Other = 'other';
}
