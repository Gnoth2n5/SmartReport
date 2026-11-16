<?php

namespace Tests\Feature;

use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Cluster1EnvironmentAndSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test smartreport configuration file and duckdb binary.
     */
    public function test_smartreport_config_and_duckdb_binary_are_valid(): void
    {
        $config = config('smartreport');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('allowed_drivers', $config);
        $this->assertArrayHasKey('duckdb_binary', $config);
        $this->assertArrayHasKey('snapshot_dir', $config);
        $this->assertArrayHasKey('snapshot_ttl_minutes', $config);
        $this->assertArrayHasKey('page_size', $config);

        $allowedDrivers = $config['allowed_drivers'];
        $this->assertEquals(['sqlsrv' => 'SQL Server', 'pgsql' => 'PostgreSQL', 'oracle' => 'Oracle'], $allowedDrivers);
        $this->assertSame(10, $config['page_size']);
        $this->assertSame(30, $config['snapshot_ttl_minutes']);

        // Verify duckdb binary works and responds
        $duckdbBin = $config['duckdb_binary'];
        $process = new Process([$duckdbBin, '-batch', '-light-mode', '-c', 'SELECT version();']);
        $process->run();

        $this->assertTrue($process->isSuccessful(), "DuckDB binary at {$duckdbBin} failed to execute.");
        $this->assertStringContainsString('v2.0.0', $process->getOutput());
    }

    /**
     * Test SQLite schema, models, encrypted passwords and json casts.
     */
    public function test_sqlite_models_and_attributes(): void
    {
        $this->seed();

        // 1. Data Sources
        $this->assertGreaterThanOrEqual(4, DataSource::count());
        $pgNorth = DataSource::where('code', 'pg_north')->firstOrFail();

        $this->assertSame(DataSource::DRIVER_PGSQL, $pgNorth->driver);
        $this->assertSame(5432, $pgNorth->port);
        $this->assertSame('etl_password', $pgNorth->password); // Decrypted via cast
        $this->assertIsArray($pgNorth->options);
        $this->assertTrue($pgNorth->is_read_only);
        $this->assertTrue($pgNorth->is_active);

        // Verify that raw password stored in DB is actually encrypted
        $rawPassword = DB::table('data_sources')->where('code', 'pg_north')->value('password');
        $this->assertNotEquals('etl_password', $rawPassword);
        $this->assertStringStartsWith('eyJ', $rawPassword); // Laravel encrypted payload (base64 JSON)

        // 2. Menu Groups
        $this->assertGreaterThanOrEqual(3, MenuGroup::count());
        $mfgGroup = MenuGroup::where('group_code', 'manufacturing')->firstOrFail();
        $this->assertIsArray($mfgGroup->roles);
        $this->assertContains('super_admin', $mfgGroup->roles);
        $this->assertGreaterThan(0, $mfgGroup->customViews()->count());

        // 3. Role Permissions
        $this->assertGreaterThanOrEqual(2, RolePermission::count());
        $superAdmin = RolePermission::where('role_code', 'super_admin')->firstOrFail();
        $this->assertTrue($superAdmin->is_system);
        $this->assertTrue($superAdmin->allowsView('any-view'));
        $this->assertTrue($superAdmin->allowsGroup('any-group'));
        $this->assertTrue($superAdmin->allowsSource('any-source'));

        $viewer = RolePermission::where('role_code', 'report_viewer')->firstOrFail();
        $this->assertFalse($viewer->is_system);
        $this->assertTrue($viewer->allowsView('pg-multi-plant-contracts'));
        $this->assertFalse($viewer->allowsView('join-oracle-mssql-productivity'));
        $this->assertTrue($viewer->allowsGroup('logistics'));
        $this->assertFalse($viewer->allowsGroup('executive'));

        // 4. Custom Views
        $this->assertGreaterThanOrEqual(5, CustomView::count());
        $pgView = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $this->assertTrue($pgView->isSingleOrUnion());
        $this->assertFalse($pgView->isFederatedJoin());
        $this->assertIsArray($pgView->allowed_sources);
        $this->assertContains('pg_north', $pgView->allowed_sources);
        $this->assertContains('pg_south', $pgView->allowed_sources);
        $this->assertIsArray($pgView->filters);
        $this->assertNotNull($pgView->menuGroup);

        $federatedView = CustomView::where('view_code', 'join-oracle-mssql-productivity')->firstOrFail();
        $this->assertTrue($federatedView->isFederatedJoin());
        $this->assertFalse($federatedView->isSingleOrUnion());
        $this->assertIsArray($federatedView->source_queries);
        $this->assertCount(2, $federatedView->source_queries);

        $threeWayView = CustomView::where('view_code', 'federated-3way-executive-report')->firstOrFail();
        $this->assertTrue($threeWayView->isFederatedJoin());
        $this->assertCount(4, $threeWayView->source_queries);

        // 5. User Roles
        $adminUser = User::where('email', 'admin@smartreport.test')->firstOrFail();
        $this->assertIsArray($adminUser->roles);
        $this->assertTrue($adminUser->hasRole('super_admin'));
        $this->assertTrue($adminUser->hasAnyRole(['report_viewer', 'super_admin']));
        $this->assertTrue($adminUser->isSuperAdmin());
    }

    /**
     * Test external databases have been provisioned with genuine realistic data.
     */
    public function test_external_databases_contain_genuine_test_data(): void
    {
        $duckdbBin = config('smartreport.duckdb_binary', 'duckdb');

        $sql = <<<'SQL'
LOAD postgres;
LOAD mssql;
LOAD oracle_scanner;

ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg_north (TYPE postgres, READ_ONLY);
ATTACH 'host=127.0.0.1 port=5433 user=app password=app dbname=app' AS pg_south (TYPE postgres, READ_ONLY);
ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);
CREATE SECRET IF NOT EXISTS __sec_oracle_hr_test (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'app');
ATTACH '__sec_oracle_hr_test' AS oracle_hr (TYPE oracle_scanner);

SELECT 'pg_north_count' AS metric, count(*)::BIGINT AS val FROM pg_north.public.v_epcontract
UNION ALL
SELECT 'pg_south_count', count(*)::BIGINT FROM pg_south.public.v_epcontract
UNION ALL
SELECT 'mssql_orders_count', count(*)::BIGINT FROM mssql_erp.dbo.production_orders
UNION ALL
SELECT 'oracle_emp_count', count(*)::BIGINT FROM oracle_hr.APP.EMPLOYEES
UNION ALL
SELECT 'pg_north_null_delivery', count(*)::BIGINT FROM pg_north.public.v_epcontract WHERE delivery_date IS NULL
UNION ALL
SELECT 'mssql_null_defect_reason', count(*)::BIGINT FROM mssql_erp.dbo.production_orders WHERE defect_reason IS NULL
UNION ALL
SELECT 'oracle_null_notes', count(*)::BIGINT FROM oracle_hr.APP.EMPLOYEES WHERE NOTES IS NULL;
SQL;

        $process = new Process([$duckdbBin, '-batch', '-light-mode', '-json']);
        $process->setInput($sql);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'DuckDB query against 4 external DBs failed: '.$process->getErrorOutput());

        $output = trim($process->getOutput());
        preg_match_all('/\[.*?\]/s', $output, $matches);
        $data = json_decode(end($matches[0]), true);
        $this->assertIsArray($data, 'DuckDB JSON output must be a valid array.');

        $metrics = [];
        foreach ($data as $row) {
            $metrics[$row['metric']] = (int) $row['val'];
        }

        // 1. Check exact row counts
        $this->assertSame(25, $metrics['pg_north_count'], 'PostgreSQL North must contain 25 contracts.');
        $this->assertSame(20, $metrics['pg_south_count'], 'PostgreSQL South must contain 20 contracts.');
        $this->assertSame(35, $metrics['mssql_orders_count'], 'SQL Server must contain 35 production orders.');
        $this->assertSame(30, $metrics['oracle_emp_count'], 'Oracle HR must contain 30 employees.');

        // 2. Check NULL values are present
        $this->assertGreaterThan(0, $metrics['pg_north_null_delivery'], 'PostgreSQL North must have contracts with NULL delivery_date.');
        $this->assertGreaterThan(0, $metrics['mssql_null_defect_reason'], 'SQL Server must have orders with NULL defect_reason.');
        $this->assertGreaterThan(0, $metrics['oracle_null_notes'], 'Oracle must have employees with NULL notes.');

        // 3. Check Vietnamese Unicode sample queries
        $vnSql = <<<'SQL'
LOAD postgres;
LOAD mssql;
LOAD oracle_scanner;

ATTACH 'host=127.0.0.1 port=5432 user=etl_user password=etl_password dbname=source_db' AS pg_north (TYPE postgres, READ_ONLY);
ATTACH 'Server=127.0.0.1,1433;Database=erp_production;User Id=sa;Password=DevPassword!123;TrustServerCertificate=yes' AS mssql_erp (TYPE mssql);
CREATE SECRET IF NOT EXISTS __sec_oracle_hr_test (TYPE oracle, DSN '127.0.0.1:1521/FREEPDB1', USER 'app', PASSWORD 'app');
ATTACH '__sec_oracle_hr_test' AS oracle_hr (TYPE oracle_scanner);

SELECT 'PG_VN' as tag, customer_name as val FROM pg_north.public.v_epcontract WHERE contract_no = 'HD-N-001'
UNION ALL
SELECT 'MSSQL_VN', defect_reason FROM mssql_erp.dbo.production_orders WHERE order_no = 'PO-2024-001'
UNION ALL
SELECT 'ORACLE_VN', FULL_NAME FROM oracle_hr.APP.EMPLOYEES WHERE EMP_NO = 'EMP001';
SQL;

        $vnProcess = new Process([$duckdbBin, '-batch', '-light-mode', '-json']);
        $vnProcess->setInput($vnSql);
        $vnProcess->setTimeout(60);
        $vnProcess->run();

        $this->assertTrue($vnProcess->isSuccessful(), 'DuckDB VN query failed: '.$vnProcess->getErrorOutput());
        $vnOutput = trim($vnProcess->getOutput());
        preg_match_all('/\[.*?\]/s', $vnOutput, $vnMatches);
        $vnData = json_decode(end($vnMatches[0]), true);
        $this->assertIsArray($vnData);

        $vnMap = [];
        foreach ($vnData as $item) {
            $vnMap[$item['tag']] = $item['val'];
        }

        $this->assertSame('Tổng Công Ty May Bắc Giang', $vnMap['PG_VN']);
        $this->assertSame('Lỗi sợi chỉ may viền cổ', $vnMap['MSSQL_VN']);
        $this->assertSame('Nguyễn Văn An', $vnMap['ORACLE_VN']);
    }
}
