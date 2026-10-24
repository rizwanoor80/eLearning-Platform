<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    PaymentGatewayServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
