<?php

namespace App\Filament\Resources\SitTemplateResource\Pages;

use App\Filament\Resources\SitTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSitTemplates extends ListRecords
{
    protected static string $resource = SitTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo kịch bản SIT')->icon('heroicon-o-plus')];
    }
}
