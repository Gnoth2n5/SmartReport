<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomViewResource\Pages;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\Sql\SqlParameterExtractor;
use App\Services\Sql\SqlSafetyValidator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
        return (Filament::auth()->user() ?? auth()->user())?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // 1. General Information
                Section::make('Thông tin chung')
                    ->description('Cấu hình định danh, tên gọi và phân loại báo cáo')
                    ->schema([
                        Grid::make(3)
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
                        Grid::make(2)
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
                    ->description('Chọn chế độ Single/Union DB hoặc Cross-Engine Federated Join')
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

                        // Mode A: Single or Union configuration
                        Grid::make(2)
                            ->schema([
                                Select::make('allowed_sources')
                                    ->label('Nguồn dữ liệu cho phép (Allowed Sources)')
                                    ->multiple()
                                    ->options(fn (): array => DataSource::where('is_active', true)->pluck('name', 'code')->toArray())
                                    ->preload()
                                    ->searchable()
                                    ->helperText('Danh sách các cơ sở dữ liệu được phép chọn khi chạy báo cáo.'),
                                Select::make('default_sources')
                                    ->label('Nguồn dữ liệu mặc định (Default Sources)')
                                    ->multiple()
                                    ->options(fn (): array => DataSource::where('is_active', true)->pluck('name', 'code')->toArray())
                                    ->preload()
                                    ->searchable()
                                    ->helperText('Nguồn dữ liệu được nạp mặc định ban đầu.'),
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
                                    ->placeholder('SELECT EMP_NO, FULL_NAME FROM APP.EMPLOYEES WHERE ...'),
                            ])
                            ->columns(3)
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
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Tên tham số (Parameter Name / @param)')
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
                                Grid::make(2)
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
                Section::make('Phân quyền truy cập (RBAC)')
                    ->description('Chỉ định các vai trò được phép xem báo cáo này')
                    ->schema([
                        TagsInput::make('roles')
                            ->label('Vai trò được phép')
                            ->suggestions(fn (): array => RolePermission::pluck('role_code')->toArray())
                            ->placeholder('Thêm vai trò (vd: super_admin, report_viewer)')
                            ->helperText('Để trống nếu chỉ cho phép Super Admin.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('view_code')
                    ->label('Mã báo cáo')
                    ->sortable()
                    ->searchable()
                    ->copyable(),
                TextColumn::make('view_name')
                    ->label('Tên báo cáo')
                    ->sortable()
                    ->searchable()
                    ->wrap(),
                TextColumn::make('menuGroup.title')
                    ->label('Nhóm Menu')
                    ->sortable()
                    ->badge()
                    ->placeholder('—'),
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
                    ->sortable(),
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
                static::getTestSqlPreviewAction('test_sql'),
                EditAction::make(),
                DeleteAction::make(),
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
        $data = [];

        if ($record instanceof CustomView) {
            $data = $record->toArray();
        }

        if ($livewire && method_exists($livewire, 'getForm') && $livewire->getForm('form')) {
            try {
                $raw = (array) $livewire->getForm('form')->getRawState();
                $data = array_merge($data, array_filter($raw, fn ($v) => $v !== null));
            } catch (\Throwable) {
                // Ignore fallback
            }
        } elseif ($livewire && isset($livewire->form)) {
            try {
                $raw = (array) $livewire->form->getRawState();
                $data = array_merge($data, array_filter($raw, fn ($v) => $v !== null));
            } catch (\Throwable) {
                // Ignore fallback
            }
        }

        if ($livewire && isset($livewire->data) && is_array($livewire->data)) {
            $data = array_merge($data, array_filter($livewire->data, fn ($v) => $v !== null));
        }

        if ($action->getSchemaComponent() !== null) {
            try {
                $get = $action->getSchemaComponent()->makeGetUtility();
                $data = array_merge($data, array_filter([
                    'view_code' => $get('view_code'),
                    'view_name' => $get('view_name'),
                    'execution_mode' => $get('execution_mode'),
                    'allowed_sources' => $get('allowed_sources'),
                    'default_sources' => $get('default_sources'),
                    'source_queries' => $get('source_queries'),
                    'sql_text' => $get('sql_text'),
                    'filters' => $get('filters'),
                    'roles' => $get('roles'),
                ], fn ($v) => $v !== null));
            } catch (\Throwable) {
                // Ignore fallback
            }
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
        $extractor = $extractor ?? app(SqlParameterExtractor::class);
        $data = static::resolveFormData($action, $livewire, $record);

        $sqlText = (string) ($data['sql_text'] ?? '');
        $executionMode = (string) ($data['execution_mode'] ?? CustomView::MODE_SINGLE_OR_UNION);
        $sourceQueries = (array) ($data['source_queries'] ?? []);
        $existingFilters = (array) ($data['filters'] ?? []);

        $sqls = [];
        if (trim($sqlText) !== '') {
            $sqls[] = $sqlText;
        }

        if ($executionMode === CustomView::MODE_FEDERATED_JOIN && is_array($sourceQueries)) {
            foreach ($sourceQueries as $sq) {
                $q = $sq['sql_text'] ?? $sq['query'] ?? '';
                if (trim($q) !== '') {
                    $sqls[] = $q;
                }
            }
        }

        if (empty($sqls)) {
            Notification::make()
                ->title('Chưa có câu lệnh SQL')
                ->body('Vui lòng nhập câu lệnh SQL trước khi nhận diện tham số.')
                ->warning()
                ->send();

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
            Notification::make()
                ->title('Không tìm thấy tham số')
                ->body('Không phát hiện tham số @tham_so nào trong câu lệnh SQL.')
                ->info()
                ->send();

            return;
        }

        // Index existing filters by lowercased parameter name to preserve user customizations
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
                $paramName = $existing['name'] ?? $existing['param_name'] ?? $cleanToken;
                $inputType = $existing['type'] ?? $existing['input_type'] ?? 'text';

                $existing['name'] = $paramName;
                $existing['param_name'] = $paramName;
                $existing['type'] = $inputType;
                $existing['input_type'] = $inputType;
                $existing['label'] = $existing['label'] ?? $cleanToken;
                $existing['is_required'] = (bool) ($existing['is_required'] ?? false);
                $newFilters[] = $existing;
            } else {
                $def = $extractor->inferParameterDefinition($cleanToken);
                $newFilters[] = [
                    'name' => $def['name'],
                    'param_name' => $def['name'],
                    'label' => $def['label'],
                    'type' => $def['type'],
                    'input_type' => $def['type'],
                    'default_value' => $def['default_value'],
                    'options' => $def['options'],
                    'is_required' => (bool) ($def['is_required'] ?? false),
                ];
            }
        }

        // Update form state
        if ($livewire) {
            if (isset($livewire->data) && is_array($livewire->data)) {
                $livewire->data['filters'] = $newFilters;
            }
            if (method_exists($livewire, 'getForm') && $livewire->getForm('form')) {
                $livewire->getForm('form')->fill([
                    ...($data ?? []),
                    'filters' => $newFilters,
                ]);
            } elseif (isset($livewire->form)) {
                $livewire->form->fill([
                    ...($data ?? []),
                    'filters' => $newFilters,
                ]);
            }
            if (isset($livewire->data) && is_array($livewire->data)) {
                $livewire->data['filters'] = $newFilters;
            }
        }

        if ($action->getSchemaComponent() !== null) {
            try {
                $set = $action->getSchemaComponent()->makeSetUtility();
                $set('filters', $newFilters);
            } catch (\Throwable) {
                // Ignore fallback
            }
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
    public static function getTestSqlPreviewAction(?string $name = 'preview_sql'): Action
    {
        return Action::make($name)
            ->label('Chạy thử SQL (Test 10 dòng)')
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading('Kết quả chạy thử SQL (10 dòng đầu tiên)')
            ->modalWidth('7xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->modalContent(function (Action $action, ?Livewire $livewire = null, ?Model $record = null, ?DuckDbReportEngine $reportEngine = null, ?SqlSafetyValidator $validator = null): View {
                $reportEngine = $reportEngine ?? app(DuckDbReportEngine::class);
                $validator = $validator ?? app(SqlSafetyValidator::class);

                $data = static::resolveFormData($action, $livewire, $record);
                $sqlText = (string) ($data['sql_text'] ?? '');
                $executionMode = (string) ($data['execution_mode'] ?? CustomView::MODE_SINGLE_OR_UNION);
                $allowedSources = (array) ($data['allowed_sources'] ?? []);
                $defaultSources = (array) ($data['default_sources'] ?? []);
                $sourceQueries = (array) ($data['source_queries'] ?? []);
                $filters = (array) ($data['filters'] ?? []);

                if (trim($sqlText) === '') {
                    return view('filament.components.sql-preview-modal', [
                        'error' => 'Vui lòng nhập câu lệnh SQL trước khi chạy thử.',
                        'rows' => [],
                        'columns' => [],
                        'totalRows' => 0,
                        'latencyMs' => 0,
                        'appliedParams' => [],
                    ]);
                }

                $violations = [];
                if (! $validator->isSafe($sqlText, $violations)) {
                    return view('filament.components.sql-preview-modal', [
                        'error' => implode(' ', $violations),
                        'rows' => [],
                        'columns' => [],
                        'totalRows' => 0,
                        'latencyMs' => 0,
                        'appliedParams' => [],
                    ]);
                }

                if ($executionMode === CustomView::MODE_FEDERATED_JOIN && is_array($sourceQueries)) {
                    foreach ($sourceQueries as $sq) {
                        $sqSql = $sq['sql_text'] ?? $sq['query'] ?? '';
                        $sqViolations = [];
                        if (! empty($sqSql) && ! $validator->isSafe($sqSql, $sqViolations)) {
                            return view('filament.components.sql-preview-modal', [
                                'error' => implode(' ', $sqViolations),
                                'rows' => [],
                                'columns' => [],
                                'totalRows' => 0,
                                'latencyMs' => 0,
                                'appliedParams' => [],
                            ]);
                        }
                    }
                }

                try {
                    $normSourceQueries = [];
                    foreach ($sourceQueries as $sq) {
                        $alias = $sq['alias'] ?? $sq['table_alias'] ?? '';
                        $sql = $sq['sql_text'] ?? $sq['query'] ?? '';
                        $normSourceQueries[] = [
                            'source_code' => $sq['source_code'] ?? '',
                            'alias' => $alias,
                            'table_alias' => $alias,
                            'sql_text' => $sql,
                            'query' => $sql,
                        ];
                    }

                    $tempView = new CustomView([
                        'view_code' => $data['view_code'] ?? 'temp_preview',
                        'view_name' => $data['view_name'] ?? 'Preview',
                        'execution_mode' => $executionMode,
                        'allowed_sources' => $allowedSources,
                        'default_sources' => $defaultSources,
                        'source_queries' => $normSourceQueries,
                        'sql_text' => $sqlText,
                        'filters' => $filters,
                    ]);

                    $selectedSources = ! empty($defaultSources) ? $defaultSources : $allowedSources;
                    if (empty($selectedSources) && $executionMode === CustomView::MODE_SINGLE_OR_UNION) {
                        $selectedSources = ['pg_north'];
                    }

                    $params = [];
                    foreach ($filters as $f) {
                        $pName = $f['name'] ?? $f['param_name'] ?? null;
                        if ($pName) {
                            $params[$pName] = $f['default_value'] ?? null;
                        }
                    }

                    $result = $reportEngine->preview($tempView, $selectedSources, $params, 10);

                    return view('filament.components.sql-preview-modal', [
                        'error' => null,
                        'preview' => $result,
                        'rows' => $result['rows'],
                        'columns' => $result['columns'],
                        'totalRows' => $result['totalRows'],
                        'latencyMs' => $result['latency_ms'],
                        'appliedParams' => $params,
                    ]);
                } catch (\Throwable $e) {
                    return view('filament.components.sql-preview-modal', [
                        'error' => $e->getMessage(),
                        'rows' => [],
                        'columns' => [],
                        'totalRows' => 0,
                        'latencyMs' => 0,
                        'appliedParams' => [],
                    ]);
                }
            })
            ->action(function (Action $action, ?Livewire $livewire = null, ?Model $record = null, ?DuckDbReportEngine $reportEngine = null, ?SqlSafetyValidator $validator = null): void {
                static::executeTestSqlPreview($livewire, $action, $record, $reportEngine, $validator);
            });
    }

    /**
     * Logic for Test SQL Preview Action.
     */
    public static function executeTestSqlPreview(?Livewire $livewire, Action $action, ?Model $record = null, ?DuckDbReportEngine $reportEngine = null, ?SqlSafetyValidator $validator = null): void
    {
        $reportEngine = $reportEngine ?? app(DuckDbReportEngine::class);
        $validator = $validator ?? app(SqlSafetyValidator::class);

        $data = static::resolveFormData($action, $livewire, $record);
        $sqlText = (string) ($data['sql_text'] ?? '');
        $executionMode = (string) ($data['execution_mode'] ?? CustomView::MODE_SINGLE_OR_UNION);
        $allowedSources = (array) ($data['allowed_sources'] ?? []);
        $defaultSources = (array) ($data['default_sources'] ?? []);
        $sourceQueries = (array) ($data['source_queries'] ?? []);
        $filters = (array) ($data['filters'] ?? []);

        if (trim($sqlText) === '') {
            Notification::make()
                ->title('Chưa có câu lệnh SQL')
                ->body('Vui lòng nhập câu lệnh SQL trước khi chạy thử.')
                ->warning()
                ->send();

            return;
        }

        $violations = [];
        if (! $validator->isSafe($sqlText, $violations)) {
            Notification::make()
                ->title('Truy vấn không an toàn')
                ->body(implode(' ', $violations))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($executionMode === CustomView::MODE_FEDERATED_JOIN && is_array($sourceQueries)) {
            foreach ($sourceQueries as $sq) {
                $sqSql = $sq['sql_text'] ?? $sq['query'] ?? '';
                $sqViolations = [];
                if (! empty($sqSql) && ! $validator->isSafe($sqSql, $sqViolations)) {
                    Notification::make()
                        ->title('Truy vấn nguồn không an toàn')
                        ->body(implode(' ', $sqViolations))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }
            }
        }

        try {
            $normSourceQueries = [];
            foreach ($sourceQueries as $sq) {
                $alias = $sq['alias'] ?? $sq['table_alias'] ?? '';
                $sql = $sq['sql_text'] ?? $sq['query'] ?? '';
                $normSourceQueries[] = [
                    'source_code' => $sq['source_code'] ?? '',
                    'alias' => $alias,
                    'table_alias' => $alias,
                    'sql_text' => $sql,
                    'query' => $sql,
                ];
            }

            $tempView = new CustomView([
                'view_code' => $data['view_code'] ?? 'temp_preview',
                'view_name' => $data['view_name'] ?? 'Preview',
                'execution_mode' => $executionMode,
                'allowed_sources' => $allowedSources,
                'default_sources' => $defaultSources,
                'source_queries' => $normSourceQueries,
                'sql_text' => $sqlText,
                'filters' => $filters,
            ]);

            $selectedSources = ! empty($defaultSources) ? $defaultSources : $allowedSources;
            if (empty($selectedSources) && $executionMode === CustomView::MODE_SINGLE_OR_UNION) {
                $selectedSources = ['pg_north'];
            }

            $params = [];
            foreach ($filters as $f) {
                $pName = $f['name'] ?? $f['param_name'] ?? null;
                if ($pName) {
                    $params[$pName] = $f['default_value'] ?? null;
                }
            }

            $result = $reportEngine->preview($tempView, $selectedSources, $params, 10);

            Notification::make()
                ->title('Chạy thử thành công')
                ->body("Tổng số dòng: {$result['totalRows']} | Thời gian: {$result['latency_ms']} ms | Cột: ".implode(', ', array_slice($result['columns'], 0, 5)))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lỗi chạy thử SQL')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'view_code';
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        $query = static::getRecordRouteBindingEloquentQuery();

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        return $query->where(function ($q) use ($key): void {
            $q->where('view_code', $key);
            if (is_numeric($key)) {
                $q->orWhere('id', $key);
            }
        })->first();
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
