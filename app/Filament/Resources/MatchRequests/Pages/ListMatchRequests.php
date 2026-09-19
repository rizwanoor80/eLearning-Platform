<?php

namespace App\Filament\Resources\MatchRequests\Pages;

use App\Filament\Resources\MatchRequests\MatchRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListMatchRequests extends ListRecords
{
    protected static string $resource = MatchRequestResource::class;
}
