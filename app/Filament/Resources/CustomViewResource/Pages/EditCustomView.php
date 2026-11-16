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
            CustomViewResource::getAutoDetectParametersAction('auto_detect_params'),
            CustomViewResource::getTestSqlPreviewAction('test_sql_preview'),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->normalizeDataForForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normalizeDataForEngine($data);
    }

    protected function normalizeDataForForm(array $data): array
    {
        if (isset($data['source_queries']) && is_array($data['source_queries'])) {
            foreach ($data['source_queries'] as &$sq) {
                $sq['alias'] = $sq['alias'] ?? $sq['table_alias'] ?? '';
                $sq['sql_text'] = $sq['sql_text'] ?? $sq['query'] ?? '';
            }
        }

        if (isset($data['filters']) && is_array($data['filters'])) {
            foreach ($data['filters'] as &$f) {
                $f['name'] = $f['name'] ?? $f['param_name'] ?? '';
                $f['type'] = $f['type'] ?? $f['input_type'] ?? 'text';
            }
        }

        return $data;
    }

    protected function normalizeDataForEngine(array $data): array
    {
        if (isset($data['source_queries']) && is_array($data['source_queries'])) {
            $normalized = [];
            foreach ($data['source_queries'] as $sq) {
                $alias = $sq['alias'] ?? $sq['table_alias'] ?? '';
                $sqlText = $sq['sql_text'] ?? $sq['query'] ?? '';
                $normalized[] = [
                    'source_code' => $sq['source_code'] ?? '',
                    'alias' => $alias,
                    'table_alias' => $alias,
                    'sql_text' => $sqlText,
                    'query' => $sqlText,
                ];
            }
            $data['source_queries'] = $normalized;
        }

        if (isset($data['filters']) && is_array($data['filters'])) {
            $normalized = [];
            foreach ($data['filters'] as $f) {
                $name = $f['name'] ?? $f['param_name'] ?? '';
                $type = $f['type'] ?? $f['input_type'] ?? 'text';
                $normalized[] = [
                    'name' => $name,
                    'param_name' => $name,
                    'label' => $f['label'] ?? $name,
                    'type' => $type,
                    'input_type' => $type,
                    'default_value' => $f['default_value'] ?? null,
                    'is_required' => (bool) ($f['is_required'] ?? false),
                    'options' => $f['options'] ?? null,
                ];
            }
            $data['filters'] = $normalized;
        }

        return $data;
    }
}
