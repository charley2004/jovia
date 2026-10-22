<?php

namespace App\Filament\Resources\TaskModuleResource\Pages;

use App\Filament\Resources\TaskModuleResource;
use Filament\Resources\Pages\EditRecord;

class EditTaskModule extends EditRecord
{
    protected static string $resource = TaskModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
