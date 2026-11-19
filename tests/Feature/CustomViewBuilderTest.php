<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomViewResource;
use App\Filament\Resources\CustomViewResource\Pages\CreateCustomView;
use App\Filament\Resources\CustomViewResource\Pages\EditCustomView;
use App\Filament\Resources\CustomViewResource\Pages\ListCustomViews;
use App\Models\CustomView;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use Database\Seeders\SmartReportSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomViewBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Prevent re-running heavy external Docker DB provisioning on each test

        $this->seed(SmartReportSeeder::class);

        // 2. Setup authenticated users
        $this->adminUser = User::where('email', 'admin@smartreport.test')->firstOrFail();
        $this->viewerUser = User::factory()->create([
            'email' => 'viewer@smartreport.test',
            'roles' => ['report_viewer'],
        ]);

        // 3. Set Filament panel context
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        // Clean up temporary DuckDB snapshot files created during preview queries
        $snapshots = glob(storage_path('app/snapshots/*.duckdb'));
        if (is_array($snapshots)) {
            foreach ($snapshots as $snapshot) {
                @unlink($snapshot);
            }
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // 1. Authorization & Access Control
    // -------------------------------------------------------------------------

    /**
     * Requirement: List, Create, Edit pages render HTTP 200 for super_admin.
     */
    public function test_super_admin_can_view_custom_view_resource_pages(): void
    {
        $this->actingAs($this->adminUser);

        $this->assertTrue(CustomViewResource::canAccess());

        // HTTP GET routes
        $this->get('/admin/custom-views')->assertSuccessful();
        $this->get('/admin/custom-views/create')->assertSuccessful();
        $this->get('/admin/custom-views/pg-multi-plant-contracts/edit')->assertSuccessful();

        // Livewire component mounts
        Livewire::test(ListCustomViews::class)->assertSuccessful();
        Livewire::test(CreateCustomView::class)->assertSuccessful();
        Livewire::test(EditCustomView::class, ['record' => 'pg-multi-plant-contracts'])->assertSuccessful();
    }

    /**
     * Requirement: User with role report_viewer gets HTTP 403 Forbidden on List, Create, Edit routes.
     */
    public function test_non_super_admin_cannot_access_custom_view_resource(): void
    {
        $this->actingAs($this->viewerUser);

        $this->assertFalse(CustomViewResource::canAccess());

        // HTTP GET routes must return 403 Forbidden
        $this->get('/admin/custom-views')->assertForbidden();
        $this->get('/admin/custom-views/create')->assertForbidden();
        $this->get('/admin/custom-views/pg-multi-plant-contracts/edit')->assertForbidden();

        // Direct Livewire component mount must be forbidden
        Livewire::test(ListCustomViews::class)->assertForbidden();
        Livewire::test(CreateCustomView::class)->assertForbidden();
        Livewire::test(EditCustomView::class, ['record' => 'pg-multi-plant-contracts'])->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 2. Resource Listing & CRUD Operations
    // -------------------------------------------------------------------------

    /**
     * Requirement: CustomViewResource lists all seeded custom views in table.
     */
    public function test_custom_view_resource_lists_seeded_views(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(ListCustomViews::class)
            ->assertCanSeeTableRecords(CustomView::all())
            ->assertCountTableRecords(5);
    }

    /**
     * Requirement: Verify creating CustomView with execution_mode = 'single_or_union',
     * allowed_sources, default_sources, sql_text, and parameters, saved properly in SQLite database.
     */
    public function test_custom_view_can_be_created_with_single_or_union_mode(): void
    {
        $this->actingAs($this->adminUser);

        $payload = [
            'view_code' => 'new-single-union-contracts',
            'view_name' => 'Báo Cáo Hợp Đồng Chi Nhánh Bắc & Nam',
            'menu_group_code' => 'logistics',
            'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
            'allowed_sources' => ['pg_north', 'pg_south'],
            'default_sources' => ['pg_north'],
            'sql_text' => 'SELECT c.contract_no, c.customer_name, c.total_amount FROM public.v_epcontract c WHERE (@Status IS NULL OR c.status = @Status)',
            'filters' => [
                [
                    'name' => 'Status',
                    'label' => 'Trạng thái',
                    'type' => 'select',
                    'default_value' => 'COMPLETED',
                    'options' => [
                        'COMPLETED' => 'Hoàn tất',
                        'PENDING' => 'Chờ duyệt',
                    ],
                    'is_required' => false,
                ],
            ],
            'roles' => ['super_admin', 'report_viewer'],
            'description' => 'Báo cáo tra cứu hợp đồng đơn nguồn hoặc gộp đa chi nhánh.',
            'version' => '1.0.0',
            'is_active' => true,
        ];

        Livewire::test(CreateCustomView::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('custom_views', [
            'view_code' => 'new-single-union-contracts',
            'view_name' => 'Báo Cáo Hợp Đồng Chi Nhánh Bắc & Nam',
            'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
            'menu_group_code' => 'logistics',
            'is_active' => true,
        ]);

        $created = CustomView::where('view_code', 'new-single-union-contracts')->firstOrFail();
        $this->assertSame(['pg_north', 'pg_south'], $created->allowed_sources);
        $this->assertSame(['pg_north'], $created->default_sources);
        $this->assertCount(1, $created->filters);
        $this->assertSame('Status', $created->filters[0]['name']);
        $this->assertNull($created->source_queries);
    }

    /**
     * Requirement: Verify creating CustomView with execution_mode = 'federated_join',
     * source_queries repeater, and federated join SQL.
     */
    public function test_custom_view_can_be_created_with_federated_join_mode(): void
    {
        $this->actingAs($this->adminUser);

        $payload = [
            'view_code' => 'new-cross-engine-productivity',
            'view_name' => 'Báo Cáo Năng Suất Nhân Lực & Sản Lượng (Oracle + MSSQL)',
            'menu_group_code' => 'manufacturing',
            'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
            'source_queries' => [
                [
                    'alias' => 'emp',
                    'source_code' => 'oracle_hr',
                    'sql_text' => 'SELECT EMP_NO, FULL_NAME, PLANT, BASE_SALARY FROM APP.EMPLOYEES WHERE (@Plant IS NULL OR PLANT = @Plant)',
                ],
                [
                    'alias' => 'orders',
                    'source_code' => 'mssql_erp',
                    'sql_text' => 'SELECT emp_no, order_no, output_qty, defect_qty FROM dbo.production_orders',
                ],
            ],
            'sql_text' => 'SELECT e.FULL_NAME, e.PLANT, sum(o.output_qty) AS total_output, sum(o.defect_qty) AS total_defect FROM emp e INNER JOIN orders o ON e.EMP_NO = o.emp_no GROUP BY e.FULL_NAME, e.PLANT ORDER BY total_output DESC',
            'filters' => [
                [
                    'name' => 'Plant',
                    'label' => 'Nhà máy',
                    'type' => 'select',
                    'default_value' => 'NORTH',
                    'is_required' => false,
                ],
            ],
            'roles' => ['super_admin'],
            'description' => 'Báo cáo liên hợp Cross-Engine giữa Oracle HR và SQL Server ERP.',
            'version' => '1.0.0',
            'is_active' => true,
        ];

        Livewire::test(CreateCustomView::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('custom_views', [
            'view_code' => 'new-cross-engine-productivity',
            'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
            'menu_group_code' => 'manufacturing',
        ]);

        $created = CustomView::where('view_code', 'new-cross-engine-productivity')->firstOrFail();
        $this->assertIsArray($created->source_queries);
        $this->assertCount(2, $created->source_queries);
        $this->assertSame('emp', $created->source_queries[0]['alias']);
        $this->assertSame('oracle_hr', $created->source_queries[0]['source_code']);
        $this->assertSame('orders', $created->source_queries[1]['alias']);
        $this->assertSame('mssql_erp', $created->source_queries[1]['source_code']);
    }

    /**
     * Requirement: Edit page updates custom view fields successfully.
     */
    public function test_custom_view_can_be_updated_in_edit_page(): void
    {
        $this->actingAs($this->adminUser);

        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();

        Livewire::test(EditCustomView::class, ['record' => 'pg-multi-plant-contracts'])
            ->fillForm([
                'view_name' => 'Tên Báo Cáo Hợp Đồng Đã Cập Nhật',
                'description' => 'Mô tả mới được cập nhật qua Filament Edit Page.',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $view->refresh();
        $this->assertSame('Tên Báo Cáo Hợp Đồng Đã Cập Nhật', $view->view_name);
        $this->assertSame('Mô tả mới được cập nhật qua Filament Edit Page.', $view->description);
        $this->assertFalse($view->is_active);
    }

    // -------------------------------------------------------------------------
    // 3. Validation Rules
    // -------------------------------------------------------------------------

    /**
     * Requirement: Unique view_code, required view_name, required execution_mode.
     */
    public function test_custom_view_validation_rules(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Missing required fields
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => '',
                'view_name' => '',
                'execution_mode' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'view_code' => 'required',
                'view_name' => 'required',
                'execution_mode' => 'required',
            ]);

        // 2. Duplicate view_code (unique validation rule)
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'pg-multi-plant-contracts', // Already exists in seeded data
                'view_name' => 'Trùng Mã Báo Cáo',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'sql_text' => 'SELECT 1',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'view_code' => 'unique',
            ]);

        // 3. Disallowed execution_mode value
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'invalid-mode-view',
                'view_name' => 'Invalid Execution Mode',
                'execution_mode' => 'unsupported_mode',
                'sql_text' => 'SELECT 1',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'execution_mode',
            ]);
    }

    // -------------------------------------------------------------------------
    // 4. Auto-Detect @Parameters Action
    // -------------------------------------------------------------------------

    /**
     * Requirement: Verify invoking the auto-detect action on SQL e.g.
     * SELECT * FROM orders WHERE plant = @Plant AND order_date >= @StartDate
     * populates the filters repeater with detected @Plant and @StartDate.
     */
    public function test_auto_detect_parameters_action_extracts_params_from_sql(): void
    {
        $this->actingAs($this->adminUser);

        $sql = 'SELECT * FROM orders WHERE plant = @Plant AND order_date >= @StartDate AND total_amount > @MinAmount';

        $test = Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'detect-param-test-view',
                'view_name' => 'Báo Cáo Tự Nhận Diện Tham Số',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'sql_text' => $sql,
            ])
            ->callAction('auto_detect_parameters')
            ->assertHasNoActionErrors()
            ->assertNotified();

        // Verify filters repeater state in form
        $filters = $test->get('data.filters');
        $this->assertIsArray($filters);
        $this->assertCount(3, $filters);

        $paramNames = array_column($filters, 'name');
        $this->assertContains('Plant', $paramNames);
        $this->assertContains('StartDate', $paramNames);
        $this->assertContains('MinAmount', $paramNames);

        // Verify inferred types from SqlParameterExtractor
        foreach ($filters as $filter) {
            if ($filter['name'] === 'Plant') {
                $this->assertSame('select', $filter['type']);
                $this->assertNotEmpty($filter['options'] ?? null);
            } elseif ($filter['name'] === 'StartDate') {
                $this->assertSame('date', $filter['type']);
            } elseif ($filter['name'] === 'MinAmount') {
                $this->assertSame('number', $filter['type']);
            }
        }
    }

    /**
     * Requirement: Auto-detect action preserves existing parameter settings and works on Edit page.
     */
    public function test_auto_detect_parameters_on_edit_page_preserves_customizations(): void
    {
        $this->actingAs($this->adminUser);

        $test = Livewire::test(EditCustomView::class, ['record' => 'pg-multi-plant-contracts'])
            ->fillForm([
                'sql_text' => 'SELECT * FROM public.v_epcontract WHERE status = @Status AND sign_date >= @StartDate AND delivery_date <= @DeliveryDate',
            ])
            ->callAction('auto_detect_parameters')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $filters = $test->get('data.filters');
        $this->assertIsArray($filters);
        $this->assertCount(3, $filters);

        $paramNames = array_column($filters, 'name');
        $this->assertContains('Status', $paramNames);
        $this->assertContains('StartDate', $paramNames);
        $this->assertContains('DeliveryDate', $paramNames);
    }

    /**
     * Requirement: Auto-detect in federated_join mode aggregates params from source queries and final sql.
     */
    public function test_auto_detect_parameters_in_federated_join_mode(): void
    {
        $this->actingAs($this->adminUser);

        $test = Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'fed-detect-test',
                'view_name' => 'Federated Detect Test',
                'execution_mode' => CustomView::MODE_FEDERATED_JOIN,
                'source_queries' => [
                    [
                        'alias' => 'emp',
                        'source_code' => 'oracle_hr',
                        'sql_text' => 'SELECT * FROM APP.EMPLOYEES WHERE DEPT_CODE = @DeptCode',
                    ],
                    [
                        'alias' => 'orders',
                        'source_code' => 'mssql_erp',
                        'sql_text' => 'SELECT * FROM dbo.production_orders WHERE shift_date >= @ShiftDate',
                    ],
                ],
                'sql_text' => 'SELECT e.EMP_NO, o.order_no FROM emp e JOIN orders o ON e.EMP_NO = o.emp_no WHERE o.output_qty >= @MinQty',
            ])
            ->callAction('auto_detect_parameters')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $filters = $test->get('data.filters');
        $this->assertIsArray($filters);
        $this->assertCount(3, $filters);

        $paramNames = array_column($filters, 'name');
        $this->assertContains('DeptCode', $paramNames);
        $this->assertContains('ShiftDate', $paramNames);
        $this->assertContains('MinQty', $paramNames);
    }

    // -------------------------------------------------------------------------
    // 5. Test SQL (10-Row Preview) Action
    // -------------------------------------------------------------------------

    /**
     * Requirement: Verify running preview on a valid query (against seeded pg_north contracts)
     * returns status success, <=10 rows, columns list, and measured execution time.
     */
    public function test_preview_sql_action_returns_ten_rows_and_metadata(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Preview on EditCustomView for seeded view 'pg-multi-plant-contracts'
        Livewire::test(EditCustomView::class, ['record' => 'pg-multi-plant-contracts'])
            ->callAction('preview_sql')
            ->assertHasNoActionErrors()
            ->assertNotified();

        // 2. Preview on CreateCustomView with live query against pg_north
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'preview-create-view',
                'view_name' => 'Chạy Thử Create View',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north'],
                'default_sources' => ['pg_north'],
                'sql_text' => 'SELECT contract_no, customer_name, plant, total_amount, status FROM public.v_epcontract LIMIT 15',
            ])
            ->callAction('preview_sql')
            ->assertHasNoActionErrors()
            ->assertNotified();

        // 3. Verify underlying DuckDb engine preview contract directly
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $engine = app(DuckDbReportEngine::class);
        $previewResult = $engine->preview($view, ['pg_north'], [], 10);

        $this->assertIsArray($previewResult['rows']);
        $this->assertLessThanOrEqual(10, count($previewResult['rows']));
        $this->assertSame(10, count($previewResult['rows']), 'Must return exactly 10 rows when total > 10.');
        $this->assertGreaterThan(0, $previewResult['totalRows']);
        $this->assertContains('contract_no', $previewResult['columns']);
        $this->assertContains('customer_name', $previewResult['columns']);
        $this->assertGreaterThan(0.0, $previewResult['latency_ms']);
    }

    /**
     * Requirement: Verify running preview with forbidden SQL (e.g. DROP TABLE, DELETE)
     * or invalid syntax is blocked by safety validator and reports error gracefully without crashing.
     */
    public function test_preview_sql_action_handles_invalid_or_dangerous_sql(): void
    {
        $this->actingAs($this->adminUser);

        // Case A: Forbidden DDL (DROP TABLE)
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'dangerous-drop-view',
                'view_name' => 'Dangerous Drop View',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north'],
                'default_sources' => ['pg_north'],
                'sql_text' => 'DROP TABLE public.v_epcontract;',
            ])
            ->callAction('preview_sql')
            ->assertNotified();

        // Case B: Forbidden DML (DELETE FROM)
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'dangerous-delete-view',
                'view_name' => 'Dangerous Delete View',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north'],
                'default_sources' => ['pg_north'],
                'sql_text' => 'DELETE FROM public.v_epcontract WHERE 1=1;',
            ])
            ->callAction('preview_sql')
            ->assertNotified();

        // Case C: Forbidden DuckDB file inspection function (read_csv)
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'dangerous-readcsv-view',
                'view_name' => 'Dangerous read_csv View',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north'],
                'default_sources' => ['pg_north'],
                'sql_text' => "SELECT * FROM read_csv('/etc/passwd');",
            ])
            ->callAction('preview_sql')
            ->assertNotified();

        // Case D: Invalid SQL syntax (nonexistent table or malformed SQL)
        Livewire::test(CreateCustomView::class)
            ->fillForm([
                'view_code' => 'invalid-syntax-view',
                'view_name' => 'Invalid Syntax View',
                'execution_mode' => CustomView::MODE_SINGLE_OR_UNION,
                'allowed_sources' => ['pg_north'],
                'default_sources' => ['pg_north'],
                'sql_text' => 'SELECT * FROM nonexistent_table_xyz_12345;',
            ])
            ->callAction('preview_sql')
            ->assertNotified();
    }
}
