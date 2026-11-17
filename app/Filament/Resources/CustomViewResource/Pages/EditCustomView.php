<?php

namespace App\Filament\Resources\CustomViewResource\Pages;

use App\Filament\Resources\CustomViewResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomView extends EditRecord
{
    protected static string $resource = CustomViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CustomViewResource::getAutoDetectParametersAction('auto_detect_parameters'),
            CustomViewResource::getTestSqlPreviewAction('preview_sql'),
            DeleteAction::make(),
        ];
    }
}
