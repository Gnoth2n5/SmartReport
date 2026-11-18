<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SitTemplateResource\Pages;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SitTemplateResource extends Resource
{
    protected static ?string $model = SitTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Kịch bản SIT';

    protected static ?string $pluralModelLabel = 'Kịch bản SIT';

    public static function canAccess(): bool
    {
        return User::current()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Thông tin Kịch bản SIT')
                    ->description('Cấu hình mã kịch bản, nhóm menu hiển thị trên Sidebar và phân hệ kiểm thử')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextInput::make('template_code')
                                    ->label('Mã kịch bản (Template Code)')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->placeholder('vd: sit-erp-order-sync')
                                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                                TextInput::make('template_name')
                                    ->label('Tên kịch bản SIT')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: Kiểm thử SIT Đồng bộ Đơn hàng & Sản xuất'),
                                Select::make('menu_group_code')
                                    ->label('Nhóm Menu Sidebar')
                                    ->options(fn (): array => MenuGroup::where('is_active', true)->pluck('title', 'group_code')->toArray())
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->placeholder('Chọn nhóm hiển thị trên Sidebar'),
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

                Section::make('Cấu hình Trường thông tin của Bước (Step Fields)')
                    ->description('Định nghĩa các ô nhập liệu tiêu chuẩn cho mỗi bước kiểm thử (khi chạy test vẫn có thể thêm trường phụ tùy ý)')
                    ->schema([
                        Repeater::make('step_fields')
                            ->label('Danh sách trường của Bước')
                            ->default(fn (): array => SitTemplate::defaultStepFields())
                            ->schema([
                                Grid::make(['default' => 1, 'md' => 4])
                                    ->schema([
                                        TextInput::make('key')
                                            ->label('Mã trường (Key)')
                                            ->required()
                                            ->placeholder('vd: action, expected, actual'),
                                        TextInput::make('label')
                                            ->label('Nhãn hiển thị')
                                            ->required()
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
                            ->schema([
                                TextInput::make('title')
                                    ->label('Tiêu đề bước')
                                    ->required()
                                    ->placeholder('vd: Bước 1 - Tạo đơn hàng xuất khẩu trên hệ thống'),
                                Grid::make(['default' => 1, 'md' => 3])
                                    ->schema([
                                        Textarea::make('action')
                                            ->label('Thao tác thực hiện')
                                            ->rows(2),
                                        Textarea::make('test_data')
                                            ->label('Dữ liệu đầu vào (Input)')
                                            ->rows(2),
                                        Textarea::make('expected')
                                            ->label('Kết quả mong đợi (Expected)')
                                            ->rows(2),
                                    ]),
                            ])
                            ->addActionLabel('Thêm bước mẫu')
                            ->defaultItems(1)
                            ->collapsible(),
                    ]),

                Section::make('Phân quyền truy cập (RBAC)')
                    ->schema([
                        TagsInput::make('roles')
                            ->label('Vai trò được phép truy cập')
                            ->suggestions(fn (): array => RolePermission::pluck('role_code')->toArray())
                            ->placeholder('Thêm vai trò (vd: super_admin, report_viewer)'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template_name')
                    ->label('Kịch bản SIT')
                    ->description(fn (SitTemplate $record): string => $record->template_code)
                    ->sortable()
                    ->searchable(['template_name', 'template_code'])
                    ->wrap(),
                TextColumn::make('menuGroup.title')
                    ->label('Nhóm Menu')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('module_name')
                    ->label('Phân hệ')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('test_runs_count')
                    ->counts('testRuns')
                    ->label('Số đợt test')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('version')
                    ->label('Phiên bản')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('menu_group_code')
                    ->label('Nhóm Menu')
                    ->options(fn (): array => MenuGroup::pluck('title', 'group_code')->toArray()),
                TernaryFilter::make('is_active')
                    ->label('Trạng thái kích hoạt'),
            ])
            ->recordActions([
                Action::make('open_runner')
                    ->label('Chạy SIT')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->url(fn (SitTemplate $record): string => url("/admin/sit-tests/{$record->template_code}")),
                EditAction::make()->iconButton()->tooltip('Chỉnh sửa'),
                DeleteAction::make()->iconButton()->tooltip('Xóa'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'template_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return static::getRecordRouteBindingEloquentQuery()
            ->where('template_code', $key)
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', $key))
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
