<?php

namespace App\Filament\Resources\DataSourceResource\Pages;

use App\Filament\Resources\DataSourceResource;
use App\Models\DataSource;
use App\Services\DuckDb\DuckDbSourceAdapter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDataSource extends EditRecord
{
    protected static string $resource = DataSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Kiểm tra kết nối')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->action(function (DuckDbSourceAdapter $adapter): void {
                    /** @var DataSource $record */
                    $record = $this->record;
                    $result = $adapter->testConnection($record);
                    if ($result['success']) {
                        Notification::make()
                            ->title('Kết nối thành công')
                            ->body("{$record->name} [{$record->driver}]: {$result['latency_ms']} ms")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Kết nối thất bại')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}
