<?php

namespace App\Filament\Resources\TutorProfiles\Pages;

use App\Filament\Resources\TutorProfiles\TutorProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListTutorProfiles extends ListRecords
{
    protected static string $resource = TutorProfileResource::class;

    /**
     * No CreateAction: tutor profiles are created only through the tutor's
     * own onboarding wizard, never by an admin.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
