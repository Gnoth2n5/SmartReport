<?php

namespace App\Filament\Pages;

use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\SnapshotManager;
use App\Services\Export\DuckDbExporter;
use App\Services\Navigation\DynamicSidebarBuilder;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportRunnerPage extends Page
{
    protected static ?string $slug = 'reports';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.report-runner-page';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public string $viewCode = '';

    #[Locked]
    public ?string $snapshotId = null;

    /** @var list<string>|null */
    #[Locked]
    public ?array $snapshotSourceCodes = null;

    #[Locked]
    public int $currentPage = 1;

    #[Locked]
    public int $perPage = 10;

    #[Locked]
    public int $totalRows = 0;

    #[Locked]
    public int $totalPages = 1;

    #[Locked]
    public bool $hasNextPage = false;

    /**
     * @var list<string>
     */
    #[Locked]
    public array $columns = [];

    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $rows = [];

    /**
     * @var list<string>
     */
    public array $selectedSources = [];

    /**
     * @var array<string, mixed>
     */
    public array $filterValues = [];

    #[Locked]
    public float $executionLatencyMs = 0.0;

    public string $activeTab = 'overview';

    #[Locked]
    public string $rawSqlText = '';

    #[Locked]
    public string $compiledFederatedSql = '';

    /**
     * @var list<array{source_code: string, source_name: string, driver: string, alias: string, sql: string}>
     */
    #[Locked]
    public array $individualSourceQueries = [];

    #[Locked]
    public ?string $snapshotCreatedAt = null;

    #[Locked]
    public ?string $executionError = null;

    protected ?CustomView $cachedView = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/reports/{viewCode}';
    }

    public function mount(string $viewCode): void
    {
        $view = $this->resolveCustomView($viewCode);
        $this->authorizeViewAccess($view);

        $this->viewCode = $view->view_code;
        $this->perPage = max(1, (int) config('smartreport.page_size', 10));
        $this->rawSqlText = (string) $view->sql_text;

        $this->initializeDefaults($view);
        $this->runReport();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->resolveCustomView()->view_name;
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->resolveCustomView()->view_name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->resolveCustomView()->description;
    }

    public function getCustomView(): CustomView
    {
        return $this->resolveCustomView();
    }

    public function setActiveTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'app_info', 'sql_inspector'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function updatedSelectedSources(): void
    {
        $this->selectedSources = $this->sanitizeScalarList($this->selectedSources);
    }

    public function updatedFilterValues(): void
    {
        $this->filterValues = $this->sanitizeScalarFilterValues($this->filterValues);
    }

    public function runReport(): void
    {
        $view = $this->resolveCustomView();
        $this->authorizeViewAccess($view);

        $this->executionError = null;
        $this->resetValidation();

        $this->selectedSources = $this->sanitizeScalarList($this->selectedSources);
        $this->filterValues = $this->sanitizeScalarFilterValues($this->filterValues);

        $normalizedFilters = $this->getNormalizedFilters($view);
        foreach ($normalizedFilters as $filter) {
            $name = $filter['name'];
            $value = $this->filterValues[$name] ?? null;

            if ($filter['is_required'] && ($value === null || $value === '')) {
                $this->resetSnapshotState();
                $message = "Tham số [{$filter['label']}] là bắt buộc.";
                $this->addError("filterValues.{$name}", $message);
                $this->executionError = $message;
                Notification::make()
                    ->title('Thiếu tham số bắt buộc')
                    ->body($message)
                    ->danger()
                    ->send();

                return;
            }

            if ($filter['type'] === 'number' && $value !== null && $value !== '' && ! is_numeric($value)) {
                $this->resetSnapshotState();
                $message = "Tham số [{$filter['label']}] phải là giá trị số hợp lệ.";
                $this->addError("filterValues.{$name}", $message);
                $this->executionError = $message;
                Notification::make()
                    ->title('Giá trị tham số không hợp lệ')
                    ->body($message)
                    ->danger()
                    ->send();

                return;
            }
        }

        $sourcesToUse = [];
        if ($view->isSingleOrUnion()) {
            $permitted = $this->getPermittedSourceCodes($view);
            $sourcesToUse = array_values(array_intersect($this->selectedSources, $permitted));
            $this->selectedSources = $sourcesToUse;

            if (empty($sourcesToUse)) {
                $this->resetSnapshotState();
                $this->executionError = 'Vui lòng chọn ít nhất một nguồn dữ liệu.';
                Notification::make()
                    ->title('Chưa chọn nguồn dữ liệu')
                    ->body($this->executionError)
                    ->warning()
                    ->send();

                return;
            }
        }

        $params = $this->filterValues;

        /** @var DuckDbReportEngine $engine */
        $engine = app(DuckDbReportEngine::class);
        /** @var SnapshotManager $snapshotManager */
        $snapshotManager = app(SnapshotManager::class);

        try {
            $inspection = $engine->buildInspectionData($view, $sourcesToUse, $params);
            $this->rawSqlText = $inspection['raw_sql'];
            $this->individualSourceQueries = $inspection['individual_queries'];
            $this->compiledFederatedSql = $inspection['federated_sql'];
        } catch (Throwable) {
            // Keep prior inspection data if compilation fails before execution error reporting
        }

        $oldSnapshotId = $this->snapshotId;
        $start = microtime(true);

        try {
            $newSnapshotId = $engine->executeAndCreateSnapshot($view, $sourcesToUse, $params);
            $this->executionLatencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($oldSnapshotId !== null && $oldSnapshotId !== '' && $oldSnapshotId !== $newSnapshotId) {
                $snapshotManager->deleteSnapshot($oldSnapshotId);
            }

            $this->snapshotId = $newSnapshotId;
            $this->snapshotSourceCodes = $view->isSingleOrUnion()
                ? $sourcesToUse
                : $this->sanitizeScalarList(array_column($view->source_queries ?? [], 'source_code'));
            $meta = $snapshotManager->getMetadata($newSnapshotId);
            $this->snapshotCreatedAt = isset($meta['created_at']) ? (string) $meta['created_at'] : now('UTC')->toDateTimeString();

            $perPage = max(1, $this->perPage);
            $this->perPage = $perPage;
            $this->totalPages = max(1, (int) ceil(((int) ($meta['total_rows'] ?? 0)) / $perPage));
            $this->loadPage(1);
        } catch (Throwable $e) {
            if ($oldSnapshotId !== null && $oldSnapshotId !== '') {
                $snapshotManager->deleteSnapshot($oldSnapshotId);
            }
            $this->executionLatencyMs = round((microtime(true) - $start) * 1000, 2);
            $this->executionError = $e->getMessage();
            $this->resetSnapshotState();

            Notification::make()
                ->title('Lỗi thực thi báo cáo')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function loadPage(int $page): void
    {
        $this->authorizeViewAccess($this->resolveCustomView(), checkSnapshotSources: true);

        /** @var SnapshotManager $snapshotManager */
        $snapshotManager = app(SnapshotManager::class);

        if (blank($this->snapshotId) || ! $snapshotManager->snapshotExists($this->snapshotId)) {
            $this->resetSnapshotState();
            Notification::make()
                ->title('Snapshot đã hết hạn hoặc không tồn tại')
                ->body('Vui lòng nhấn "Tra cứu" để tải lại dữ liệu.')
                ->warning()
                ->send();

            return;
        }

        $targetPage = max(1, $page);
        if ($this->totalPages > 0) {
            $targetPage = min($targetPage, $this->totalPages);
        }

        $perPage = max(1, $this->perPage);
        $this->perPage = $perPage;
        $pageData = $snapshotManager->getPage($this->snapshotId, $targetPage, $perPage);

        $this->rows = $pageData['rows'];
        $this->columns = $pageData['columns'];
        $this->totalRows = $pageData['totalRows'];
        $this->currentPage = $pageData['page'];
        $this->totalPages = max(1, (int) $pageData['totalPages']);
        $this->hasNextPage = $pageData['hasNextPage'];
    }

    public function resetFilters(): void
    {
        $view = $this->resolveCustomView();
        $this->authorizeViewAccess($view);

        $this->initializeDefaults($view);
        $this->runReport();
    }

    public function exportExcel(): ?BinaryFileResponse
    {
        return $this->exportSnapshot('xlsx');
    }

    public function exportCsv(): ?BinaryFileResponse
    {
        return $this->exportSnapshot('csv');
    }

    /**
     * @return list<array{name: string, label: string, type: string, default_value: mixed, options: array<string, string>, is_required: bool}>
     */
    public function getNormalizedFilters(?CustomView $view = null): array
    {
        $view ??= $this->resolveCustomView();
        $filters = is_array($view->filters) ? $view->filters : [];
        $normalized = [];

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $name = (string) ($filter['name'] ?? $filter['param_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $type = (string) ($filter['type'] ?? $filter['input_type'] ?? 'text');
            $label = (string) ($filter['label'] ?? $name);
            $default = $filter['default_value'] ?? null;
            $rawOptions = is_array($filter['options'] ?? null) ? $filter['options'] : [];

            $options = [];
            if (array_is_list($rawOptions)) {
                foreach ($rawOptions as $opt) {
                    if (is_array($opt)) {
                        $optVal = (string) ($opt['value'] ?? $opt['key'] ?? '');
                        $optLabel = (string) ($opt['label'] ?? $optVal);
                        if ($optVal !== '') {
                            $options[$optVal] = $optLabel;
                        }
                    } elseif (is_scalar($opt)) {
                        $options[(string) $opt] = (string) $opt;
                    }
                }
            } else {
                foreach ($rawOptions as $optVal => $optLabel) {
                    $options[(string) $optVal] = (string) $optLabel;
                }
            }

            $normalized[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'default_value' => $default,
                'options' => $options,
                'is_required' => (bool) ($filter['is_required'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @return Collection<int, DataSource>
     */
    public function getAllowedSourcesList(): Collection
    {
        $view = $this->resolveCustomView();
        $permitted = $this->getPermittedSourceCodes($view);

        if (empty($permitted)) {
            return new Collection;
        }

        return DataSource::query()
            ->whereIn('code', $permitted)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, DataSource>
     */
    public function getConnectedDataSources(): Collection
    {
        $view = $this->resolveCustomView();

        if ($view->isSingleOrUnion()) {
            $permitted = $this->getPermittedSourceCodes($view);
            $selected = $this->sanitizeScalarList($this->selectedSources);

            if (! empty($selected)) {
                $codes = array_values(array_intersect($selected, $permitted));
            } else {
                $defaults = $this->sanitizeScalarList((array) ($view->default_sources ?: []));
                $codes = ! empty($defaults)
                    ? array_values(array_intersect($defaults, $permitted))
                    : $permitted;
            }
        } else {
            $codes = [];
            foreach ($view->source_queries ?? [] as $sq) {
                if (! empty($sq['source_code'])) {
                    $codes[] = (string) $sq['source_code'];
                }
            }
            $codes = array_values(array_unique($codes));
            $codes = $this->filterSourcesByRolePermissions($codes);
        }

        if (empty($codes)) {
            return new Collection;
        }

        return DataSource::query()
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{ttl_minutes: int, created_at: string, expires_at_unix: int, remaining_seconds: int}
     */
    public function getSnapshotHealth(): array
    {
        $ttlMinutes = max(1, (int) config('smartreport.snapshot_ttl_minutes', 30));

        if (blank($this->snapshotCreatedAt)) {
            return [
                'ttl_minutes' => $ttlMinutes,
                'created_at' => '—',
                'expires_at_unix' => 0,
                'remaining_seconds' => 0,
            ];
        }

        try {
            $created = Carbon::parse($this->snapshotCreatedAt, 'UTC')->utc();
        } catch (Throwable) {
            $created = now('UTC');
        }

        $expiresAt = $created->copy()->addMinutes($ttlMinutes);
        $remainingSeconds = max(0, $expiresAt->timestamp - now('UTC')->timestamp);

        return [
            'ttl_minutes' => $ttlMinutes,
            'created_at' => $created->copy()->utc()->format('Y-m-d H:i:s').' UTC',
            'expires_at_unix' => $expiresAt->timestamp,
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    protected function exportSnapshot(string $format): ?BinaryFileResponse
    {
        $view = $this->resolveCustomView();
        $this->authorizeViewAccess($view, checkSnapshotSources: true);

        /** @var SnapshotManager $snapshotManager */
        $snapshotManager = app(SnapshotManager::class);
        if (blank($this->snapshotId) || ! $snapshotManager->snapshotExists($this->snapshotId)) {
            $this->resetSnapshotState();
            Notification::make()
                ->title('Snapshot không khả dụng')
                ->body('Snapshot đã hết hạn hoặc chưa được tạo. Vui lòng nhấn "Tra cứu" trước khi xuất dữ liệu.')
                ->warning()
                ->send();

            return null;
        }

        $exportDir = storage_path('app/exports');
        File::ensureDirectoryExists($exportDir);

        $timestamp = now()->format('Ymd_His');
        $uniqueName = "{$this->viewCode}_{$timestamp}_".Str::lower(Str::random(6));
        $targetPath = "{$exportDir}/{$uniqueName}.{$format}";
        $downloadFilename = "{$this->viewCode}_{$timestamp}.{$format}";

        /** @var DuckDbExporter $exporter */
        $exporter = app(DuckDbExporter::class);

        try {
            if ($format === 'xlsx') {
                $exporter->exportExcel($this->snapshotId, $targetPath);
                $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $exporter->exportCsv($this->snapshotId, $targetPath);
                $contentType = 'text/csv; charset=UTF-8';
            }

            return response()->download($targetPath, $downloadFilename, [
                'Content-Type' => $contentType,
            ])->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            if (file_exists($targetPath)) {
                @unlink($targetPath);
            }

            Notification::make()
                ->title('Lỗi xuất dữ liệu')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    protected function resolveCustomView(?string $viewCode = null): CustomView
    {
        $code = $viewCode ?? $this->viewCode;

        if ($this->cachedView !== null && $this->cachedView->view_code === $code) {
            return $this->cachedView;
        }

        $this->cachedView = CustomView::with('menuGroup')
            ->where('view_code', $code)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->cachedView;
    }

    protected function authorizeViewAccess(CustomView $view, bool $checkSnapshotSources = false): void
    {
        $user = User::current() ?? abort(403);

        $permissions = RolePermission::query()
            ->whereIn('role_code', $user->roles ?? [])
            ->get();

        abort_unless(
            app(DynamicSidebarBuilder::class)->canUserAccessView($user, $view, $permissions),
            403
        );

        if ($view->isFederatedJoin()) {
            $requiredSources = array_values(array_unique(array_column($view->source_queries ?? [], 'source_code')));
            abort_unless(count($this->filterSourcesByRolePermissions($requiredSources)) === count($requiredSources), 403);
        }

        if ($checkSnapshotSources && $this->snapshotId !== null) {
            abort_unless($this->snapshotSourceCodes !== null, 403);
            abort_unless(count($this->filterSourcesByRolePermissions($this->snapshotSourceCodes)) === count($this->snapshotSourceCodes), 403);
        }
    }

    protected function initializeDefaults(CustomView $view): void
    {
        if ($view->isSingleOrUnion()) {
            $permitted = $this->getPermittedSourceCodes($view);
            $defaults = $this->sanitizeScalarList((array) ($view->default_sources ?? []));
            $defaults = array_values(array_intersect($defaults, $permitted));

            $this->selectedSources = ! empty($defaults)
                ? $defaults
                : (! empty($permitted) ? [$permitted[0]] : []);
        } else {
            $codes = [];
            foreach ($view->source_queries ?? [] as $sq) {
                if (! empty($sq['source_code'])) {
                    $codes[] = (string) $sq['source_code'];
                }
            }
            $this->selectedSources = $this->filterSourcesByRolePermissions(array_values(array_unique($codes)));
        }

        $this->filterValues = [];
        foreach ($this->getNormalizedFilters($view) as $filter) {
            $name = $filter['name'];
            $default = $filter['default_value'];

            if (is_string($default) && strtolower(trim($default)) === '@today') {
                $default = now()->format('Y-m-d');
            } elseif (strtolower($name) === 'today' && ($default === null || $default === '')) {
                $default = now()->format('Y-m-d');
            }

            $this->filterValues[$name] = is_scalar($default) || $default === null ? $default : null;
        }
    }

    protected function resetSnapshotState(): void
    {
        $this->snapshotId = null;
        $this->snapshotSourceCodes = null;
        $this->columns = [];
        $this->rows = [];
        $this->totalRows = 0;
        $this->currentPage = 1;
        $this->totalPages = 1;
        $this->hasNextPage = false;
        $this->snapshotCreatedAt = null;
    }

    /**
     * @return list<string>
     */
    protected function getPermittedSourceCodes(CustomView $view): array
    {
        $viewAllowed = $this->sanitizeScalarList((array) ($view->allowed_sources ?? []));

        $query = DataSource::query()->where('is_active', true)->orderBy('id');
        if (! empty($viewAllowed)) {
            $query->whereIn('code', $viewAllowed);
        }

        $activeCodes = $query->pluck('code')->map(fn ($c): string => (string) $c)->all();

        return $this->filterSourcesByRolePermissions($activeCodes);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    protected function filterSourcesByRolePermissions(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $user = User::current();
        if (! $user) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return array_values($codes);
        }

        $permissions = RolePermission::query()
            ->whereIn('role_code', $user->roles ?? [])
            ->get();

        return array_values(array_filter(
            $codes,
            function (string $code) use ($permissions): bool {
                foreach ($permissions as $permission) {
                    if ($permission->allowsSource($code)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * @return list<string>
     */
    protected function sanitizeScalarList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $str = trim((string) $value);
                if ($str !== '' && ! in_array($str, $clean, true)) {
                    $clean[] = $str;
                }
            }
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sanitizeScalarFilterValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $clean[(string) $key] = (is_scalar($value) || $value === null) ? $value : null;
        }

        return $clean;
    }
}
