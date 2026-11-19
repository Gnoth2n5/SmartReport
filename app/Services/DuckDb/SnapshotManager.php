<?php

namespace App\Services\DuckDb;

use Illuminate\Support\Facades\File;
use RuntimeException;

class SnapshotManager
{
    public function __construct(
        protected DuckDbSourceAdapter $adapter
    ) {}

    /**
     * Get the directory where DuckDB snapshot files are stored.
     */
    public function getSnapshotDirectory(): string
    {
        $dir = config('smartreport.snapshot_dir', storage_path('app/snapshots'));
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }

        return $dir;
    }

    /**
     * Get the absolute path for a snapshot file.
     */
    public function getSnapshotPath(string $snapshotId): string
    {
        return $this->getSnapshotDirectory().DIRECTORY_SEPARATOR."{$snapshotId}.duckdb";
    }

    /**
     * Check if a snapshot file exists.
     */
    public function snapshotExists(string $snapshotId): bool
    {
        $path = $this->getSnapshotPath($snapshotId);
        clearstatcache(true, $path);

        return is_file($path) && filemtime($path) > now()->timestamp - max(1, (int) config('smartreport.snapshot_ttl_minutes', 30)) * 60;
    }

    /**
     * Read paginated rows directly from the detached snapshot file.
     * Guaranteed 0 queries to external source databases.
     *
     * @return array{rows: list<array<string, mixed>>, totalRows: int, page: int, perPage: int, totalPages: int, hasNextPage: bool, columns: list<string>}
     */
    public function getPage(string $snapshotId, int $page = 1, int $perPage = 10): array
    {
        $snapshotPath = $this->getSnapshotPath($snapshotId);
        if (! $this->snapshotExists($snapshotId)) {
            throw new RuntimeException('Snapshot has expired or does not exist. Run the report again.');
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage + 1;

        $meta = $this->getMetadata($snapshotId);
        $totalRows = (int) ($meta['total_rows'] ?? 0);
        $columns = (array) ($meta['columns'] ?? []);

        $sql = sprintf(
            'SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id LIMIT %d OFFSET %d;',
            $limit,
            $offset
        );

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->adapter->runQuery($sql, $snapshotPath, true);

        $hasNextPage = count($rawRows) > $perPage;
        $rows = $hasNextPage ? array_slice($rawRows, 0, $perPage) : $rawRows;
        $totalPages = $perPage > 0 ? (int) ceil($totalRows / $perPage) : 1;

        return [
            'rows' => $rows,
            'totalRows' => $totalRows,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'hasNextPage' => $hasNextPage,
            'columns' => $columns,
        ];
    }

    /**
     * Read metadata from report_meta table in snapshot file.
     *
     * @return array{snapshot_id?: string, view_code?: string, total_rows?: int, created_at?: string, columns?: list<string>}|null
     */
    public function getMetadata(string $snapshotId): ?array
    {
        $snapshotPath = $this->getSnapshotPath($snapshotId);
        if (! $this->snapshotExists($snapshotId)) {
            return null;
        }

        /** @var list<array<string, mixed>> $metaRows */
        $metaRows = $this->adapter->runQuery('SELECT * FROM report_meta LIMIT 1;', $snapshotPath, true);
        if (empty($metaRows)) {
            return null;
        }

        $meta = $metaRows[0];
        if (isset($meta['columns']) && is_string($meta['columns'])) {
            $meta['columns'] = json_decode($meta['columns'], true) ?: [];
        }

        return $meta;
    }

    /**
     * Delete a snapshot file.
     */
    public function deleteSnapshot(string $snapshotId): bool
    {
        $path = $this->getSnapshotPath($snapshotId);
        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Clean up expired snapshots older than TTL minutes.
     */
    public function cleanExpiredSnapshots(?int $ttlMinutes = null): int
    {
        $ttl = max(1, $ttlMinutes ?? (int) config('smartreport.snapshot_ttl_minutes', 30));
        $threshold = now()->timestamp - ($ttl * 60);
        $dir = $this->getSnapshotDirectory();
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.duckdb') ?: [];

        $deletedCount = 0;
        foreach ($files as $file) {
            clearstatcache(true, $file);
            if (is_file($file) && filemtime($file) <= $threshold) {
                if (@unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }
}
