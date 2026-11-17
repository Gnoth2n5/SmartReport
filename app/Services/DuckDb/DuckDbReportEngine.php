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
        $plan = $this->compileExecutionPlan($view, $selectedSourceCodes, $params);

        // Prepare snapshot file path
        $snapshotId = (string) Str::uuid();
        $snapshotPath = $this->snapshotManager->getSnapshotPath($snapshotId);
        if (file_exists($snapshotPath)) {
            @unlink($snapshotPath);
        }

        $fullScript = $this->assembleFederationScript($plan, $snapshotId, $view->view_code);

        // Execute DuckDB CLI against the target snapshot file
        try {
            $this->adapter->runQuery($fullScript, $snapshotPath, false, 120);
        } catch (\Throwable $e) {
            $this->snapshotManager->deleteSnapshot($snapshotId);
            throw $e;
        }

        return $snapshotId;
    }

    /**
     * Build sanitized SQL inspection data for Tab 3 (SQL Query Inspector) without executing queries.
     *
     * @param  list<string>  $selectedSourceCodes
     * @param  array<string, mixed>  $params
     * @return array{
     *     raw_sql: string,
     *     individual_queries: list<array{source_code: string, source_name: string, driver: string, alias: string, sql: string}>,
     *     federated_sql: string,
     *     sources: Collection<int, DataSource>,
     *     applied_params: array<string, mixed>
     * }
     */
    public function buildInspectionData(CustomView $view, array $selectedSourceCodes = [], array $params = []): array
    {
        $plan = $this->compileExecutionPlan($view, $selectedSourceCodes, $params);
        $script = $this->assembleFederationScript($plan, null, $view->view_code);

        return [
            'raw_sql' => (string) $view->sql_text,
            'individual_queries' => $plan['individual_queries'],
            'federated_sql' => $this->adapter->redactSecrets($script),
            'sources' => $plan['sources'],
            'applied_params' => $params,
        ];
    }

    /**
     * Execute an ad-hoc SELECT/WITH query against a single active source or federated sources
     * and materialize it into a detached local DuckDB snapshot.
     */
    public function executeAdhocAndCreateSnapshot(string $sql, ?string $sourceCode = null): string
    {
        $this->safetyValidator->validate($sql);

        $cleanSql = $this->sanitizeExecutableSql($sql);

        if ($sourceCode !== null && $sourceCode !== '' && $sourceCode !== 'federated') {
            $source = DataSource::where('code', $sourceCode)->where('is_active', true)->first();
            if (! $source) {
                throw new InvalidArgumentException("Data source [{$sourceCode}] not found or inactive.");
            }

            $sources = new Collection([$source]);
            $executableSql = $this->adapter->qualifySqlForSource($cleanSql, $source);
        } else {
            $allActive = DataSource::where('is_active', true)->get();
            if ($allActive->isEmpty()) {
                throw new InvalidArgumentException('No active data sources available.');
            }

            $matched = $allActive->filter(
                fn (DataSource $source): bool => (bool) preg_match('/\b'.preg_quote($source->code, '/').'\b/i', $cleanSql)
            )->values();

            $sources = $matched->isNotEmpty() ? $matched : $allActive;
            $executableSql = $cleanSql;
        }

        $snapshotId = (string) Str::uuid();
        $snapshotPath = $this->snapshotManager->getSnapshotPath($snapshotId);
        if (file_exists($snapshotPath)) {
            @unlink($snapshotPath);
        }

        $loadStatements = [];
        $attachStatements = [];
        $detachStatements = [];

        foreach ($sources as $source) {
            $loadStatements[$source->driver] = $this->adapter->generateLoadExtensionSql($source->driver);
            $attachStatements[] = $this->adapter->generateAttachSql($source);
            $detachStatements[] = $this->adapter->generateDetachSql($source);
        }

        $plan = [
            'load_statements' => $loadStatements,
            'attach_statements' => $attachStatements,
            'detach_statements' => $detachStatements,
            'staging_sql' => [sprintf("CREATE TEMPORARY TABLE __adhoc_result AS (\n%s\n);", $executableSql)],
            'final_query' => 'SELECT * FROM __adhoc_result',
        ];

        $fullScript = $this->assembleFederationScript($plan, $snapshotId, 'adhoc_sql_export');

        try {
            $this->adapter->runQuery($fullScript, $snapshotPath, false, 120);
        } catch (\Throwable $e) {
            $this->snapshotManager->deleteSnapshot($snapshotId);
            throw $e;
        }

        return $snapshotId;
    }

    /**
     * Validate SQL, resolve sources, and compile DuckDB execution plan without touching disk or running DuckDB.
     *
     * @param  list<string>  $selectedSourceCodes
     * @param  array<string, mixed>  $params
     * @return array{
     *     sources: Collection<int, DataSource>,
     *     load_statements: array<string, string>,
     *     attach_statements: list<string>,
     *     detach_statements: list<string>,
     *     staging_sql: list<string>,
     *     final_query: string,
     *     individual_queries: list<array{source_code: string, source_name: string, driver: string, alias: string, sql: string}>
     * }
     */
    protected function compileExecutionPlan(CustomView $view, array $selectedSourceCodes = [], array $params = []): array
    {
        // 1. Validate SQL safety
        $this->safetyValidator->validate($view->sql_text);
        if ($view->isFederatedJoin() && is_array($view->source_queries)) {
            foreach ($view->source_queries as $sq) {
                $sqSql = (string) ($sq['sql_text'] ?? $sq['query'] ?? '');
                if ($sqSql !== '') {
                    $this->safetyValidator->validate($sqSql);
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

        // 3. Generate extension LOAD statements
        $loadStatements = [];
        foreach ($sources as $source) {
            $loadStatements[$source->driver] = $this->adapter->generateLoadExtensionSql($source->driver);
        }

        // 4. Generate ATTACH & DETACH statements
        $attachStatements = [];
        $detachStatements = [];
        foreach ($sources as $source) {
            $attachStatements[] = $this->adapter->generateAttachSql($source);
            $detachStatements[] = $this->adapter->generateDetachSql($source);
        }

        // 5. Generate staging temporary tables, final query, and per-source queries
        $stagingSql = [];
        $finalQuery = '';
        $individualQueries = [];

        if ($view->isSingleOrUnion()) {
            if ($sources->count() > 1) {
                $unionParts = [];
                foreach ($sources as $index => $source) {
                    $qualified = $this->adapter->qualifySqlForSource($view->sql_text, $source);
                    $bound = $this->sanitizeExecutableSql($this->adapter->bindParameters($qualified, $params));
                    $stagingSql[] = sprintf('CREATE TEMPORARY TABLE __part_%d AS %s;', $index, $bound);
                    $unionParts[] = sprintf("SELECT '%s' AS _source_db, * FROM __part_%d", $source->code, $index);
                    $individualQueries[] = [
                        'source_code' => $source->code,
                        'source_name' => $source->name,
                        'driver' => $source->driver,
                        'alias' => $source->code,
                        'sql' => $bound,
                    ];
                }
                $finalQuery = implode("\nUNION ALL\n", $unionParts);
            } else {
                $source = $sources->first();
                $qualified = $this->adapter->qualifySqlForSource($view->sql_text, $source);
                $bound = $this->sanitizeExecutableSql($this->adapter->bindParameters($qualified, $params));
                $stagingSql[] = sprintf('CREATE TEMPORARY TABLE __part_0 AS %s;', $bound);
                $finalQuery = 'SELECT * FROM __part_0';
                $individualQueries[] = [
                    'source_code' => $source->code,
                    'source_name' => $source->name,
                    'driver' => $source->driver,
                    'alias' => $source->code,
                    'sql' => $bound,
                ];
            }
        } else {
            // Federated Join: stage each source query into a temporary table named after alias
            foreach ($view->source_queries ?? [] as $sq) {
                $alias = (string) ($sq['alias'] ?? $sq['table_alias'] ?? '');
                $sourceCode = (string) ($sq['source_code'] ?? '');
                $sqSql = (string) ($sq['sql_text'] ?? $sq['query'] ?? '');
                $source = $sourcesMap[$sourceCode] ?? null;

                if (! $source) {
                    throw new RuntimeException("Data source [{$sourceCode}] required for alias [{$alias}] not found or inactive.");
                }

                $qualified = $this->adapter->qualifySqlForSource($sqSql, $source);
                $bound = $this->sanitizeExecutableSql($this->adapter->bindParameters($qualified, $params));
                $stagingSql[] = sprintf('CREATE TEMPORARY TABLE %s AS %s;', $alias, $bound);
                $individualQueries[] = [
                    'source_code' => $source->code,
                    'source_name' => $source->name,
                    'driver' => $source->driver,
                    'alias' => $alias,
                    'sql' => $bound,
                ];
            }

            // Final federated join query references the local temporary tables
            $finalQuery = $this->sanitizeExecutableSql($this->adapter->bindParameters($view->sql_text, $params));
        }

        return [
            'sources' => $sources,
            'load_statements' => $loadStatements,
            'attach_statements' => $attachStatements,
            'detach_statements' => $detachStatements,
            'staging_sql' => $stagingSql,
            'final_query' => $finalQuery,
            'individual_queries' => $individualQueries,
        ];
    }

    /**
     * Assemble DuckDB script either for snapshot materialization or SQL inspection.
     *
     * @param  array{
     *     load_statements: array<string, string>,
     *     attach_statements: list<string>,
     *     detach_statements: list<string>,
     *     staging_sql: list<string>,
     *     final_query: string
     * }  $plan
     */
    protected function assembleFederationScript(array $plan, ?string $snapshotId, string $viewCode): string
    {
        $scriptParts = [
            implode("\n", array_values($plan['load_statements'])),
            implode("\n", $plan['attach_statements']),
            implode("\n", $plan['staging_sql']),
            implode("\n", $plan['detach_statements']),
            'SET enable_external_access = false;',
        ];

        $cleanFinalQuery = $this->sanitizeExecutableSql($plan['final_query']);

        if ($snapshotId !== null) {
            $scriptParts[] = sprintf(
                'CREATE TABLE report_result AS SELECT row_number() OVER ()::INTEGER AS __row_id, * FROM (%s);',
                $cleanFinalQuery
            );
            $scriptParts[] = 'CREATE UNIQUE INDEX idx_report_result_row_id ON report_result (__row_id);';
            $scriptParts[] = 'CREATE TABLE report_meta (snapshot_id VARCHAR, view_code VARCHAR, total_rows BIGINT, created_at TIMESTAMP, columns JSON);';
            $scriptParts[] = sprintf(
                "INSERT INTO report_meta SELECT '%s' AS snapshot_id, '%s' AS view_code, (SELECT count(*) FROM report_result) AS total_rows, timezone('UTC', current_timestamp) AS created_at, (SELECT to_json(list(column_name)) FROM (SELECT column_name FROM information_schema.columns WHERE table_name = 'report_result' AND column_name != '__row_id' ORDER BY ordinal_position)) AS columns;",
                $snapshotId,
                $viewCode
            );
        } else {
            $scriptParts[] = $cleanFinalQuery.';';
        }

        return implode("\n\n", array_filter($scriptParts));
    }

    /**
     * Strip SQL comments safely before trimming trailing semicolons and whitespace.
     */
    protected function sanitizeExecutableSql(string $sql): string
    {
        $withoutComments = $this->safetyValidator->stripComments($sql);

        return rtrim(trim($withoutComments), "; \t\n\r\0\x0B");
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
