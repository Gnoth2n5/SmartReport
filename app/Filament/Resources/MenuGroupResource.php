<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuGroupResource\Pages;
use App\Models\MenuGroup;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MenuGroupResource extends Resource
{
    protected static ?string $model = MenuGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Nhóm menu';

    protected static ?string $pluralModelLabel = 'Nhóm menu';

    public static function canAccess(): bool
    {
        return User::current()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Thông tin nhóm Menu')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextInput::make('title')
                                    ->label('Tiêu đề nhóm')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: Sản Xuất & Vận Hành'),
                                Toggle::make('is_active')
                                    ->label('Kích hoạt')
                                    ->default(true),
                            ]),
                    ]),
                Section::make('Nâng cao')->collapsed()->schema([
                    TextInput::make('group_code')
                        ->label('Mã kỹ thuật (tự sinh)')
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->placeholder('vd: manufacturing, logistics'),
                    TextInput::make('icon')
                        ->label('Icon (Heroicon)')
                        ->default('heroicon-o-folder')
                        ->maxLength(255)
                        ->placeholder('heroicon-o-folder'),
                    TextInput::make('sort_order')
                        ->label('Thứ tự sắp xếp')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable(),
                TextColumn::make('group_code')
                    ->label('Mã nhóm')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('icon')
                    ->label('Icon')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('sort_order', 'asc')->reorderable('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái kích hoạt'),
            ])
            ->recordActions([
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
        return 'group_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return static::getRecordRouteBindingEloquentQuery()
            ->where('group_code', $key)
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', $key))
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenuGroups::route('/'),
            'create' => Pages\CreateMenuGroup::route('/create'),
            'edit' => Pages\EditMenuGroup::route('/{record}/edit'),
        ];
    }
}
