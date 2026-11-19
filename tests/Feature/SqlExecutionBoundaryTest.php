<?php

namespace Tests\Feature;

use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\DuckDbSourceAdapter;
use App\Services\Sql\SqlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SqlExecutionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_federated_report_refuses_a_source_outside_the_readers_permissions(): void
    {
        $this->seed();
        $view = CustomView::create([
            'view_code' => 'restricted-hr', 'view_name' => 'Restricted HR',
            'execution_mode' => 'federated_join', 'sql_text' => 'SELECT * FROM hr_rows',
            'source_queries' => [['source_code' => 'oracle_hr', 'alias' => 'hr_rows', 'sql_text' => 'SELECT * FROM APP.EMPLOYEES']],
            'roles' => ['report_viewer'], 'is_active' => true,
        ]);
        RolePermission::where('role_code', 'report_viewer')->update(['allowed_sources' => ['pg_north']]);
        $this->actingAs(User::factory()->create(['roles' => ['report_viewer']]));
        $this->mock(DuckDbReportEngine::class)->shouldNotReceive('executeAndCreateSnapshot');

        $this->get('/admin/reports/'.$view->view_code)->assertForbidden();
    }

    public function test_parameters_preserve_single_double_and_dollar_quoted_sql(): void
    {
        $adapter = app(DuckDbSourceAdapter::class);
        $sql = 'SELECT $$support@today$$ AS literal, $tag$@name$tag$ AS tagged, \'public.v_epcontract\' AS schema_text, @name AS "@column"';
        $bound = $adapter->bindParameters($sql, ['today' => '2026-11-19', 'name' => "O'Brien"]);
        $rows = $adapter->runQuery($bound);

        $this->assertSame('support@today', $rows[0]['literal']);
        $this->assertSame('@name', $rows[0]['tagged']);
        $this->assertSame('public.v_epcontract', $rows[0]['schema_text']);
        $this->assertSame("O'Brien", $rows[0]['@column']);
    }

    public function test_source_schema_does_not_rewrite_literals_or_cte_names(): void
    {
        $this->seed();
        $engine = app(DuckDbReportEngine::class);
        $view = new CustomView([
            'view_code' => 'schema-context', 'execution_mode' => 'single_or_union',
            'sql_text' => "WITH v_epcontract AS (SELECT 'public.v_epcontract' AS literal, count(*) AS total FROM v_epcontract_detail) SELECT * FROM v_epcontract",
        ]);
        $snapshot = $engine->executeAndCreateSnapshot($view, ['pg_north']);
        $rows = $engine->getPage($snapshot)['rows'];

        $this->assertSame('public.v_epcontract', $rows[0]['literal']);
        $this->assertGreaterThan(0, $rows[0]['total']);
    }

    public function test_file_table_literals_are_rejected_and_quoted_file_identifiers_cannot_be_read(): void
    {
        $this->seed();
        $path = storage_path('private-marker.csv');
        file_put_contents($path, "marker\nPRIVATE_MARKER\n");
        $validator = app(SqlSafetyValidator::class);
        $this->assertFalse($validator->isSafe("SELECT * FROM '{$path}'"));
        $this->assertFalse($validator->isSafe('SELECT * FROM $$'.$path.'$$'));

        $this->expectException(RuntimeException::class);
        app(DuckDbReportEngine::class)->executeAdhocAndCreateSnapshot('SELECT * FROM "'.$path.'"', 'pg_north');
    }

    public function test_special_passwords_are_parsed_and_never_appear_in_errors_or_inspection(): void
    {
        $this->seed();
        $adapter = app(DuckDbSourceAdapter::class);
        $password = "REVIEW_SECRET' with\\slash;";
        foreach (['pg_north', 'mssql_erp', 'oracle_hr'] as $code) {
            $source = DataSource::where('code', $code)->firstOrFail();
            $source->password = $password;
            $sql = $adapter->generateLoadExtensionSql($source->driver)."\n".$adapter->generateAttachSql($source);
            $this->assertStringNotContainsString('REVIEW_SECRET', $adapter->redactSecrets($sql));
            $connection = $adapter->testConnection($source);
            $this->assertFalse($connection['success']);
            $this->assertStringNotContainsString('Parser Error', $connection['message']);
            $this->assertStringNotContainsString('REVIEW_SECRET', $connection['message']);
        }
    }
}
