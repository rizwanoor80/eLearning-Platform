<?php

namespace App\Filament\Resources\PaymentRefunds\Pages;

use App\Filament\Resources\PaymentRefunds\PaymentRefundResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentRefunds extends ListRecords
{
    protected static string $resource = PaymentRefundResource::class;
}
