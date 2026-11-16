<?php

namespace App\Filament\Resources\MenuGroupResource\Pages;

use App\Filament\Resources\MenuGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMenuGroups extends ListRecords
{
    protected static string $resource = MenuGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
