<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SitTemplateResource\Pages;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class SitTemplateResource extends Resource
{
    protected static ?string $model = SitTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Kịch bản SIT';

    protected static ?string $pluralModelLabel = 'Kịch bản SIT';

    public static function getEloquentQuery(): Builder
    {
        $user = User::current();

        return $user === null ? parent::getEloquentQuery()->whereRaw('1 = 0') : parent::getEloquentQuery()->accessibleTo($user);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Thông tin Kịch bản SIT')
                    ->description('Cấu hình mã kịch bản, tên kịch bản và phân hệ kiểm thử')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('template_name')
                                    ->label('Tên kịch bản SIT')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: Kiểm thử SIT Đồng bộ Đơn hàng & Sản xuất'),
                            ]),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextInput::make('module_name')
                                    ->label('Phân hệ / Module')
                                    ->maxLength(255)
                                    ->placeholder('vd: ERP Sản xuất & Kho'),
                                TextInput::make('version')
                                    ->label('Phiên bản')
                                    ->default('1.0.0')
                                    ->required()
                                    ->maxLength(50),
                                Toggle::make('is_active')
                                    ->label('Kích hoạt kịch bản')
                                    ->default(true),
                            ]),
                        Textarea::make('description')
                            ->label('Mô tả phạm vi & điều kiện tiên quyết (Pre-conditions)')
                            ->rows(2)
                            ->maxLength(2000)
                            ->nullable(),
                    ]),

                Section::make('Trường tùy biến')->collapsed()
                    ->description('Định nghĩa các ô nhập liệu tiêu chuẩn cho mỗi bước kiểm thử (khi chạy test vẫn có thể thêm trường phụ tùy ý)')
                    ->schema([
                        Repeater::make('step_fields')
                            ->label('Danh sách trường của Bước')
                            ->default(fn (): array => SitTemplate::defaultStepFields())
                            ->schema([
                                Grid::make(['default' => 1, 'md' => 4])
                                    ->schema([
                                        TextInput::make('key')
                                            ->label('Mã trường')
                                            ->required()->regex('/^[a-zA-Z_][a-zA-Z0-9_]*$/')->distinct()->live(onBlur: true)
                                            ->placeholder('vd: action, expected, actual'),
                                        TextInput::make('label')
                                            ->label('Nhãn hiển thị')
                                            ->required()->live(onBlur: true)
                                            ->placeholder('vd: Thao tác thực hiện'),
                                        Select::make('type')
                                            ->label('Kiểu ô nhập')
                                            ->options([
                                                'textarea' => 'Văn bản nhiều dòng (textarea)',
                                                'text' => 'Văn bản ngắn (text)',
                                                'select' => 'Danh sách chọn (select)',
                                            ])
                                            ->default('textarea')
                                            ->required()
                                            ->live(),
                                        Toggle::make('required')
                                            ->label('Bắt buộc')
                                            ->default(false),
                                    ]),
                                KeyValue::make('options')
                                    ->label('Danh sách tùy chọn (Key => Label)')
                                    ->visible(fn (Get $get): bool => $get('type') === 'select'),
                            ])
                            ->addActionLabel('Thêm trường thông tin cho Bước')
                            ->collapsible(),
                    ]),

                Section::make('Danh sách Bước mẫu (Default Steps)')
                    ->description('Các bước khởi tạo sẵn mỗi khi tạo một Đợt chạy SIT (Test Run) mới từ kịch bản này')
                    ->schema([
                        Repeater::make('default_steps')
                            ->label('Các bước kiểm thử mẫu')
                            ->afterStateHydrated(function (Repeater $component, array $state): void {
                                $template = new SitTemplate(['default_steps' => $state]);
                                $component->state($template->default_steps);
                            })
                            ->schema([
                                TextInput::make('title')
                                    ->label('Tiêu đề bước')
                                    ->required()
                                    ->placeholder('vd: Bước 1 - Tạo đơn hàng xuất khẩu trên hệ thống'),
                                Hidden::make('uid')->default(fn (): string => 'step_'.Str::lower(Str::random(8))),
                                Group::make()->schema(function (Get $get): array {
                                    $template = new SitTemplate(['step_fields' => $get('data.step_fields', isAbsolute: true)]);

                                    return array_map(function (array $field) {
                                        $name = 'fields.'.$field['key'];
                                        $component = match ($field['type']) {
                                            'select' => Select::make($name)->options($field['options'] ?? []),
                                            'text' => TextInput::make($name),
                                            default => Textarea::make($name)->rows(2),
                                        };

                                        return $component->label($field['label'])->nullable();
                                    }, $template->resolvedStepFields());
                                })->columns(['default' => 1, 'md' => 2]),
                            ])
                            ->addActionLabel('Thêm bước mẫu')
                            ->defaultItems(1)
                            ->collapsible(),
                    ]),

                Section::make('Nâng cao')->collapsed()->schema([
                    TextInput::make('template_code')
                        ->label('Mã kỹ thuật (tự sinh)')
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->placeholder('vd: sit-erp-order-sync')
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                ]),

                Section::make('Quyền truy cập')
                    ->description('Cấp và thu hồi quyền tại màn hình Phân quyền. Super admin luôn có quyền.')
                    ->schema([
                        Text::make(function (?Model $record): string {
                            if ($record === null) {
                                return 'Lưu nội dung trước khi cấp quyền cho các vai trò khác.';
                            }
                            $names = RolePermission::query()
                                ->whereJsonContains('allowed_sit_templates', '*')
                                ->orWhereJsonContains('allowed_sit_templates', $record->template_code)
                                ->pluck('role_name')->implode(', ');

                            return $names !== '' ? 'Vai trò được cấp quyền: '.$names : 'Chưa cấp quyền cho vai trò khác.';
                        }),
                        Actions::make([
                            Action::make('manage_permissions')->label('Quản lý quyền')->url(RolePermissionResource::getUrl())->openUrlInNewTab(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(['id', 'template_code', 'template_name', 'module_name', 'description', 'version', 'is_active', 'updated_at'])
                ->selectRaw('coalesce(json_array_length(default_steps), 0) as steps_count'))
            ->columns([
                Stack::make([
                    TextColumn::make('template_name')
                        ->label('Kịch bản SIT')
                        ->weight(FontWeight::Bold)
                        ->description(fn (SitTemplate $record): string => "{$record->template_code} • v{$record->version}")
                        ->searchable(['template_name', 'template_code', 'module_name'])
                        ->wrap(),
                    TextColumn::make('description')->limit(160)->wrap(),
                    TextColumn::make('module_name')->badge()->placeholder('Chung'),
                    TextColumn::make('test_runs_count')
                        ->counts('testRuns')
                        ->formatStateUsing(fn (int $state, SitTemplate $record): string => "{$record->steps_count} bước mẫu • {$state} đợt test"),
                    TextColumn::make('is_active')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Sẵn sàng' : 'Tạm tắt')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ])->space(2),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([12, 24, 48])
            ->filters([
                TernaryFilter::make('is_active')->label('Trạng thái kích hoạt')->visible(fn (): bool => User::current()?->isSuperAdmin() ?? false),
            ])
            ->recordActions([
                Action::make('open_runner')
                    ->label('Chạy SIT')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (SitTemplate $record): bool => $record->is_active)
                    ->url(fn (SitTemplate $record): string => url("/admin/sit-tests/{$record->template_code}")),
                EditAction::make()->iconButton()->tooltip('Chỉnh sửa'),
                DeleteAction::make()->iconButton()->tooltip('Xóa')
                    ->modalDescription('Xóa kịch bản cũng xóa toàn bộ các đợt test của kịch bản này.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'template_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return static::getRecordRouteBindingEloquentQuery()
            ->where(function (Builder $query) use ($key): void {
                $query->where('template_code', $key)->when(is_numeric($key), fn (Builder $query): Builder => $query->orWhere('id', $key));
            })
            ->when($modifyQuery !== null, $modifyQuery)
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSitTemplates::route('/'),
            'create' => Pages\CreateSitTemplate::route('/create'),
            'edit' => Pages\EditSitTemplate::route('/{record}/edit'),
        ];
    }
}
