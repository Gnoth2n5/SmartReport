<?php

namespace App\Filament\Pages;

use App\Exceptions\SqlSafetyException;
use App\Models\DataSource;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\SnapshotManager;
use App\Services\Export\DuckDbExporter;
use App\Services\Sql\SqlSafetyValidator;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

class SqlExportPage extends Page
{
    protected static ?string $slug = 'sql-export';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Quản Trị Hệ Thống';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Xuất SQL (Ad-hoc)';

    protected static ?string $title = 'Xuất dữ liệu SQL (Ad-hoc Export)';

    protected string $view = 'filament.pages.sql-export-page';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (Filament::auth()->user() ?? auth()->user())?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'data_source' => 'federated',
            'sql_statement' => '',
            'export_format' => 'xlsx',
            'split_file' => false,
            'chunk_row_threshold' => '100000',
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Truy vấn & Cấu hình xuất dữ liệu')
                ->description('Thực thi câu lệnh SELECT/WITH trực tiếp qua DuckDB và xuất kết quả ra tệp Excel (.xlsx), CSV (UTF-8 BOM) hoặc ZIP chia nhỏ.')
                ->icon('heroicon-o-command-line')
                ->schema([
                    Select::make('data_source')
                        ->label('Data Source')
                        ->options(fn (): array => $this->getDataSourceOptions())
                        ->required()
                        ->searchable(),

                    Textarea::make('sql_statement')
                        ->label('SQL Statement')
                        ->placeholder('SELECT contract_no, customer_name, total_amount FROM public.v_epcontract ORDER BY contract_no;')
                        ->required()
                        ->rows(8)
                        ->extraInputAttributes([
                            'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.875rem;',
                        ]),

                    Grid::make(['default' => 1, 'md' => 3])
                        ->schema([
                            Radio::make('export_format')
                                ->label('Export Format')
                                ->options([
                                    'xlsx' => 'Excel (.xlsx)',
                                    'csv' => 'CSV (.csv)',
                                ])
                                ->default('xlsx')
                                ->required()
                                ->inline(),

                            Toggle::make('split_file')
                                ->label('Split File (.zip)')
                                ->default(false)
                                ->live()
                                ->helperText('Đóng gói và chia nhỏ nhiều file trong một tệp .zip.'),

                            Select::make('chunk_row_threshold')
                                ->label('Chunk Row Threshold')
                                ->options([
                                    '10' => '10 dòng (Test)',
                                    '100000' => '100,000 dòng',
                                    '500000' => '500,000 dòng',
                                    '1000000' => '1,000,000 dòng',
                                ])
                                ->default('100000')
                                ->required()
                                ->helperText('Chỉ áp dụng khi bật Split File (.zip).'),
                        ]),
                ]),
        ]);
    }

    public function export(
        DuckDbReportEngine $engine,
        DuckDbExporter $exporter,
        SnapshotManager $snapshotManager,
        SqlSafetyValidator $validator
    ): ?BinaryFileResponse {
        $state = $this->form->getState();

        $dataSource = (string) ($state['data_source'] ?? 'federated');
        $sql = (string) ($state['sql_statement'] ?? '');
        $format = strtolower((string) ($state['export_format'] ?? 'xlsx'));
        $splitFile = (bool) ($state['split_file'] ?? false);
        $chunkRows = max(1, (int) ($state['chunk_row_threshold'] ?? 100000));

        try {
            $validator->validate($sql);
        } catch (SqlSafetyException $e) {
            $this->addError('data.sql_statement', $e->getMessage());

            Notification::make()
                ->title('Truy vấn không an toàn')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return null;
        }

        $snapshotId = null;
        $targetPath = null;

        try {
            $snapshotId = $engine->executeAdhocAndCreateSnapshot($sql, $dataSource);

            $exportDir = storage_path('app/exports');
            File::ensureDirectoryExists($exportDir);

            $baseName = 'smartreport_sql_export_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(6));

            if ($splitFile) {
                $targetPath = "{$exportDir}/{$baseName}.zip";
                $exporter->exportZip($snapshotId, $format, $chunkRows, $targetPath);
                $downloadName = "{$baseName}.zip";
                $contentType = 'application/zip';
            } elseif ($format === 'xlsx') {
                $targetPath = "{$exportDir}/{$baseName}.xlsx";
                $exporter->exportExcel($snapshotId, $targetPath);
                $downloadName = "{$baseName}.xlsx";
                $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $targetPath = "{$exportDir}/{$baseName}.csv";
                $exporter->exportCsv($snapshotId, $targetPath);
                $downloadName = "{$baseName}.csv";
                $contentType = 'text/csv; charset=UTF-8';
            }

            return response()->download($targetPath, $downloadName, [
                'Content-Type' => $contentType,
            ])->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            if ($targetPath !== null && file_exists($targetPath)) {
                @unlink($targetPath);
            }

            Notification::make()
                ->title('Lỗi thực thi truy vấn SQL')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return null;
        } finally {
            if ($snapshotId !== null) {
                $snapshotManager->deleteSnapshot($snapshotId);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function getDataSourceOptions(): array
    {
        $options = [
            'federated' => 'Federated (All Sources)',
        ];

        $sources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($sources as $source) {
            $options[$source->code] = "{$source->name} ({$source->code} - {$source->driver})";
        }

        return $options;
    }
}
