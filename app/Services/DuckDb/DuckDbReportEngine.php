<?php

namespace App\Services\DuckDb;

use App\Models\CustomView;
use App\Models\DataSource;
use App\Services\Sql\SqlParameterExtractor;
use App\Services\Sql\SqlSafetyValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DuckDbReportEngine
{
    public function __construct(
        protected DuckDbSourceAdapter $adapter,
        protected SnapshotManager $snapshotManager,
        protected SqlParameterExtractor $paramExtractor,
        protected SqlSafetyValidator $safetyValidator
    ) {}

    /**
     * Execute custom view query, detach external sources, lock filesystem,
     * and persist materialized snapshot to storage/app/snapshots/{snapshotId}.duckdb.
     *
     * @param  list<string>  $selectedSourceCodes
     * @param  array<string, mixed>  $params
     * @return string Snapshot ID (UUID)
     */
    public function executeAndCreateSnapshot(CustomView $view, array $selectedSourceCodes = [], array $params = []): string
    {
        // 1. Validate SQL safety
        $this->safetyValidator->validate($view->sql_text);
        if ($view->isFederatedJoin() && is_array($view->source_queries)) {
            foreach ($view->source_queries as $sq) {
                if (! empty($sq['sql_text'])) {
                    $this->safetyValidator->validate($sq['sql_text']);
                }
            }
        }

        // 2. Resolve required data sources
        /** @var Collection<int, DataSource> $sources */
        if ($view->isSingleOrUnion()) {
            $codes = ! empty($selectedSourceCodes)
                ? $selectedSourceCodes
                : ($view->default_sources ?: $view->allowed_sources ?: []);

            if (empty($codes)) {
                throw new InvalidArgumentException("No data source selected for view [{$view->view_code}].");
            }

            $sources = DataSource::whereIn('code', $codes)->where('is_active', true)->get();
            if ($sources->isEmpty()) {
                throw new InvalidArgumentException('None of the selected data sources ['.implode(', ', $codes).'] are active.');
            }
        } else {
            // Federated join: collect all source codes from source_queries
            $codes = [];
            foreach ($view->source_queries ?? [] as $sq) {
                if (! empty($sq['source_code'])) {
                    $codes[] = $sq['source_code'];
                }
            }
            $codes = array_values(array_unique($codes));
            $sources = DataSource::whereIn('code', $codes)->where('is_active', true)->get();
        }

        $sourcesMap = [];
        foreach ($sources as $s) {
            $sourcesMap[$s->code] = $s;
        }

        // 3. Prepare snapshot file path
        $snapshotId = (string) Str::uuid();
        $snapshotPath = $this->snapshotManager->getSnapshotPath($snapshotId);
        if (file_exists($snapshotPath)) {
            @unlink($snapshotPath);
        }

        // 4. Generate extension LOAD statements
        $loadStatements = [];
        foreach ($sources as $source) {
            $loadStatements[$source->driver] = $this->adapter->generateLoadExtensionSql($source->driver);
        }

        // 5. Generate ATTACH & DETACH statements
        $attachStatements = [];
        $detachStatements = [];
        foreach ($sources as $source) {
            $attachStatements[] = $this->adapter->generateAttachSql($source);
            $detachStatements[] = $this->adapter->generateDetachSql($source);
        }

        // 6. Generate staging temporary tables and final query
        $stagingSql = [];
        $finalQuery = '';

        if ($view->isSingleOrUnion()) {
            if ($sources->count() > 1) {
                $unionParts = [];
                foreach ($sources as $index => $source) {
                    $qualified = $this->adapter->qualifySqlForSource($view->sql_text, $source);
                    $bound = $this->adapter->bindParameters($qualified, $params);
                    $stagingSql[] = sprintf('CREATE TEMPORARY TABLE __part_%d AS %s;', $index, $bound);
                    $unionParts[] = sprintf("SELECT '%s' AS _source_db, * FROM __part_%d", $source->code, $index);
                }
                $finalQuery = implode("\nUNION ALL\n", $unionParts);
            } else {
                $source = $sources->first();
                $qualified = $this->adapter->qualifySqlForSource($view->sql_text, $source);
                $bound = $this->adapter->bindParameters($qualified, $params);
                $stagingSql[] = sprintf('CREATE TEMPORARY TABLE __part_0 AS %s;', $bound);
                $finalQuery = 'SELECT * FROM __part_0';
            }
        } else {
            // Federated Join: stage each source query into a temporary table named after alias
            foreach ($view->source_queries ?? [] as $sq) {
                $alias = $sq['alias'];
                $sourceCode = $sq['source_code'];
                $source = $sourcesMap[$sourceCode] ?? null;

                if (! $source) {
                    throw new RuntimeException("Data source [{$sourceCode}] required for alias [{$alias}] not found or inactive.");
                }

                $qualified = $this->adapter->qualifySqlForSource($sq['sql_text'], $source);
                $bound = $this->adapter->bindParameters($qualified, $params);
                $stagingSql[] = sprintf('CREATE TEMPORARY TABLE %s AS %s;', $alias, $bound);
            }

            // Final federated join query references the local temporary tables
            $finalQuery = $this->adapter->bindParameters($view->sql_text, $params);
        }

        // 7. Assemble the complete DuckDB snapshot script
        $scriptParts = [
            implode("\n", array_values($loadStatements)),
            implode("\n", $attachStatements),
            implode("\n", $stagingSql),
            implode("\n", $detachStatements),
            'SET enable_external_access = false;',
            sprintf(
                'CREATE TABLE report_result AS SELECT row_number() OVER ()::INTEGER AS __row_id, * FROM (%s);',
                $finalQuery
            ),
            'CREATE UNIQUE INDEX idx_report_result_row_id ON report_result (__row_id);',
            'CREATE TABLE report_meta (snapshot_id VARCHAR, view_code VARCHAR, total_rows BIGINT, created_at TIMESTAMP, columns JSON);',
            sprintf(
                "INSERT INTO report_meta SELECT '%s' AS snapshot_id, '%s' AS view_code, (SELECT count(*) FROM report_result) AS total_rows, current_timestamp AS created_at, (SELECT to_json(list(column_name)) FROM (SELECT column_name FROM information_schema.columns WHERE table_name = 'report_result' AND column_name != '__row_id' ORDER BY ordinal_position)) AS columns;",
                $snapshotId,
                $view->view_code
            ),
        ];

        $fullScript = implode("\n\n", array_filter($scriptParts));

        // 8. Execute DuckDB CLI against the target snapshot file
        $this->adapter->runQuery($fullScript, $snapshotPath, false, 120);

        return $snapshotId;
    }

    /**
     * Read a page of rows from the snapshot file.
     * Guaranteed zero connections to external databases.
     *
     * @return array{rows: list<array<string, mixed>>, totalRows: int, page: int, perPage: int, totalPages: int, hasNextPage: bool, columns: list<string>}
     */
    public function getPage(string $snapshotId, int $page = 1, int $perPage = 10): array
    {
        return $this->snapshotManager->getPage($snapshotId, $page, $perPage);
    }

    /**
     * Preview first N rows of a query without persisting long-term snapshot.
     *
     * @param  list<string>  $selectedSourceCodes
     * @param  array<string, mixed>  $params
     * @return array{rows: list<array<string, mixed>>, totalRows: int, columns: list<string>, latency_ms: float}
     */
    public function preview(CustomView $view, array $selectedSourceCodes = [], array $params = [], int $limit = 10): array
    {
        $start = microtime(true);
        $snapshotId = $this->executeAndCreateSnapshot($view, $selectedSourceCodes, $params);

        try {
            $pageData = $this->getPage($snapshotId, 1, $limit);
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'rows' => $pageData['rows'],
                'totalRows' => $pageData['totalRows'],
                'columns' => $pageData['columns'],
                'latency_ms' => $latency,
                'snapshotId' => $snapshotId,
            ];
        } finally {
            // Keep snapshot or clean up as needed
        }
    }
}
