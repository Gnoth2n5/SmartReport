<?php

namespace App\Filament\Resources\CustomViewResource\Pages;

use App\Filament\Resources\CustomViewResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomViews extends ListRecords
{
    protected static string $resource = CustomViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
