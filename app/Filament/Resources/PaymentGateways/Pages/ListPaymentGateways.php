<?php

namespace App\Filament\Resources\PaymentGateways\Pages;

use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use App\Services\Payments\PaymentGatewayManager;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListPaymentGateways extends ListRecords
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * With no gateway that can run, booking shows parents a notice and creates no `payments` row
     * (R246); this is the matching notice for the admin.
     */
    public function getSubheading(): string|Htmlable|null
    {
        if (app(PaymentGatewayManager::class)->available()) {
            return null;
        }

        return new HtmlString('<span data-test="no-active-gateway">No payment gateway is active, so parents cannot book lessons and weekly slots are not charged. Activate one below.</span>');
    }
}
