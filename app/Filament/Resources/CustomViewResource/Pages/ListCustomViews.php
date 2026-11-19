<?php

namespace App\Filament\Resources\CustomViewResource\Pages;

use App\Filament\Pages\SqlExportPage;
use App\Filament\Resources\CustomViewResource;
use App\Filament\Resources\MenuGroupResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomViews extends ListRecords
{
    protected static string $resource = CustomViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('manage_groups')->label('Nhóm báo cáo')->icon('heroicon-o-folder')->url(MenuGroupResource::getUrl()),
            Action::make('sql_tools')->label('Công cụ SQL')->icon('heroicon-o-command-line')->url(SqlExportPage::getUrl()),
        ];
    }
}
