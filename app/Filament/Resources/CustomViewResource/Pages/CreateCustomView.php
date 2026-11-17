<?php

namespace App\Filament\Resources\CustomViewResource\Pages;

use App\Filament\Resources\CustomViewResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomView extends CreateRecord
{
    protected static string $resource = CustomViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CustomViewResource::getAutoDetectParametersAction('auto_detect_parameters'),
            CustomViewResource::getTestSqlPreviewAction('preview_sql'),
        ];
    }
}
