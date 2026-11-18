<?php

namespace App\Filament\Resources\SitTemplateResource\Pages;

use App\Filament\Resources\SitTemplateResource;
use App\Models\SitTemplate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSitTemplate extends EditRecord
{
    protected static string $resource = SitTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_runner')
                ->label('Mở trang Test SIT')
                ->icon('heroicon-o-play')
                ->color('success')
                ->url(fn (SitTemplate $record): string => url("/admin/sit-tests/{$record->template_code}")),
            DeleteAction::make(),
        ];
    }
}
