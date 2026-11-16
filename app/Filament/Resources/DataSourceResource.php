<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DataSourceResource\Pages;
use App\Models\DataSource;
use App\Services\DuckDb\DuckDbSourceAdapter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DataSourceResource extends Resource
{
    protected static ?string $model = DataSource::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Nguồn dữ liệu';

    protected static ?string $pluralModelLabel = 'Nguồn dữ liệu';

    public static function canAccess(): bool
    {
        return (Filament::auth()->user() ?? auth()->user())?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin cơ bản')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('code')
                                    ->label('Mã nguồn dữ liệu')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->placeholder('vd: pg_north, mssql_erp'),
                                TextInput::make('name')
                                    ->label('Tên hiển thị')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: PostgreSQL Nhà Máy Phía Bắc'),
                                Select::make('driver')
                                    ->label('Loại cơ sở dữ liệu')
                                    ->options([
                                        DataSource::DRIVER_SQLSRV => 'SQL Server (sqlsrv)',
                                        DataSource::DRIVER_PGSQL => 'PostgreSQL (pgsql)',
                                        DataSource::DRIVER_ORACLE => 'Oracle (oracle)',
                                    ])
                                    ->required()
                                    ->in(DataSource::ALLOWED_DRIVERS),
                                Grid::make(2)
                                    ->schema([
                                        Toggle::make('is_read_only')
                                            ->label('Chỉ đọc (Read Only)')
                                            ->default(true),
                                        Toggle::make('is_active')
                                            ->label('Kích hoạt')
                                            ->default(true),
                                    ]),
                            ]),
                    ]),
                Section::make('Thông số kết nối')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('host')
                                    ->label('Máy chủ / Host')
                                    ->required()
                                    ->default('127.0.0.1')
                                    ->maxLength(255),
                                TextInput::make('port')
                                    ->label('Cổng / Port')
                                    ->numeric()
                                    ->required(),
                                TextInput::make('database_name')
                                    ->label('Tên Database')
                                    ->nullable()
                                    ->maxLength(255)
                                    ->placeholder('source_db / erp_production'),
                                TextInput::make('service_name')
                                    ->label('Service Name (Oracle)')
                                    ->nullable()
                                    ->maxLength(255)
                                    ->placeholder('FREEPDB1'),
                                TextInput::make('default_schema')
                                    ->label('Schema mặc định')
                                    ->nullable()
                                    ->maxLength(255)
                                    ->placeholder('public / dbo / APP'),
                                TextInput::make('username')
                                    ->label('Tài khoản')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('password')
                                    ->label('Mật khẩu')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->maxLength(255),
                            ]),
                    ]),
                Section::make('Cấu hình nâng cao')
                    ->schema([
                        KeyValue::make('options')
                            ->label('Tham số bổ sung')
                            ->keyLabel('Key')
                            ->valueLabel('Value'),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->sortable()
                    ->searchable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Tên nguồn dữ liệu')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pgsql' => 'info',
                        'sqlsrv' => 'warning',
                        'oracle' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('host')
                    ->label('Host')
                    ->searchable(),
                TextColumn::make('port')
                    ->label('Port')
                    ->sortable(),
                TextColumn::make('database_name')
                    ->label('Database')
                    ->placeholder('—'),
                TextColumn::make('service_name')
                    ->label('Service')
                    ->placeholder('—'),
                IconColumn::make('is_read_only')
                    ->label('Chỉ đọc')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->label('Driver')
                    ->options([
                        DataSource::DRIVER_SQLSRV => 'SQL Server (sqlsrv)',
                        DataSource::DRIVER_PGSQL => 'PostgreSQL (pgsql)',
                        DataSource::DRIVER_ORACLE => 'Oracle (oracle)',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Trạng thái kích hoạt'),
                TernaryFilter::make('is_read_only')
                    ->label('Chỉ đọc (Read Only)'),
            ])
            ->recordActions([
                Action::make('test_connection')
                    ->label('Kiểm tra kết nối')
                    ->icon('heroicon-o-bolt')
                    ->color('info')
                    ->action(function (DataSource $record, DuckDbSourceAdapter $adapter): void {
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
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        $query = static::getRecordRouteBindingEloquentQuery();

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        return $query->where(function ($q) use ($key): void {
            $q->where('code', $key);
            if (is_numeric($key)) {
                $q->orWhere('id', $key);
            }
        })->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataSources::route('/'),
            'create' => Pages\CreateDataSource::route('/create'),
            'edit' => Pages\EditDataSource::route('/{record}/edit'),
        ];
    }
}
