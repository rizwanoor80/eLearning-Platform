<?php

namespace App\Enums;

enum StrikeType: string
{
    case LateCancel = 'late_cancel';
    case NoShow = 'no_show';
    case LateReportX3 = 'late_report_x3';
    case Admin = 'admin';
}
