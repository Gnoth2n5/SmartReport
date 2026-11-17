<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RolePermissionResource\Pages;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RolePermissionResource extends Resource
{
    protected static ?string $model = RolePermission::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Vai trò & Phân quyền';

    protected static ?string $pluralModelLabel = 'Vai trò & Phân quyền';

    public static function canAccess(): bool
    {
        return (Filament::auth()->user() ?? auth()->user())?->isSuperAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return ! (bool) ($record->is_system ?? false);
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        if ((bool) ($record->is_system ?? false)) {
            return Response::deny('System roles cannot be deleted.');
        }

        return parent::getDeleteAuthorizationResponse($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Thông tin vai trò')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextInput::make('role_code')
                                    ->label('Mã vai trò')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->disabled(fn (?RolePermission $record): bool => (bool) $record?->is_system)
                                    ->placeholder('vd: super_admin, report_viewer'),
                                TextInput::make('role_name')
                                    ->label('Tên vai trò')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: Quản trị viên cấp cao'),
                                Textarea::make('description')
                                    ->label('Mô tả vai trò')
                                    ->maxLength(1000)
                                    ->columnSpanFull(),
                                Toggle::make('is_system')
                                    ->label('Vai trò hệ thống')
                                    ->default(false)
                                    ->disabled(),
                            ]),
                    ]),
                Section::make('Phạm vi phân quyền')
                    ->schema([
                        Select::make('allowed_groups')
                            ->label('Nhóm Menu được phép')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->options(fn (): array => ['*' => 'Tất cả nhóm (*)'] + MenuGroup::pluck('title', 'group_code')->toArray()),
                        Select::make('allowed_views')
                            ->label('Báo cáo (Custom View) được phép')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->options(fn (): array => ['*' => 'Tất cả báo cáo (*)'] + CustomView::pluck('view_name', 'view_code')->toArray()),
                        Select::make('allowed_sources')
                            ->label('Nguồn dữ liệu DB được phép')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->options(fn (): array => ['*' => 'Tất cả nguồn dữ liệu (*)'] + DataSource::pluck('name', 'code')->toArray()),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('role_code')
                    ->label('Mã vai trò')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role_name')
                    ->label('Tên vai trò')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(50)
                    ->toggleable(),
                IconColumn::make('is_system')
                    ->label('Hệ thống')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('allowed_groups')
                    ->label('Nhóm được phép')
                    ->badge()
                    ->separator(','),
                TextColumn::make('allowed_views')
                    ->label('Báo cáo được phép')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),
                TextColumn::make('allowed_sources')
                    ->label('Nguồn được phép')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_system')
                    ->label('Vai trò hệ thống'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (RolePermission $record): bool => (bool) $record->is_system),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $records->filter(fn (RolePermission $record): bool => ! $record->is_system)->each->delete();
                        }),
                ]),
            ]);
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'role_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return static::getRecordRouteBindingEloquentQuery()
            ->where('role_code', $key)
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', $key))
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRolePermissions::route('/'),
            'create' => Pages\CreateRolePermission::route('/create'),
            'edit' => Pages\EditRolePermission::route('/{record}/edit'),
        ];
    }
}
