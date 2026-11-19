<?php

namespace Tests\Feature;

use App\Models\CustomView;
use App\Services\DuckDb\DuckDbReportEngine;
use App\Services\DuckDb\SnapshotManager;
use App\Services\Export\DuckDbExporter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SnapshotLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_snapshots_are_refused_for_metadata_pagination_and_export(): void
    {
        $this->seed();
        $id = app(DuckDbReportEngine::class)->executeAdhocAndCreateSnapshot('SELECT 1 AS value', 'pg_north');
        $manager = app(SnapshotManager::class);
        $this->travel(31)->minutes();

        $this->assertFalse($manager->snapshotExists($id));
        $this->assertNull($manager->getMetadata($id));
        try {
            $manager->getPage($id);
            $this->fail('Expired snapshot must not be paginated.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        app(DuckDbExporter::class)->exportCsv($id, storage_path('expired.csv'));
    }

    public function test_scheduled_cleanup_removes_only_expired_snapshots(): void
    {
        $this->seed();
        $engine = app(DuckDbReportEngine::class);
        $old = $engine->executeAdhocAndCreateSnapshot('SELECT 1 AS value', 'pg_north');
        $this->travel(31)->minutes();
        $active = $engine->executeAdhocAndCreateSnapshot('SELECT 2 AS value', 'pg_north');
        touch(app(SnapshotManager::class)->getSnapshotPath($active), now()->timestamp);
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'smartreport:clean-expired-snapshots');
        $this->assertNotNull($event);
        $event->run($this->app);

        $this->assertFileDoesNotExist(app(SnapshotManager::class)->getSnapshotPath($old));
        $this->assertTrue(app(SnapshotManager::class)->snapshotExists($active));
    }

    public function test_preview_returns_a_bounded_result_and_leaves_no_snapshot(): void
    {
        $this->seed();
        $view = CustomView::where('view_code', 'pg-multi-plant-contracts')->firstOrFail();
        $manager = app(SnapshotManager::class);
        $before = glob($manager->getSnapshotDirectory().'/*.duckdb');
        $preview = app(DuckDbReportEngine::class)->preview($view, ['pg_north', 'pg_south'], [], 10);

        $this->assertCount(10, $preview['rows']);
        $this->assertSame(10, $preview['totalRows']);
        $this->assertSame($before, glob($manager->getSnapshotDirectory().'/*.duckdb'));
    }

    public function test_large_csv_exports_with_bom_under_a_fixed_memory_budget(): void
    {
        $this->seed();
        $id = app(DuckDbReportEngine::class)->executeAdhocAndCreateSnapshot("SELECT i, repeat('x', 512) AS payload FROM range(50000) t(i)", 'pg_north');
        $target = storage_path("large'export.csv");
        $script = <<<'CODE'
require __AUTOLOAD__;
$app = require __APP__;
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\Export\DuckDbExporter::class)->exportCsv(__ID__, __TARGET__);
echo memory_get_peak_usage(true);
CODE;
        $script = str_replace(['__AUTOLOAD__', '__APP__', '__ID__', '__TARGET__'], array_map(fn (string $value): string => var_export($value, true), [base_path('vendor/autoload.php'), base_path('bootstrap/app.php'), $id, $target]), $script);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $script], base_path(), [
            'LARAVEL_STORAGE_PATH' => storage_path(),
            'SMARTREPORT_SNAPSHOT_DIR' => config('smartreport.snapshot_dir'),
            'DUCKDB_BINARY' => config('smartreport.duckdb_binary'),
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertGreaterThan(25_000_000, filesize($target));
        $input = fopen($target, 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($input, 3));
        $this->assertSame("i,payload\n", fgets($input));
        fclose($input);
        $this->assertSame([], glob($target.'.*.raw*'));
    }
}
