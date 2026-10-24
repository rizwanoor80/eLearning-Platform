<?php

namespace App\Enums;

enum TenantStatus: string
{
    /** Being created: its database exists but is not yet migrated and ready. Served by nothing until it is Active. */
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
