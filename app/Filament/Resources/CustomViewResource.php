<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomViewResource\Pages;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\Sql\SqlParameterExtractor;
use App\Services\Sql\SqlSafetyValidator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component as Livewire;
use UnitEnum;

class CustomViewResource extends Resource
{
    protected static ?string $model = CustomView::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Báo cáo tùy chỉnh';

    protected static ?string $pluralModelLabel = 'Báo cáo tùy chỉnh';

    public static function canAccess(): bool
    {
        return User::current()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // 1. General Information
                Section::make('Thông tin chung')
                    ->description('Cấu hình định danh, tên gọi và phân loại báo cáo')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextInput::make('view_code')
                                    ->label('Mã báo cáo (View Code)')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->placeholder('vd: pg-multi-plant-contracts')
                                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                                TextInput::make('view_name')
                                    ->label('Tên báo cáo')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('vd: Hợp Đồng Xuất Khẩu Đa Chi Nhánh'),
                                Select::make('menu_group_code')
                                    ->label('Nhóm Menu')
                                    ->options(fn (): array => MenuGroup::where('is_active', true)->pluck('title', 'group_code')->toArray())
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->placeholder('Chọn nhóm phân loại'),
                            ]),
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                TextInput::make('version')
                                    ->label('Phiên bản')
                                    ->default('1.0.0')
                                    ->required()
                                    ->maxLength(50),
                                Toggle::make('is_active')
                                    ->label('Kích hoạt báo cáo')
                                    ->default(true),
                            ]),
                        Textarea::make('description')
                            ->label('Mô tả mục đích báo cáo')
                            ->rows(2)
                            ->maxLength(1000)
                            ->nullable(),
                    ]),

                // 2. Execution Mode & Data Source Configuration
                Section::make('Cấu hình Chế độ Thực thi & Nguồn Dữ liệu')
                    ->description('Chọn chế độ Single/Union DB hoặc Cross-Engine Federated JOIN')
                    ->schema([
                        Select::make('execution_mode')
                            ->label('Chế độ thực thi (Execution Mode)')
                            ->options([
                                CustomView::MODE_SINGLE_OR_UNION => 'Single DB / Multi-DB UNION ALL (single_or_union)',
                                CustomView::MODE_FEDERATED_JOIN => 'Cross-Engine Federated JOIN (federated_join)',
                            ])
                            ->default(CustomView::MODE_SINGLE_OR_UNION)
                            ->in(CustomView::EXECUTION_MODES)
                            ->required()
                            ->live(),

                        Repeater::make('source_selections')
                            ->label('Nguồn dữ liệu')
                            ->table([TableColumn::make('Nguồn'), TableColumn::make('Cho phép'), TableColumn::make('Mặc định')])
                            ->addable(false)->deletable(false)->reorderable(false)
                            ->afterStateHydrated(function (Repeater $component, ?CustomView $record): void {
                                $sources = DataSource::orderBy('id')->get(['code', 'name', 'is_active']);
                                $allowed = $record !== null ? ($record->allowed_sources ?: $sources->where('is_active', true)->pluck('code')->all()) : array_slice($sources->where('is_active', true)->pluck('code')->all(), 0, 1);
                                $defaults = $record?->defaultSourceCodes($sources->pluck('code')->all()) ?? $allowed;
                                $component->state($sources->map(fn (DataSource $source): array => [
                                    'source_code' => $source->code,
                                    'source_name' => $source->name.($source->is_active ? '' : ' (Đã tắt)'),
                                    'is_allowed' => in_array($source->code, $allowed, true),
                                    'is_default' => in_array($source->code, $defaults, true),
                                ])->all());
                            })
                            ->afterStateUpdated(function (Repeater $component, array $state): void {
                                $allowed = array_filter($state, fn (array $row): bool => (bool) ($row['is_allowed'] ?? false));
                                if (count($allowed) === 1) {
                                    $only = array_values($allowed)[0]['source_code'];
                                    foreach ($state as &$row) {
                                        $row['is_default'] = $row['source_code'] === $only;
                                    }
                                    unset($row);
                                    $component->state($state);
                                }
                            })
                            ->schema([
                                Hidden::make('source_code')->required()->in(fn (): array => DataSource::pluck('code')->all()),
                                Text::make(fn (Get $get): string => (string) $get('source_name')),
                                Checkbox::make('is_allowed')->label('Cho phép')->live()
                                    ->extraInputAttributes(fn (Get $get): array => ['aria-label' => 'Cho phép '.$get('source_code')])
                                    ->afterStateUpdated(function (bool $state, Set $set): void {
                                        if (! $state) {
                                            $set('is_default', false);
                                        }
                                    }),
                                Checkbox::make('is_default')->label('Mặc định')->live()
                                    ->extraInputAttributes(fn (Get $get): array => ['aria-label' => 'Mặc định '.$get('source_code')])
                                    ->disabled(fn (Get $get): bool => ! $get('is_allowed')),
                            ])
                            ->visible(fn (Get $get): bool => $get('execution_mode') === CustomView::MODE_SINGLE_OR_UNION),

                        // Mode B: Federated Join configuration
                        Repeater::make('source_queries')
                            ->label('Cấu hình các truy vấn nguồn (Source Queries)')
                            ->schema([
                                Select::make('source_code')
                                    ->label('Nguồn dữ liệu')
                                    ->options(fn (): array => DataSource::where('is_active', true)->pluck('name', 'code')->toArray())
                                    ->required()
                                    ->searchable(),
                                TextInput::make('alias')
                                    ->label('Bí danh bảng tạm (Alias / Table Alias)')
                                    ->required()
                                    ->placeholder('vd: emp, orders, contracts'),
                                Textarea::make('sql_text')
                                    ->label('Câu lệnh SQL nguồn (Query / SQL Text)')
                                    ->required()
                                    ->rows(3)
                                    ->placeholder('SELECT EMP_NO, FULL_NAME FROM APP.EMPLOYEES WHERE ...')
                                    ->columnSpanFull(),
                            ])
                            ->columns(['default' => 1, 'sm' => 2])
                            ->visible(fn (Get $get): bool => $get('execution_mode') === CustomView::MODE_FEDERATED_JOIN)
                            ->addActionLabel('Thêm truy vấn nguồn DB')
                            ->defaultItems(2),
                    ]),

                // 3. SQL Query Definition
                Section::make('Câu lệnh SQL')
                    ->description('Định nghĩa câu lệnh truy vấn dữ liệu')
                    ->schema([
                        Textarea::make('sql_text')
                            ->label(fn (Get $get): string => $get('execution_mode') === CustomView::MODE_FEDERATED_JOIN
                                ? 'Câu lệnh SQL Federated Join (DuckDB kết hợp các bảng tạm)'
                                : 'Câu lệnh SQL truy vấn')
                            ->rows(8)
                            ->required()
                            ->placeholder('SELECT * FROM ... WHERE (@param IS NULL OR col = @param)')
                            ->helperText('Hỗ trợ tham số bắt đầu bằng ký tự @ (vd: @StartDate, @Plant, @Status).'),
                    ]),

                // 4. Dynamic Filters Repeater
                Section::make('Bộ lọc tham số động (@param)')
                    ->description('Cấu hình các tiêu chí lọc người dùng nhập khi chạy báo cáo')
                    ->headerActions([
                        static::getAutoDetectParametersAction('section_auto_detect_params'),
                    ])
                    ->schema([
                        Repeater::make('filters')
                            ->label('Danh sách bộ lọc')
                            ->schema([
                                Grid::make(['default' => 1, 'md' => 3])
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Tên tham số (@param)')
                                            ->required()
                                            ->placeholder('vd: Status'),
                                        TextInput::make('label')
                                            ->label('Nhãn hiển thị')
                                            ->required()
                                            ->placeholder('vd: Trạng thái hợp đồng'),
                                        Select::make('type')
                                            ->label('Kiểu nhập liệu')
                                            ->options([
                                                'text' => 'Văn bản (text)',
                                                'date' => 'Ngày tháng (date)',
                                                'number' => 'Số (number)',
                                                'select' => 'Danh sách chọn (select)',
                                            ])
                                            ->default('text')
                                            ->required()
                                            ->live(),
                                    ]),
                                Grid::make(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('default_value')
                                            ->label('Giá trị mặc định')
                                            ->nullable(),
                                        Toggle::make('is_required')
                                            ->label('Bắt buộc nhập')
                                            ->default(false),
                                    ]),
                                KeyValue::make('options')
                                    ->label('Danh sách tùy chọn (Key => Label)')
                                    ->keyLabel('Mã gửi vào SQL')
                                    ->valueLabel('Tên hiển thị')
                                    ->keyPlaceholder('vd: COMPLETED')
                                    ->valuePlaceholder('vd: Hoàn tất')
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ['select']) || in_array($get('input_type'), ['select'])),
                            ])
                            ->addActionLabel('Thêm tham số bộ lọc')
                            ->defaultItems(0)
                            ->collapsible(),
                    ]),

                // 5. RBAC Roles Assignment
                Section::make('Quyền truy cập')
                    ->description('Cấp và thu hồi quyền tại màn hình Phân quyền. Super admin luôn có quyền.')
                    ->schema([
                        Text::make(function (?Model $record): string {
                            if ($record === null) {
                                return 'Lưu nội dung trước khi cấp quyền cho các vai trò khác.';
                            }
                            $names = RolePermission::query()
                                ->whereJsonContains('allowed_views', '*')
                                ->orWhereJsonContains('allowed_views', $record->view_code)
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
            ->columns([
                TextColumn::make('view_name')
                    ->label('Báo cáo')
                    ->description(fn (CustomView $record): string => $record->view_code)
                    ->sortable()
                    ->searchable(['view_name', 'view_code'])
                    ->wrap(),
                TextColumn::make('view_code')
                    ->label('Mã báo cáo')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('menuGroup.title')
                    ->label('Nhóm Menu')
                    ->sortable()
                    ->badge()
                    ->placeholder('—')
                    ->visibleFrom('xl')
                    ->toggleable(),
                TextColumn::make('execution_mode')
                    ->label('Chế độ')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CustomView::MODE_SINGLE_OR_UNION => 'info',
                        CustomView::MODE_FEDERATED_JOIN => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CustomView::MODE_SINGLE_OR_UNION => 'Single / Union',
                        CustomView::MODE_FEDERATED_JOIN => 'Federated Join',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('version')
                    ->label('Phiên bản')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('execution_mode')
                    ->label('Chế độ thực thi')
                    ->options([
                        CustomView::MODE_SINGLE_OR_UNION => 'Single / Union',
                        CustomView::MODE_FEDERATED_JOIN => 'Federated Join',
                    ]),
                SelectFilter::make('menu_group_code')
                    ->label('Nhóm Menu')
                    ->options(fn (): array => MenuGroup::pluck('title', 'group_code')->toArray()),
                TernaryFilter::make('is_active')
                    ->label('Trạng thái kích hoạt'),
            ])
            ->recordActions([
                static::getTestSqlPreviewAction('test_sql', 'Chạy thử'),
                EditAction::make()->iconButton()->tooltip('Chỉnh sửa'),
                DeleteAction::make()->iconButton()->tooltip('Xóa'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Resolve form data across component action, page action, and table record action.
     *
     * @return array<string, mixed>
     */
    public static function resolveFormData(Action $action, ?Livewire $livewire = null, ?Model $record = null): array
    {
        $data = $record instanceof CustomView ? $record->toArray() : [];

        if ($livewire && isset($livewire->data) && is_array($livewire->data)) {
            $data = array_merge($data, array_filter($livewire->data, fn ($v) => $v !== null));
        }

        return $data;
    }

    /**
     * Action 1: Auto-Detect @Parameters Action builder.
     */
    public static function getAutoDetectParametersAction(?string $name = 'auto_detect_parameters'): Action
    {
        return Action::make($name)
            ->label('Tự động nhận diện @tham số')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->tooltip('Quét câu lệnh SQL và tự động cấu hình các tham số @param vào bộ lọc')
            ->action(function (Action $action, ?Livewire $livewire = null, ?Model $record = null, ?SqlParameterExtractor $extractor = null): void {
                static::executeAutoDetectParameters($livewire, $action, $record, $extractor);
            });
    }

    /**
     * Logic for Auto-Detect Parameters.
     */
    public static function executeAutoDetectParameters(?Livewire $livewire, Action $action, ?Model $record = null, ?SqlParameterExtractor $extractor = null): void
    {
        $extractor ??= app(SqlParameterExtractor::class);
        $data = static::resolveFormData($action, $livewire, $record);

        $sqlText = (string) ($data['sql_text'] ?? '');
        $executionMode = (string) ($data['execution_mode'] ?? CustomView::MODE_SINGLE_OR_UNION);
        $sourceQueries = (array) ($data['source_queries'] ?? []);
        $existingFilters = (array) ($data['filters'] ?? []);

        $sqls = trim($sqlText) !== '' ? [$sqlText] : [];
        if ($executionMode === CustomView::MODE_FEDERATED_JOIN) {
            foreach ($sourceQueries as $sq) {
                $q = (string) ($sq['sql_text'] ?? $sq['query'] ?? '');
                if (trim($q) !== '') {
                    $sqls[] = $q;
                }
            }
        }

        if (empty($sqls)) {
            Notification::make()->title('Chưa có câu lệnh SQL')->body('Vui lòng nhập câu lệnh SQL trước khi nhận diện tham số.')->warning()->send();

            return;
        }

        $allTokens = [];
        foreach ($sqls as $sql) {
            foreach ($extractor->extractTokens($sql) as $token) {
                if (! in_array($token, $allTokens, true)) {
                    $allTokens[] = $token;
                }
            }
        }

        if (empty($allTokens)) {
            Notification::make()->title('Không tìm thấy tham số')->body('Không phát hiện tham số @tham_so nào trong câu lệnh SQL.')->info()->send();

            return;
        }

        $existingMap = [];
        foreach ($existingFilters as $f) {
            if (is_array($f)) {
                $key = strtolower(ltrim((string) ($f['name'] ?? $f['param_name'] ?? ''), '@'));
                if ($key !== '') {
                    $existingMap[$key] = $f;
                }
            }
        }

        $newFilters = [];
        foreach ($allTokens as $token) {
            $cleanToken = ltrim($token, '@');
            $key = strtolower($cleanToken);

            if (isset($existingMap[$key])) {
                $existing = $existingMap[$key];
                $existing['name'] = $existing['name'] ?? $existing['param_name'] ?? $cleanToken;
                $existing['type'] = $existing['type'] ?? $existing['input_type'] ?? 'text';
                $existing['label'] = $existing['label'] ?? $cleanToken;
                $existing['is_required'] = (bool) ($existing['is_required'] ?? false);
                $newFilters[] = $existing;
            } else {
                $newFilters[] = $extractor->inferParameterDefinition($cleanToken);
            }
        }

        if ($livewire && isset($livewire->data) && is_array($livewire->data)) {
            $livewire->data['filters'] = $newFilters;
        }

        Notification::make()
            ->title('Đã nhận diện tham số')
            ->body('Tìm thấy '.count($newFilters).' tham số trong câu lệnh SQL.')
            ->success()
            ->send();
    }

    /**
     * Action 2: Test SQL 10-Row Preview Action builder.
     */
    public static function getTestSqlPreviewAction(?string $name = 'preview_sql', string $label = 'Chạy thử SQL (Test 10 dòng)'): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading('Kết quả chạy thử SQL (10 dòng đầu tiên)')
            ->modalWidth('7xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->modalContent(fn (Action $action, ?Livewire $livewire = null, ?Model $record = null): View => view(
                'filament.components.sql-preview-modal',
                static::runPreviewData($action, $livewire, $record)
            ))
            ->action(function (Action $action, ?Livewire $livewire = null, ?Model $record = null): void {
                $res = static::runPreviewData($action, $livewire, $record);
                if ($res['error'] !== null) {
                    Notification::make()->title('Lỗi chạy thử SQL')->body($res['error'])->danger()->persistent()->send();
                } else {
                    Notification::make()
                        ->title('Chạy thử thành công')
                        ->body("Dòng xem trước: {$res['totalRows']} | Thời gian: {$res['latencyMs']} ms | Cột: ".implode(', ', array_slice($res['columns'], 0, 5)))
                        ->success()
                        ->send();
                }
            });
    }

    /**
     * Shared preview execution helper for modal and action trigger.
     *
     * @return array{error: ?string, preview?: array<string, mixed>, rows: list<array<string, mixed>>, columns: list<string>, totalRows: int, latencyMs: float|int, appliedParams: array<string, mixed>}
     */
    protected static function runPreviewData(Action $action, ?Livewire $livewire = null, ?Model $record = null): array
    {
        $reportEngine = app(DuckDbReportEngine::class);
        $validator = app(SqlSafetyValidator::class);
        $data = static::resolveFormData($action, $livewire, $record);

        $sqlText = (string) ($data['sql_text'] ?? '');
        $executionMode = (string) ($data['execution_mode'] ?? CustomView::MODE_SINGLE_OR_UNION);
        $allowedSources = (array) ($data['allowed_sources'] ?? []);
        $defaultSources = (array) ($data['default_sources'] ?? []);
        $sourceQueries = (array) ($data['source_queries'] ?? []);
        $filters = (array) ($data['filters'] ?? []);
        $empty = ['rows' => [], 'columns' => [], 'totalRows' => 0, 'latencyMs' => 0, 'appliedParams' => []];

        if (trim($sqlText) === '') {
            return ['error' => 'Vui lòng nhập câu lệnh SQL trước khi chạy thử.', ...$empty];
        }

        $violations = [];
        if (! $validator->isSafe($sqlText, $violations)) {
            return ['error' => implode(' ', $violations), ...$empty];
        }

        if ($executionMode === CustomView::MODE_FEDERATED_JOIN) {
            foreach ($sourceQueries as $sq) {
                $sqSql = (string) ($sq['sql_text'] ?? $sq['query'] ?? '');
                $sqViolations = [];
                if ($sqSql !== '' && ! $validator->isSafe($sqSql, $sqViolations)) {
                    return ['error' => implode(' ', $sqViolations), ...$empty];
                }
            }
        }

        try {
            $tempView = new CustomView([
                'view_code' => $data['view_code'] ?? 'temp_preview',
                'view_name' => $data['view_name'] ?? 'Preview',
                'execution_mode' => $executionMode,
                'allowed_sources' => $allowedSources,
                'default_sources' => $defaultSources,
                'source_queries' => $sourceQueries,
                'sql_text' => $sqlText,
                'filters' => $filters,
            ]);

            if ($executionMode === CustomView::MODE_SINGLE_OR_UNION && isset($data['source_selections'])) {
                $tempView->source_selections = $data['source_selections'];
            }
            $selectedSources = $executionMode === CustomView::MODE_SINGLE_OR_UNION
                ? $tempView->defaultSourceCodes(DataSource::where('is_active', true)->orderBy('id')->pluck('code')->all())
                : [];
            $params = [];
            foreach ($filters as $f) {
                $pName = $f['name'] ?? $f['param_name'] ?? null;
                if ($pName) {
                    $params[$pName] = $f['default_value'] ?? null;
                }
            }

            $result = $reportEngine->preview($tempView, $selectedSources, $params, 10);

            return [
                'error' => null,
                'preview' => $result,
                'rows' => $result['rows'],
                'columns' => $result['columns'],
                'totalRows' => $result['totalRows'],
                'latencyMs' => $result['latency_ms'],
                'appliedParams' => $params,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), ...$empty];
        }
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'view_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return static::getRecordRouteBindingEloquentQuery()
            ->where('view_code', $key)
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', $key))
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomViews::route('/'),
            'create' => Pages\CreateCustomView::route('/create'),
            'edit' => Pages\EditCustomView::route('/{record}/edit'),
        ];
    }
}
