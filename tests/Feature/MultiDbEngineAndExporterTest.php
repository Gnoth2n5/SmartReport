<?php

namespace Tests\Feature;

use App\Exceptions\SqlSafetyException;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\DuckDbSourceAdapter;
use App\Services\DuckDb\SnapshotManager;
use App\Services\Export\DuckDbExporter;
use App\Services\Sql\SqlParameterExtractor;
use App\Services\Sql\SqlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class MultiDbEngineAndExporterTest extends TestCase
{
    use RefreshDatabase;

    protected DuckDbSourceAdapter $adapter;

    protected SnapshotManager $snapshotManager;

    protected DuckDbReportEngine $engine;

    protected DuckDbExporter $exporter;

    protected SqlParameterExtractor $extractor;

    protected SqlSafetyValidator $validator;

    protected string $testArtifactsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->adapter = app(DuckDbSourceAdapter::class);
        $this->snapshotManager = app(SnapshotManager::class);
        $this->extractor = app(SqlParameterExtractor::class);
        $this->validator = app(SqlSafetyValidator::class);
        $this->engine = app(DuckDbReportEngine::class);
        $this->exporter = app(DuckDbExporter::class);

        $this->testArtifactsDir = storage_path('app/test_artifacts');
        File::ensureDirectoryExists($this->testArtifactsDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testArtifactsDir)) {
            File::deleteDirectory($this->testArtifactsDir);
        }

        parent::tearDown();
    }

    /**
     * Test 1: Single-DB Select across pgsql, sqlsrv, and oracle with parameter binding.
     */
    public function test_single_db_select_across_pgsql_sqlsrv_and_oracle_with_parameter_binding(): void
    {
        // 1. PostgreSQL (pg_north)
        $pgView = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        // With Status = 'COMPLETED'
        $pgSnapId = $this->engine->executeAndCreateSnapshot($pgView, ['pg_north'], ['Status' => 'COMPLETED']);
        $pgPage = $this->engine->getPage($pgSnapId, 1, 50);
        $this->assertSame(12, $pgPage['totalRows'], 'pg_north with COMPLETED status must return exactly 12 contracts.');
        foreach ($pgPage['rows'] as $row) {
            $this->assertSame('COMPLETED', $row['status']);
            $this->assertSame('NORTH', $row['plant']);
        }
        $this->snapshotManager->deleteSnapshot($pgSnapId);

        // With Status = NULL (optional filter omitted -> all 25 contracts)
        $pgAllSnapId = $this->engine->executeAndCreateSnapshot($pgView, ['pg_north'], []);
        $pgAllPage = $this->engine->getPage($pgAllSnapId, 1, 50);
        $this->assertSame(25, $pgAllPage['totalRows'], 'pg_north without filter must return all 25 contracts.');
        $this->snapshotManager->deleteSnapshot($pgAllSnapId);

        // 2. SQL Server (mssql_erp)
        $mssqlView = CustomView::where('view_code', 'mssql-production-orders')->firstOrFail();
        // With Plant = 'NORTH'
        $mssqlSnapId = $this->engine->executeAndCreateSnapshot($mssqlView, ['mssql_erp'], ['Plant' => 'NORTH']);
        $mssqlPage = $this->engine->getPage($mssqlSnapId, 1, 50);
        $this->assertSame(20, $mssqlPage['totalRows'], 'mssql_erp with Plant NORTH must return 20 orders.');
        foreach ($mssqlPage['rows'] as $row) {
            $this->assertSame('NORTH', $row['plant']);
        }
        $this->snapshotManager->deleteSnapshot($mssqlSnapId);

        // With Plant = NULL (all 35 orders)
        $mssqlAllSnapId = $this->engine->executeAndCreateSnapshot($mssqlView, ['mssql_erp'], []);
        $mssqlAllPage = $this->engine->getPage($mssqlAllSnapId, 1, 50);
        $this->assertSame(35, $mssqlAllPage['totalRows'], 'mssql_erp without filter must return all 35 orders.');
        $this->snapshotManager->deleteSnapshot($mssqlAllSnapId);

        // 3. Oracle (oracle_hr)
        $oracleView = CustomView::where('view_code', 'oracle-hr-directory')->firstOrFail();
        // With DeptCode = 'PRD'
        $oracleSnapId = $this->engine->executeAndCreateSnapshot($oracleView, ['oracle_hr'], ['DeptCode' => 'PRD']);
        $oraclePage = $this->engine->getPage($oracleSnapId, 1, 50);
        $this->assertSame(12, $oraclePage['totalRows'], 'oracle_hr with DeptCode PRD must return 12 employees.');
        $this->snapshotManager->deleteSnapshot($oracleSnapId);

        // With DeptCode = NULL (all 30 employees)
        $oracleAllSnapId = $this->engine->executeAndCreateSnapshot($oracleView, ['oracle_hr'], []);
        $oracleAllPage = $this->engine->getPage($oracleAllSnapId, 1, 50);
        $this->assertSame(30, $oracleAllPage['totalRows'], 'oracle_hr without filter must return all 30 employees.');
        $this->snapshotManager->deleteSnapshot($oracleAllSnapId);
    }

    /**
     * Test 2: Multi-DB UNION ALL across pg_north and pg_south verifying _source_db badge and correct row counts.
     */
    public function test_multi_db_union_all_verifies_source_db_badge_and_row_counts(): void
    {
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $snapshotId = $this->engine->executeAndCreateSnapshot($view, ['pg_north', 'pg_south'], []);

        $page = $this->engine->getPage($snapshotId, 1, 100);

        // 25 from pg_north + 20 from pg_south = 45 total rows
        $this->assertSame(45, $page['totalRows'], 'Multi-DB UNION ALL of pg_north (25) and pg_south (20) must equal 45 rows.');

        // Verify that _source_db column is present in columns and rows
        $this->assertContains('_source_db', $page['columns']);

        $northCount = 0;
        $southCount = 0;
        foreach ($page['rows'] as $row) {
            $this->assertArrayHasKey('_source_db', $row);
            $this->assertContains($row['_source_db'], ['pg_north', 'pg_south']);

            if ($row['_source_db'] === 'pg_north') {
                $northCount++;
                $this->assertSame('NORTH', $row['plant']);
            } elseif ($row['_source_db'] === 'pg_south') {
                $southCount++;
                $this->assertSame('SOUTH', $row['plant']);
            }
        }

        $this->assertSame(25, $northCount, 'Exactly 25 rows must be tagged with pg_north.');
        $this->assertSame(20, $southCount, 'Exactly 20 rows must be tagged with pg_south.');

        $this->snapshotManager->deleteSnapshot($snapshotId);
    }

    /**
     * Test 3: Cross-Engine 2-DB JOIN (Oracle HR + SQL Server ERP).
     */
    public function test_cross_engine_join_oracle_hr_and_sql_server_erp(): void
    {
        $view = CustomView::where('view_code', 'join-oracle-mssql-productivity')->firstOrFail();
        $this->assertTrue($view->isFederatedJoin());

        // Test with NULL filter
        $snapId = $this->engine->executeAndCreateSnapshot($view, [], []);
        $page = $this->engine->getPage($snapId, 1, 50);

        $this->assertSame(10, $page['totalRows'], 'Joined productivity dataset must return exactly 10 productive employees.');
        $this->assertContains('EMP_NO', $page['columns']);
        $this->assertContains('FULL_NAME', $page['columns']);
        $this->assertContains('total_orders', $page['columns']);
        $this->assertContains('total_output', $page['columns']);
        $this->assertContains('BASE_SALARY', $page['columns']);

        // Check top performer
        $firstRow = $page['rows'][0];
        $this->assertNotEmpty($firstRow['FULL_NAME']);
        $this->assertGreaterThan(0, (float) $firstRow['total_output']);
        $this->snapshotManager->deleteSnapshot($snapId);

        // Test with @Plant = 'NORTH'
        $northSnapId = $this->engine->executeAndCreateSnapshot($view, [], ['Plant' => 'NORTH']);
        $northPage = $this->engine->getPage($northSnapId, 1, 50);
        $this->assertSame(5, $northPage['totalRows'], 'Filter Plant=NORTH must return 5 employees.');
        foreach ($northPage['rows'] as $r) {
            $this->assertSame('NORTH', $r['PLANT']);
        }
        $this->snapshotManager->deleteSnapshot($northSnapId);
    }

    /**
     * Test 4: 3-Way Enterprise Federation CTE combining PostgreSQL + SQL Server + Oracle.
     */
    public function test_three_way_enterprise_federation_combining_all_three_db_engines(): void
    {
        $view = CustomView::where('view_code', 'federated-3way-executive-report')->firstOrFail();
        $this->assertTrue($view->isFederatedJoin());

        $snapId = $this->engine->executeAndCreateSnapshot($view, [], []);
        $page = $this->engine->getPage($snapId, 1, 10);

        $this->assertSame(2, $page['totalRows'], 'Federated executive report must aggregate summary for NORTH and SOUTH plants.');
        $rowsByPlant = [];
        foreach ($page['rows'] as $r) {
            $rowsByPlant[$r['plant']] = $r;
        }

        $this->assertArrayHasKey('NORTH', $rowsByPlant);
        $this->assertArrayHasKey('SOUTH', $rowsByPlant);

        // Verify accurate enterprise metrics across all 3 engines
        $north = $rowsByPlant['NORTH'];
        $this->assertSame(25, (int) $north['contract_count'], 'NORTH must aggregate 25 contracts from PostgreSQL North.');
        $this->assertSame(15, (int) $north['headcount'], 'NORTH must aggregate 15 employees from Oracle HR.');
        $this->assertGreaterThan(0, (float) $north['total_contract_value']);
        $this->assertGreaterThan(0, (float) $north['total_prod_value']);
        $this->assertGreaterThan(0, (float) $north['total_payroll']);

        $south = $rowsByPlant['SOUTH'];
        $this->assertSame(20, (int) $south['contract_count'], 'SOUTH must aggregate 20 contracts from PostgreSQL South.');
        $this->assertSame(15, (int) $south['headcount'], 'SOUTH must aggregate 15 employees from Oracle HR.');
        $this->assertGreaterThan(0, (float) $south['total_contract_value']);
        $this->assertGreaterThan(0, (float) $south['total_prod_value']);
        $this->assertGreaterThan(0, (float) $south['total_payroll']);

        $this->snapshotManager->deleteSnapshot($snapId);
    }

    /**
     * Test 5: Snapshot Pagination: Page 1 and Page 2 queries, verifying that page 2 does NOT query external DBs.
     */
    public function test_snapshot_pagination_does_not_query_external_dbs_on_subsequent_pages(): void
    {
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $snapId = $this->engine->executeAndCreateSnapshot($view, ['pg_north', 'pg_south'], []);

        // Page 1: 10 rows
        $page1 = $this->engine->getPage($snapId, 1, 10);
        $this->assertSame(45, $page1['totalRows']);
        $this->assertSame(1, $page1['page']);
        $this->assertSame(10, $page1['perPage']);
        $this->assertSame(5, $page1['totalPages']);
        $this->assertTrue($page1['hasNextPage']);
        $this->assertCount(10, $page1['rows']);

        // Temporarily scramble all DataSource connection info in SQLite to guarantee external queries would FAIL
        DataSource::query()->update([
            'host' => '192.0.2.1', // RFC 5737 TEST-NET non-routable dummy IP
            'port' => 65534,
        ]);

        // Page 2: must still succeed cleanly because SnapshotManager queries ONLY the local .duckdb snapshot!
        $page2 = $this->engine->getPage($snapId, 2, 10);
        $this->assertSame(45, $page2['totalRows']);
        $this->assertSame(2, $page2['page']);
        $this->assertTrue($page2['hasNextPage']);
        $this->assertCount(10, $page2['rows']);

        // Verify that Page 1 and Page 2 contain completely different rows (disjoint row sets)
        $page1Contracts = array_column($page1['rows'], 'contract_no');
        $page2Contracts = array_column($page2['rows'], 'contract_no');
        $this->assertEmpty(
            array_intersect($page1Contracts, $page2Contracts),
            'Page 1 and Page 2 must not have overlapping contracts.'
        );

        // Page 5 (last page): must contain 5 rows and hasNextPage must be false
        $page5 = $this->engine->getPage($snapId, 5, 10);
        $this->assertSame(5, $page5['page']);
        $this->assertFalse($page5['hasNextPage']);
        $this->assertCount(5, $page5['rows']);

        $this->snapshotManager->deleteSnapshot($snapId);
    }

    /**
     * Test 6: OpenXML .xlsx export verification: DuckDB read_xlsx reads back the exported file and matches 100% of data.
     */
    public function test_openxml_xlsx_export_verified_via_duckdb_read_xlsx(): void
    {
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $snapId = $this->engine->executeAndCreateSnapshot($view, ['pg_north', 'pg_south'], []);

        $targetXlsx = $this->testArtifactsDir.'/contracts_export.xlsx';
        $exportedPath = $this->exporter->exportExcel($snapId, $targetXlsx);

        $this->assertSame($targetXlsx, $exportedPath);
        $this->assertFileExists($targetXlsx);
        $this->assertGreaterThan(0, filesize($targetXlsx));

        // OpenXML files are ZIP archives; check ZIP header signature (PK\x03\x04)
        $header = file_get_contents($targetXlsx, false, null, 0, 4);
        $this->assertSame("PK\x03\x04", $header, 'Exported .xlsx must be a genuine OpenXML zip archive.');

        // Verify DuckDB read_xlsx reads back the exported file with 100% data fidelity
        $duckdbBin = config('smartreport.duckdb_binary', 'duckdb');
        $verifySql = "LOAD excel;\nSELECT count(*)::BIGINT AS row_count, count(distinct contract_no)::BIGINT AS unique_contracts FROM read_xlsx('{$targetXlsx}');";

        $process = new Process([$duckdbBin, '-batch', '-light-mode', '-json']);
        $process->setInput($verifySql);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'DuckDB read_xlsx failed: '.$process->getErrorOutput());
        $output = trim($process->getOutput());
        preg_match_all('/\[.*?\]/s', $output, $matches);
        $data = json_decode(end($matches[0]), true);

        $this->assertIsArray($data);
        $this->assertSame(45, (int) $data[0]['row_count'], 'DuckDB read_xlsx must read back exactly 45 rows.');
        $this->assertSame(45, (int) $data[0]['unique_contracts'], 'All 45 contracts must be unique.');

        $this->snapshotManager->deleteSnapshot($snapId);
    }

    /**
     * Test 7: CSV export verification: UTF-8 BOM prefix \xEF\xBB\xBF present and Vietnamese text verified.
     */
    public function test_csv_export_has_utf8_bom_and_accurate_vietnamese_unicode(): void
    {
        $view = CustomView::where('view_code', 'oracle-hr-directory')->firstOrFail();
        $snapId = $this->engine->executeAndCreateSnapshot($view, ['oracle_hr'], []);

        $targetCsv = $this->testArtifactsDir.'/hr_export.csv';
        $exportedPath = $this->exporter->exportCsv($snapId, $targetCsv);

        $this->assertSame($targetCsv, $exportedPath);
        $this->assertFileExists($targetCsv);

        // 1. Verify UTF-8 BOM prefix \xEF\xBB\xBF
        $bom = file_get_contents($targetCsv, false, null, 0, 3);
        $this->assertSame("\xEF\xBB\xBF", $bom, 'CSV must start with UTF-8 BOM bytes (\xEF\xBB\xBF).');

        // 2. Verify Vietnamese text renders accurately
        $content = file_get_contents($targetCsv);
        $this->assertStringContainsString('Nguyễn Văn An', $content);
        $this->assertStringContainsString('Phòng Sản Xuất & May Mặc', $content);
        $this->assertStringContainsString('Trần Thị Mai', $content);

        // 3. Verify total row count (header + 30 rows = 31 lines)
        $lines = array_filter(explode("\n", trim($content)));
        $this->assertCount(31, $lines);

        $this->snapshotManager->deleteSnapshot($snapId);
    }

    /**
     * Test 8: Zip splitting export verification.
     */
    public function test_zip_splitting_export_verification(): void
    {
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $snapId = $this->engine->executeAndCreateSnapshot($view, ['pg_north', 'pg_south'], []);

        // 1. Test Excel zip splitting: 45 rows split by 10 -> 5 parts
        $targetXlsxZip = $this->testArtifactsDir.'/contracts_split.zip';
        $this->exporter->exportZip($snapId, 'xlsx', 10, $targetXlsxZip);

        $this->assertFileExists($targetXlsxZip);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($targetXlsxZip), 'Zip archive must be openable.');
        $this->assertSame(5, $zip->numFiles, 'Archive must contain 5 split parts.');

        $entryNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryNames[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('contracts_split_part1.xlsx', $entryNames);
        $this->assertContains('contracts_split_part2.xlsx', $entryNames);
        $this->assertContains('contracts_split_part3.xlsx', $entryNames);
        $this->assertContains('contracts_split_part4.xlsx', $entryNames);
        $this->assertContains('contracts_split_part5.xlsx', $entryNames);

        // Verify temporary part files were deleted from disk
        $this->assertFileDoesNotExist($this->testArtifactsDir.'/contracts_split_part1.xlsx');

        // 2. Test CSV zip splitting: 45 rows split by 20 -> 3 parts
        $targetCsvZip = $this->testArtifactsDir.'/contracts_csv_split.zip';
        $this->exporter->exportZip($snapId, 'csv', 20, $targetCsvZip);

        $this->assertFileExists($targetCsvZip);
        $csvZip = new ZipArchive;
        $this->assertTrue($csvZip->open($targetCsvZip));
        $this->assertSame(3, $csvZip->numFiles, 'CSV split by 20 on 45 rows must produce 3 parts.');
        $csvZip->close();

        $this->snapshotManager->deleteSnapshot($snapId);
    }

    /**
     * Test 9: SqlSafetyValidator blocks malicious queries and syntax injections.
     */
    public function test_sql_safety_validator_blocks_malicious_queries_and_syntax_injections(): void
    {
        // 1. DDL commands
        $this->expectSqlSafetyViolation('DROP TABLE users;');
        $this->expectSqlSafetyViolation('ALTER TABLE employees ADD COLUMN hack VARCHAR;');
        $this->expectSqlSafetyViolation('TRUNCATE TABLE production_orders;');
        $this->expectSqlSafetyViolation('CREATE TABLE evil (x INT);');

        // 2. DML commands
        $this->expectSqlSafetyViolation('INSERT INTO users VALUES (1, "hacker");');
        $this->expectSqlSafetyViolation('UPDATE users SET role = "admin";');
        $this->expectSqlSafetyViolation('DELETE FROM contracts WHERE id > 0;');

        // 3. Stacked queries / multiple statements
        $this->expectSqlSafetyViolation('SELECT * FROM users; DROP TABLE contracts;');
        $this->expectSqlSafetyViolation('SELECT 1; SELECT 2;');

        // 4. Execution and system commands
        $this->expectSqlSafetyViolation('EXEC xp_cmdshell "whoami";');
        $this->expectSqlSafetyViolation('EXECUTE sp_who;');
        $this->expectSqlSafetyViolation('CALL evil_procedure();');

        // 5. DuckDB catalog and extension commands
        $this->expectSqlSafetyViolation("COPY (SELECT * FROM contracts) TO '/tmp/leak.csv';");
        $this->expectSqlSafetyViolation("ATTACH 'evil.duckdb' AS evil;");
        $this->expectSqlSafetyViolation("INSTALL 'httpfs';");
        $this->expectSqlSafetyViolation("LOAD 'spatial';");

        // 6. Filesystem read/write functions
        $this->expectSqlSafetyViolation("SELECT * FROM read_csv('/etc/passwd');");
        $this->expectSqlSafetyViolation("SELECT * FROM read_parquet('s3://secret.parquet');");
        $this->expectSqlSafetyViolation("SELECT * FROM read_blob('/etc/shadow');");
        $this->expectSqlSafetyViolation("SELECT * FROM write_csv('/tmp/out.csv');");

        // 7. Non-read queries starting with other tokens
        $this->expectSqlSafetyViolation('PRAGMA table_info("contracts");');

        // 8. Legitimate queries MUST pass
        $this->assertTrue($this->validator->isSafe("SELECT * FROM contracts WHERE status = 'DELETED'"));
        $this->assertTrue($this->validator->isSafe("SELECT last_update_time FROM logs WHERE note = 'drop table soon'"));
        $this->assertTrue($this->validator->isSafe('WITH cte AS (SELECT contract_no FROM contracts) SELECT * FROM cte WHERE contract_no IS NOT NULL;'));

        // 9. Parameter extractor token inference verification
        $tokens = $this->extractor->extract('SELECT * FROM t WHERE sign_date >= @StartDate AND delivery_time <= @EndTime AND total_qty = @OrderQty AND price = @ItemPrice AND plant = @Plant AND dept = @DeptCode AND status = @Status AND notes = @CustomerNote AND date = @today');

        $tokenMap = [];
        foreach ($tokens as $t) {
            $tokenMap[$t['name']] = $t;
        }

        $this->assertSame('date', $tokenMap['StartDate']['type']);
        $this->assertSame('date', $tokenMap['EndTime']['type']);
        $this->assertSame('date', $tokenMap['today']['type']);
        $this->assertSame(date('Y-m-d'), $tokenMap['today']['default_value']);
        $this->assertSame('number', $tokenMap['OrderQty']['type']);
        $this->assertSame('number', $tokenMap['ItemPrice']['type']);
        $this->assertSame('select', $tokenMap['Plant']['type']);
        $this->assertArrayHasKey('NORTH', $tokenMap['Plant']['options']);
        $this->assertSame('select', $tokenMap['DeptCode']['type']);
        $this->assertArrayHasKey('PRD', $tokenMap['DeptCode']['options']);
        $this->assertSame('select', $tokenMap['Status']['type']);
        $this->assertArrayHasKey('ACTIVE', $tokenMap['Status']['options']);
        $this->assertSame('text', $tokenMap['CustomerNote']['type']);
    }

    /**
     * Helper to assert that a query fails safety validation.
     */
    protected function expectSqlSafetyViolation(string $sql): void
    {
        $this->assertFalse(
            $this->validator->isSafe($sql),
            "Query was expected to be rejected by SqlSafetyValidator: [{$sql}]"
        );

        $threw = false;
        try {
            $this->validator->validate($sql);
        } catch (SqlSafetyException) {
            $threw = true;
        }

        $this->assertTrue($threw, "Query was expected to throw SqlSafetyException: [{$sql}]");
    }
}
