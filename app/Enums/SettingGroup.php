<?php

namespace App\Enums;

enum SettingGroup: string
{
    case Platform = 'platform';
    case Site = 'site';
    case Mail = 'mail';
    case Features = 'features';
}
