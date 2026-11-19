<?php

namespace Tests\Feature;

use App\Exceptions\SqlSafetyException;
use App\Filament\Pages\ReportRunnerPage;
use App\Filament\Pages\SqlExportPage;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\DuckDbSourceAdapter;
use App\Services\DuckDb\SnapshotManager;
use App\Services\Sql\SqlSafetyValidator;
use Carbon\Carbon;
use Database\Seeders\SmartReportSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ReportRunnerAndSqlExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $adminUser;

    protected User $viewerUser;

    protected string $testArtifactsDir;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->testArtifactsDir = storage_path('app/test_artifacts');
        File::ensureDirectoryExists($this->testArtifactsDir);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/snapshots/*.duckdb')) ?: [] as $snapshotFile) {
            @unlink($snapshotFile);
        }

        if (File::isDirectory($this->testArtifactsDir)) {
            File::deleteDirectory($this->testArtifactsDir);
        }

        parent::tearDown();
    }

    protected function seedSqliteOnly(): void
    {

        $this->seed(SmartReportSeeder::class);
        $this->initUsers();
    }

    protected function initUsers(): void
    {
        $this->superAdmin = User::where('email', 'admin@smartreport.test')->firstOrFail();
        $this->adminUser = User::factory()->create([
            'name' => 'Operations Admin',
            'email' => 'ops-admin@smartreport.test',
            'roles' => ['admin'],
        ]);
        $this->viewerUser = User::factory()->create([
            'name' => 'Report Viewer',
            'email' => 'viewer@smartreport.test',
            'roles' => ['report_viewer'],
        ]);
    }

    protected function switchUser(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    /**
     * @return array{name: string, content: string, contentType: ?string}
     */
    protected function extractDownload(Testable $component, ?string $expectedExtension = null): array
    {
        $component->assertFileDownloaded();

        $download = data_get($component->effects, 'download');
        $this->assertIsArray($download);

        $name = (string) data_get($download, 'name');
        $binary = base64_decode((string) data_get($download, 'content'), true);
        $this->assertIsString($binary);
        $this->assertNotSame('', $binary);

        if ($expectedExtension !== null) {
            $this->assertStringEndsWith(".{$expectedExtension}", $name);
        }

        return [
            'name' => $name,
            'content' => $binary,
            'contentType' => data_get($download, 'contentType'),
        ];
    }

    public function test_guest_is_redirected_to_login_for_report_runner_and_sql_export(): void
    {
        $this->seedSqliteOnly();

        $this->get('/admin/reports/pg-multi-plant-contracts')
            ->assertRedirect('/admin/login');

        $this->get('/admin/sql-export')
            ->assertRedirect('/admin/login');
    }

    public function test_report_runner_returns_403_for_unauthorized_viewer_and_404_for_missing_or_inactive_view(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->viewerUser);

        $this->get('/admin/reports/federated-3way-executive-report')
            ->assertForbidden();

        $this->get('/admin/reports/join-oracle-mssql-productivity')
            ->assertForbidden();

        Livewire::test(ReportRunnerPage::class, ['viewCode' => 'federated-3way-executive-report'])
            ->assertForbidden();

        $this->get('/admin/reports/nonexistent-view-code')
            ->assertNotFound();

        CustomView::where('view_code', 'pg-multi-plant-contracts')->update(['is_active' => false]);

        $this->switchUser($this->superAdmin)
            ->get('/admin/reports/pg-multi-plant-contracts')
            ->assertNotFound();
    }

    public function test_report_runner_returns_200_for_authorized_report_viewer(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->viewerUser);

        $this->get('/admin/reports/pg-multi-plant-contracts')
            ->assertSuccessful()
            ->assertSee('Hợp Đồng Xuất Khẩu Đa Chi Nhánh (Bắc & Nam)');

        $this->get('/admin/reports/oracle-hr-directory')
            ->assertSuccessful()
            ->assertSee('Hồ Sơ Nhân Sự & Bậc Lương Nhà Máy (Oracle 23ai)');
    }

    public function test_sql_export_rbac_and_dynamic_sidebar_visibility(): void
    {
        $this->seedSqliteOnly();

        // report_viewer is blocked from SqlExportPage and Quản Trị Hệ Thống
        $this->actingAs($this->viewerUser);
        $this->assertFalse(SqlExportPage::canAccess());
        $this->get('/admin/sql-export')->assertForbidden();
        Livewire::test(SqlExportPage::class)->assertForbidden();

        $viewerNav = Filament::getPanel('admin')->getNavigation();
        $viewerGroupLabels = array_map(fn (NavigationGroup $g): string => (string) $g->getLabel(), $viewerNav);
        $this->assertNotContains('Quản Trị Hệ Thống', $viewerGroupLabels);

        // admin can access SqlExportPage and sees it in Quản Trị Hệ Thống, while DataSources remains super_admin only
        $this->switchUser($this->adminUser);
        $this->assertTrue(SqlExportPage::canAccess());
        $this->get('/admin/sql-export')->assertSuccessful();
        $this->get('/admin/data-sources')->assertForbidden();

        $adminNav = Filament::getPanel('admin')->getNavigation();
        $adminUrls = [];
        foreach ($adminNav as $group) {
            foreach ($group->getItems() as $item) {
                $adminUrls[] = $item->getUrl();
            }
        }
        $this->assertContains(url('/admin/sql-export'), $adminUrls);

        // super_admin can access SqlExportPage and sees it in Quản Trị Hệ Thống
        $this->switchUser($this->superAdmin);
        $this->assertTrue(SqlExportPage::canAccess());
        $this->get('/admin/sql-export')->assertSuccessful();

        $superAdminNav = Filament::getPanel('admin')->getNavigation();
        $superAdminUrls = [];
        foreach ($superAdminNav as $group) {
            foreach ($group->getItems() as $item) {
                $superAdminUrls[] = $item->getUrl();
            }
        }
        $this->assertNotContains(url('/admin/sql-export'), $superAdminUrls);
        $this->get('/admin/custom-views')->assertSuccessful()->assertSee('Công cụ SQL');
    }

    public function test_report_runner_single_or_union_source_switching(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'pg-multi-plant-contracts'])
            ->assertSet('selectedSources', ['pg_north'])
            ->assertSet('totalRows', 25);

        $columnsSingle = $component->get('columns');
        $this->assertNotContains('_source_db', $columnsSingle);

        $component
            ->set('selectedSources', ['pg_north', 'pg_south'])
            ->call('runReport')
            ->assertSet('totalRows', 45);

        $columnsUnion = $component->get('columns');
        $this->assertContains('_source_db', $columnsUnion);
        $component->assertSee('pg_north');
    }

    public function test_report_runner_dynamic_param_injection_today_expansion_and_reset(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        // (a) Filter existing view by Status
        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'pg-multi-plant-contracts'])
            ->set('filterValues.Status', 'COMPLETED')
            ->call('runReport')
            ->assertSet('totalRows', 12);

        foreach ($component->get('rows') as $row) {
            $this->assertSame('COMPLETED', $row['status']);
        }

        // (b) CustomView with @today default and dual-key filter schema (param_name / input_type)
        CustomView::create([
            'view_code' => 'test-today-dual-key-view',
            'view_name' => 'Báo Cáo Kiểm Thử @today & Reset',
            'menu_group_code' => 'logistics',
            'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
            'allowed_sources' => ['pg_north'],
            'default_sources' => ['pg_north'],
            'sql_text' => 'SELECT contract_no, customer_name, status, sign_date FROM public.v_epcontract WHERE (@Status IS NULL OR status = @Status) AND (@EndDate IS NULL OR sign_date <= @EndDate) ORDER BY contract_no',
            'filters' => [
                [
                    'param_name' => 'EndDate',
                    'label' => 'Đến ngày',
                    'input_type' => 'date',
                    'default_value' => '@today',
                ],
                [
                    'name' => 'Status',
                    'label' => 'Trạng thái',
                    'type' => 'select',
                    'default_value' => null,
                    'options' => [
                        'COMPLETED' => 'Hoàn tất',
                        'CANCELLED' => 'Đã hủy',
                    ],
                ],
            ],
            'version' => '1.0.0',
            'is_active' => true,
        ]);

        $today = now()->format('Y-m-d');

        $todayComponent = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'test-today-dual-key-view'])
            ->assertSet('filterValues.EndDate', $today)
            ->assertSet('filterValues.Status', null)
            ->assertSet('totalRows', 25);

        $todayComponent
            ->set('filterValues.EndDate', '2020-01-01')
            ->set('filterValues.Status', 'CANCELLED')
            ->call('runReport')
            ->assertSet('totalRows', 0);

        $todayComponent
            ->call('resetFilters')
            ->assertSet('filterValues.EndDate', $today)
            ->assertSet('filterValues.Status', null)
            ->assertSet('totalRows', 25);
    }

    public function test_report_runner_snapshot_pagination_never_queries_external_dbs(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'pg-multi-plant-contracts'])
            ->set('selectedSources', ['pg_north', 'pg_south'])
            ->call('runReport')
            ->assertSet('totalRows', 45)
            ->assertSet('currentPage', 1)
            ->assertSet('hasNextPage', true)
            ->assertSee('Trang 1 / 5 (Tổng số: 45 dòng)');

        $this->assertNotEmpty($component->get('snapshotId'));
        $page1Rows = $component->get('rows');
        $this->assertCount(10, $page1Rows);
        $page1Contracts = array_column($page1Rows, 'contract_no');

        // Scramble external DB hosts/ports so any external query would fail
        DataSource::query()->update([
            'host' => '192.0.2.1',
            'port' => 65534,
        ]);

        $component
            ->call('loadPage', 2)
            ->assertSet('currentPage', 2)
            ->assertSee('Trang 2 / 5 (Tổng số: 45 dòng)');

        $page2Rows = $component->get('rows');
        $this->assertCount(10, $page2Rows);
        $page2Contracts = array_column($page2Rows, 'contract_no');
        $this->assertEmpty(array_intersect($page1Contracts, $page2Contracts));

        $component
            ->call('loadPage', 5)
            ->assertSet('currentPage', 5)
            ->assertSet('hasNextPage', false);

        $this->assertCount(5, $component->get('rows'));
    }

    public function test_report_runner_renders_all_three_tabs_and_redacts_passwords(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        // Verify NULL rendering ('—') on pg-multi-plant-contracts (delivery_date/notes contain NULLs)
        Livewire::test(ReportRunnerPage::class, ['viewCode' => 'pg-multi-plant-contracts'])
            ->assertSee('—');

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'federated-3way-executive-report'])
            ->assertSet('activeTab', 'overview')
            ->assertSee('Tổng quan')
            ->assertSee('NORTH')
            ->assertSee('SOUTH');

        // Tab 2: Application Information
        $component
            ->set('activeTab', 'app_info')
            ->assertSee('Báo Cáo Tổng Hợp Điều Hành Doanh Nghiệp 3 Hệ DB')
            ->assertSee('federated-3way-executive-report')
            ->assertSee('1.0.0')
            ->assertSee('federated_join')
            ->assertSee('Báo Cáo Điều Hành & Nhân Sự')
            ->assertSee('pg_north')
            ->assertSee('pg_south')
            ->assertSee('mssql_erp')
            ->assertSee('oracle_hr')
            ->assertSee('ms')
            ->assertSee('30 mins');

        // Tab 3: SQL Query Inspector with password redaction and preserved PostgreSQL dbname
        $component
            ->set('activeTab', 'sql_inspector')
            ->assertSee('Applied Criteria')
            ->assertSee('Individual Source Queries')
            ->assertSee('contracts_north')
            ->assertSee('contracts_south')
            ->assertSee('mfg')
            ->assertSee('hr')
            ->assertSee('DuckDB Federation SQL')
            ->assertSee('WITH all_contracts')
            ->assertSee('dbname=source_db')
            ->assertSee('dbname=app')
            ->assertSee('***')
            ->assertDontSee('etl_password')
            ->assertDontSee('DevPassword!123')
            ->assertDontSee('password=app')
            ->assertDontSee("PASSWORD 'app'");
    }

    public function test_report_runner_exports_valid_xlsx_and_utf8_bom_csv(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->viewerUser);

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'oracle-hr-directory']);

        // 1. Export Excel (.xlsx)
        $component->call('exportExcel');
        $xlsx = $this->extractDownload($component, 'xlsx');
        $this->assertSame("PK\x03\x04", substr($xlsx['content'], 0, 4));

        $xlsxPath = $this->testArtifactsDir.'/report_runner_export.xlsx';
        file_put_contents($xlsxPath, $xlsx['content']);

        /** @var DuckDbSourceAdapter $adapter */
        $adapter = app(DuckDbSourceAdapter::class);
        $rows = $adapter->runQuery("LOAD excel;\nSELECT count(*)::BIGINT AS cnt FROM read_xlsx('{$xlsxPath}');", null, true);
        $this->assertSame(30, (int) ($rows[0]['cnt'] ?? 0));

        // 2. Export CSV (UTF-8 BOM)
        $component->call('exportCsv');
        $csv = $this->extractDownload($component, 'csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv['content']);
        $this->assertStringContainsString('Nguyễn Văn An', $csv['content']);
        $this->assertStringContainsString('Phòng Sản Xuất & May Mặc', $csv['content']);

        $lines = preg_split('/\r\n|\r|\n/', trim(substr($csv['content'], 3)));
        $this->assertCount(31, $lines);
    }

    public function test_sql_export_blocks_unsafe_sql_via_safety_validator(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->adminUser);

        $beforeSnapshots = glob(storage_path('app/snapshots/*.duckdb')) ?: [];

        $unsafeQueries = [
            'DROP TABLE users;',
            'DELETE FROM public.v_epcontract;',
            "SELECT * FROM read_csv('/etc/passwd');",
        ];

        foreach ($unsafeQueries as $unsafeSql) {
            Livewire::test(SqlExportPage::class)
                ->fillForm([
                    'data_source' => 'pg_north',
                    'sql_statement' => $unsafeSql,
                    'export_format' => 'xlsx',
                    'split_file' => false,
                    'chunk_row_threshold' => '100000',
                ])
                ->call('export')
                ->assertHasFormErrors(['sql_statement'])
                ->assertNotified()
                ->assertNoFileDownloaded();
        }

        $afterSnapshots = glob(storage_path('app/snapshots/*.duckdb')) ?: [];
        $this->assertSame($beforeSnapshots, $afterSnapshots);
    }

    public function test_sql_export_streams_valid_xlsx_from_selected_source(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->adminUser);

        $component = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'pg_north',
                'sql_statement' => 'SELECT contract_no, customer_name, total_amount FROM public.v_epcontract ORDER BY contract_no',
                'export_format' => 'xlsx',
                'split_file' => false,
                'chunk_row_threshold' => '100000',
            ])
            ->call('export');

        $xlsx = $this->extractDownload($component, 'xlsx');
        $this->assertSame("PK\x03\x04", substr($xlsx['content'], 0, 4));

        $xlsxPath = $this->testArtifactsDir.'/sql_export_single.xlsx';
        file_put_contents($xlsxPath, $xlsx['content']);

        /** @var DuckDbSourceAdapter $adapter */
        $adapter = app(DuckDbSourceAdapter::class);
        $rows = $adapter->runQuery("LOAD excel;\nSELECT count(*)::BIGINT AS cnt FROM read_xlsx('{$xlsxPath}');", null, true);
        $this->assertSame(25, (int) ($rows[0]['cnt'] ?? 0));
    }

    public function test_sql_export_streams_valid_utf8_bom_csv(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        $component = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'oracle_hr',
                'sql_statement' => 'SELECT EMP_NO, FULL_NAME, BASE_SALARY FROM APP.EMPLOYEES ORDER BY EMP_NO',
                'export_format' => 'csv',
                'split_file' => false,
                'chunk_row_threshold' => '100000',
            ])
            ->call('export');

        $csv = $this->extractDownload($component, 'csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv['content']);
        $this->assertStringContainsString('Nguyễn Văn An', $csv['content']);

        $lines = preg_split('/\r\n|\r|\n/', trim(substr($csv['content'], 3)));
        $this->assertCount(31, $lines);
    }

    public function test_sql_export_streams_chunked_zip_and_cleans_up_temp_snapshot(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->adminUser);

        $beforeSnapshots = glob(storage_path('app/snapshots/*.duckdb')) ?: [];

        $component = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'pg_north',
                'sql_statement' => 'SELECT contract_no, customer_name, status FROM public.v_epcontract ORDER BY contract_no',
                'export_format' => 'xlsx',
                'split_file' => true,
                'chunk_row_threshold' => '10',
            ])
            ->call('export');

        $zipDownload = $this->extractDownload($component, 'zip');
        $this->assertSame("PK\x03\x04", substr($zipDownload['content'], 0, 4));

        $zipPath = $this->testArtifactsDir.'/sql_export_split.zip';
        file_put_contents($zipPath, $zipDownload['content']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(3, $zip->numFiles);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $this->assertIsArray($stat);
            $this->assertStringEndsWith('.xlsx', (string) $stat['name']);
            $this->assertGreaterThan(0, (int) $stat['size']);
        }
        $zip->close();

        // Also verify CSV split ZIP
        $csvZipComponent = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'pg_north',
                'sql_statement' => 'SELECT contract_no, customer_name, status FROM public.v_epcontract ORDER BY contract_no',
                'export_format' => 'csv',
                'split_file' => true,
                'chunk_row_threshold' => '10',
            ])
            ->call('export');

        $csvZipDownload = $this->extractDownload($csvZipComponent, 'zip');
        $csvZipPath = $this->testArtifactsDir.'/sql_export_split_csv.zip';
        file_put_contents($csvZipPath, $csvZipDownload['content']);

        $csvZip = new ZipArchive;
        $this->assertTrue($csvZip->open($csvZipPath) === true);
        $this->assertSame(3, $csvZip->numFiles);
        for ($i = 0; $i < $csvZip->numFiles; $i++) {
            $stat = $csvZip->statIndex($i);
            $this->assertIsArray($stat);
            $this->assertStringEndsWith('.csv', (string) $stat['name']);
            $entryContent = $csvZip->getFromIndex($i);
            $this->assertIsString($entryContent);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $entryContent);
        }
        $csvZip->close();

        $afterSnapshots = glob(storage_path('app/snapshots/*.duckdb')) ?: [];
        $this->assertSame($beforeSnapshots, $afterSnapshots);
    }

    public function test_sql_export_supports_federated_all_sources_query(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->superAdmin);

        $component = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'federated',
                'sql_statement' => "SELECT 'N' AS branch, count(*)::BIGINT AS cnt FROM pg_north.public.v_epcontract UNION ALL SELECT 'S', count(*)::BIGINT FROM pg_south.public.v_epcontract",
                'export_format' => 'csv',
                'split_file' => false,
                'chunk_row_threshold' => '100000',
            ])
            ->call('export');

        $csv = $this->extractDownload($component, 'csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv['content']);
        $this->assertStringContainsString('N,25', $csv['content']);
        $this->assertStringContainsString('S,20', $csv['content']);
    }

    public function test_sql_safety_validator_and_sql_export_block_quote_desync_and_duckdb_system_functions(): void
    {
        $this->seedSqliteOnly();

        /** @var SqlSafetyValidator $validator */
        $validator = app(SqlSafetyValidator::class);

        $maliciousPayloads = [
            "SELECT 1 AS \"x'\"); COPY (SELECT 1) TO '/tmp/pwn_dq.csv'; SELECT (1 -- '",
            "SELECT 'x\\'); COPY (SELECT 1) TO '/tmp/pwn_bs.csv'; SELECT (1 -- '",
            'SELECT database_name, path FROM duckdb_databases()',
            'SELECT * FROM duckdb_settings()',
            "SELECT * FROM sniff_csv('/etc/passwd')",
            "SELECT getenv('PATH') AS env_path",
            "SELECT \"getenv\"('PATH') AS quoted_env",
            "SELECT * FROM postgres_query('pg_north', 'SELECT 1')",
            "SELECT * FROM csv_scan('/etc/passwd')",
            "SELECT current_setting('TimeZone')",
            'SELECT * FROM pragma_database_list()',
            "SELECT \$\$a'\$\$; COPY (SELECT 1) TO '/tmp/pwn_dollar.csv'; SELECT (1 -- '",
        ];

        $this->actingAs($this->adminUser);

        foreach ($maliciousPayloads as $payload) {
            $threw = false;
            try {
                $validator->validate($payload);
            } catch (SqlSafetyException) {
                $threw = true;
            }
            $this->assertTrue($threw, "SqlSafetyValidator failed to reject: {$payload}");

            Livewire::test(SqlExportPage::class)
                ->fillForm([
                    'data_source' => 'pg_north',
                    'sql_statement' => $payload,
                    'export_format' => 'csv',
                    'split_file' => false,
                    'chunk_row_threshold' => '100000',
                ])
                ->call('export')
                ->assertHasFormErrors(['sql_statement'])
                ->assertNoFileDownloaded();
        }
    }

    public function test_sql_export_executes_cte_with_trailing_semicolon_and_comment(): void
    {
        $this->seedSqliteOnly();

        $this->actingAs($this->adminUser);

        $component = Livewire::test(SqlExportPage::class)
            ->fillForm([
                'data_source' => 'pg_north',
                'sql_statement' => 'WITH cte AS (SELECT contract_no FROM public.v_epcontract ORDER BY contract_no LIMIT 5) SELECT * FROM cte; -- trailing comment',
                'export_format' => 'csv',
                'split_file' => false,
                'chunk_row_threshold' => '100000',
            ])
            ->call('export');

        $csv = $this->extractDownload($component, 'csv');
        $lines = preg_split('/\r\n|\r|\n/', trim(substr($csv['content'], 3)));
        $this->assertCount(6, $lines);

        // Also verify federated_join CustomView with trailing semicolon + comment in compileExecutionPlan()
        $fedView = CustomView::create([
            'view_code' => 'test-fed-trailing-semicolon',
            'view_name' => 'Test Federated Trailing Semicolon',
            'menu_group_code' => 'logistics',
            'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
            'allowed_sources' => ['pg_north'],
            'default_sources' => ['pg_north'],
            'source_queries' => [
                [
                    'alias' => 'c',
                    'source_code' => 'pg_north',
                    'sql_text' => 'SELECT contract_no FROM public.v_epcontract LIMIT 3; -- source comment',
                ],
            ],
            'sql_text' => 'SELECT contract_no FROM c ORDER BY contract_no; -- final comment',
            'version' => '1.0.0',
            'is_active' => true,
        ]);

        /** @var DuckDbReportEngine $engine */
        $engine = app(DuckDbReportEngine::class);
        $snapId = $engine->executeAndCreateSnapshot($fedView);
        $page = $engine->getPage($snapId, 1, 10);
        $this->assertSame(3, $page['totalRows']);
        app(SnapshotManager::class)->deleteSnapshot($snapId);
    }

    public function test_redact_secrets_preserves_pg_dbname_and_masks_special_passwords(): void
    {
        /** @var DuckDbSourceAdapter $adapter */
        $adapter = app(DuckDbSourceAdapter::class);

        $script = implode("\n", [
            "ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg_north (TYPE postgres, READ_ONLY);",
            "ATTACH 'host=127.0.0.1 port=5432 user=etl password=Dev;Pass!123 dbname=source_db' AS pg_semi (TYPE postgres, READ_ONLY);",
            "ATTACH 'host=127.0.0.1 port=5432 user=etl password=my secret ''pass'' dbname=source_db' AS pg_space (TYPE postgres, READ_ONLY);",
            "ATTACH 'Server=127.0.0.1,1433;Database=erp;User Id=sa;Password=foo;bar''baz qux;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);",
            "CREATE SECRET IF NOT EXISTS oracle_hr (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'pa''ss word');",
        ]);

        $redacted = $adapter->redactSecrets($script);

        $this->assertStringContainsString("ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=*** dbname=source_db' AS pg_north", $redacted);
        $this->assertStringContainsString("ATTACH 'host=127.0.0.1 port=5432 user=etl password=*** dbname=source_db' AS pg_semi", $redacted);
        $this->assertStringContainsString("ATTACH 'host=127.0.0.1 port=5432 user=etl password=*** dbname=source_db' AS pg_space", $redacted);
        $this->assertStringContainsString("ATTACH 'Server=127.0.0.1,1433;Database=erp;User Id=sa;Password=***;TrustServerCertificate=yes' AS mssql_erp", $redacted);
        $this->assertStringContainsString("PASSWORD '***'", $redacted);

        $this->assertStringNotContainsString('etl_password', $redacted);
        $this->assertStringNotContainsString('Dev;Pass!123', $redacted);
        $this->assertStringNotContainsString('Pass!123', $redacted);
        $this->assertStringNotContainsString('secret', $redacted);
        $this->assertStringNotContainsString('foo;bar', $redacted);
        $this->assertStringNotContainsString('baz qux', $redacted);
        $this->assertStringNotContainsString("pa''ss word", $redacted);
    }

    public function test_report_runner_snapshot_health_reports_genuine_utc_and_30min_ttl(): void
    {
        $this->seedSqliteOnly();
        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'pg-multi-plant-contracts']);

        /** @var ReportRunnerPage $page */
        $page = $component->instance();
        $health = $page->getSnapshotHealth();

        $this->assertSame(30, $health['ttl_minutes']);
        $this->assertGreaterThanOrEqual(1750, $health['remaining_seconds']);
        $this->assertLessThanOrEqual(1800, $health['remaining_seconds']);
        $this->assertStringEndsWith(' UTC', $health['created_at']);

        $parsedCreated = Carbon::createFromFormat('Y-m-d H:i:s', str_replace(' UTC', '', $health['created_at']), 'UTC');
        $this->assertNotFalse($parsedCreated);
        $this->assertLessThanOrEqual(60, abs(now('UTC')->timestamp - $parsedCreated->timestamp));
    }

    public function test_report_runner_enforces_role_source_permissions_locks_pagination_and_resets_snapshot_on_error(): void
    {
        $this->seedSqliteOnly();

        // Restrict report_viewer RolePermission to pg_north only
        RolePermission::where('role_code', 'report_viewer')->update([
            'allowed_sources' => ['pg_north'],
        ]);

        CustomView::create([
            'view_code' => 'open-sources-viewer-report',
            'view_name' => 'Báo Cáo Nguồn Mở Theo Vai Trò',
            'menu_group_code' => 'logistics',
            'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
            'allowed_sources' => [],
            'default_sources' => [],
            'sql_text' => 'SELECT contract_no, customer_name, plant, total_amount FROM public.v_epcontract WHERE (@MinAmount IS NULL OR total_amount >= @MinAmount) ORDER BY contract_no',
            'filters' => [
                [
                    'name' => 'MinAmount',
                    'label' => 'Giá trị tối thiểu',
                    'type' => 'number',
                ],
            ],
            'version' => '1.0.0',
            'is_active' => true,
        ]);

        RolePermission::where('role_code', 'report_viewer')->update(['allowed_views' => ['open-sources-viewer-report']]);
        $this->actingAs($this->viewerUser);

        $component = Livewire::test(ReportRunnerPage::class, ['viewCode' => 'open-sources-viewer-report'])
            ->assertSet('selectedSources', ['pg_north'])
            ->assertSet('totalRows', 25);

        /** @var ReportRunnerPage $page */
        $page = $component->instance();
        $this->assertSame(['pg_north'], $page->getAllowedSourcesList()->pluck('code')->all());
        $this->assertSame(['pg_north'], $page->getConnectedDataSources()->pluck('code')->all());

        // Attempt to select disallowed pg_south even when CustomView.allowed_sources is empty
        $component->set('selectedSources', ['pg_south']);
        $this->assertSame([], $component->instance()->getConnectedDataSources()->pluck('code')->all());

        $component
            ->call('runReport')
            ->assertSet('snapshotId', null)
            ->assertSet('columns', [])
            ->assertSet('snapshotCreatedAt', null)
            ->assertSet('totalRows', 0);

        $this->assertNotNull($component->get('executionError'));
        $this->assertNotContains('pg_south', $component->instance()->getConnectedDataSources()->pluck('code')->all());

        // Mixed valid + disallowed source never leaks disallowed source on Tab 2
        $component
            ->set('selectedSources', ['pg_north', 'pg_south', 'oracle_hr'])
            ->call('runReport')
            ->assertSet('totalRows', 25);
        $this->assertSame(['pg_north'], $component->instance()->getConnectedDataSources()->pluck('code')->all());

        // Nested arrays in selectedSources / filterValues are sanitized safely
        $component
            ->set('selectedSources', [['nested'], 'pg_north'])
            ->set('filterValues', ['MinAmount' => ['nested' => 'evil']])
            ->call('runReport')
            ->assertSet('selectedSources', ['pg_north'])
            ->assertSet('filterValues.MinAmount', null)
            ->assertSet('totalRows', 25);

        // Validation failure on runReport() clears snapshotId, columns, and snapshotCreatedAt
        $this->assertNotNull($component->get('snapshotId'));
        $component
            ->set('filterValues.MinAmount', 'not-a-number')
            ->call('runReport')
            ->assertSet('snapshotId', null)
            ->assertSet('columns', [])
            ->assertSet('snapshotCreatedAt', null)
            ->assertSet('rows', []);

        // Restore valid snapshot, delete snapshot file, and verify exportExcel clears snapshotId
        $component
            ->set('filterValues.MinAmount', null)
            ->call('runReport');
        $snapId = (string) $component->get('snapshotId');
        $this->assertNotSame('', $snapId);
        app(SnapshotManager::class)->deleteSnapshot($snapId);

        $component
            ->call('exportExcel')
            ->assertSet('snapshotId', null)
            ->assertNoFileDownloaded();

        // Verify #[Locked] on $perPage and $currentPage rejects tampering
        $lockedPerPage = false;
        try {
            $component->set('perPage', 0);
        } catch (CannotUpdateLockedPropertyException) {
            $lockedPerPage = true;
        }
        $this->assertTrue($lockedPerPage, 'ReportRunnerPage::$perPage must be #[Locked].');

        $lockedCurrentPage = false;
        try {
            $component->set('currentPage', 99);
        } catch (CannotUpdateLockedPropertyException) {
            $lockedCurrentPage = true;
        }
        $this->assertTrue($lockedCurrentPage, 'ReportRunnerPage::$currentPage must be #[Locked].');
    }
}
