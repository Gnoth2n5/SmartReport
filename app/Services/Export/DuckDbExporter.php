<?php

namespace App\Services\Export;

use App\Services\DuckDb\DuckDbSourceAdapter;
use App\Services\DuckDb\SnapshotManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class DuckDbExporter
{
    public function __construct(
        protected DuckDbSourceAdapter $adapter,
        protected SnapshotManager $snapshotManager
    ) {}

    /**
     * Export snapshot rows to an OpenXML (.xlsx) Excel spreadsheet using DuckDB's excel extension.
     */
    public function exportExcel(string $snapshotId, string $targetPath, ?int $limit = null, int $offset = 0): string
    {
        $this->ensureSnapshotExists($snapshotId);
        $this->prepareDirectoryForFile($targetPath);

        $snapshotPath = $this->snapshotManager->getSnapshotPath($snapshotId);
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }

        $slice = $limit !== null ? sprintf(' LIMIT %d OFFSET %d', $limit, $offset) : '';
        $sql = sprintf(
            "LOAD excel;\nCOPY (SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id%s) TO '%s' WITH (FORMAT xlsx, HEADER true, SHEET 'SmartReport');",
            $slice,
            $targetPath
        );

        $this->adapter->runQuery($sql, $snapshotPath, false, 60);

        return $targetPath;
    }

    /**
     * Export snapshot rows to CSV with UTF-8 BOM prefix (\xEF\xBB\xBF) for Vietnamese Excel rendering.
     */
    public function exportCsv(string $snapshotId, string $targetPath, ?int $limit = null, int $offset = 0): string
    {
        $this->ensureSnapshotExists($snapshotId);
        $this->prepareDirectoryForFile($targetPath);

        $snapshotPath = $this->snapshotManager->getSnapshotPath($snapshotId);
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }

        $slice = $limit !== null ? sprintf(' LIMIT %d OFFSET %d', $limit, $offset) : '';
        $sql = sprintf(
            "COPY (SELECT * EXCLUDE (__row_id) FROM report_result ORDER BY __row_id%s) TO '%s' WITH (FORMAT csv, HEADER true, DELIMITER ',');",
            $slice,
            $targetPath
        );

        $this->adapter->runQuery($sql, $snapshotPath, false, 60);

        // Prepend UTF-8 BOM so Excel opens Vietnamese characters correctly
        $content = (string) file_get_contents($targetPath);
        if (! str_starts_with($content, "\xEF\xBB\xBF")) {
            file_put_contents($targetPath, "\xEF\xBB\xBF".$content);
        }

        return $targetPath;
    }

    /**
     * Export snapshot to a .zip archive, optionally splitting rows into multiple part files.
     */
    public function exportZip(string $snapshotId, string $format, int $splitRows, string $targetPath): string
    {
        $format = strtolower(trim($format));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            throw new InvalidArgumentException("Unsupported export format [{$format}]. Allowed formats: xlsx, csv.");
        }

        $this->ensureSnapshotExists($snapshotId);
        $this->prepareDirectoryForFile($targetPath);

        $meta = $this->snapshotManager->getMetadata($snapshotId);
        $totalRows = (int) ($meta['total_rows'] ?? 0);

        $tempDir = dirname($targetPath).DIRECTORY_SEPARATOR.'tmp_'.uniqid('', true);
        File::ensureDirectoryExists($tempDir);

        $baseName = pathinfo($targetPath, PATHINFO_FILENAME);
        $partFiles = [];

        try {
            $numParts = ($splitRows > 0 && $totalRows > $splitRows) ? (int) ceil($totalRows / $splitRows) : 1;
            $limit = $numParts > 1 ? $splitRows : null;

            for ($i = 0; $i < $numParts; $i++) {
                $partNum = $i + 1;
                $offset = $i * $splitRows;
                $partPath = $tempDir.DIRECTORY_SEPARATOR."{$baseName}_part{$partNum}.{$format}";

                if ($format === 'xlsx') {
                    $this->exportExcel($snapshotId, $partPath, $limit, $offset);
                } else {
                    $this->exportCsv($snapshotId, $partPath, $limit, $offset);
                }

                $partFiles[] = $partPath;
            }

            // Create ZIP archive
            if (file_exists($targetPath)) {
                @unlink($targetPath);
            }

            $zip = new ZipArchive;
            $result = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result !== true) {
                throw new RuntimeException("Failed to create zip archive at [{$targetPath}], error code: {$result}");
            }

            foreach ($partFiles as $partFile) {
                $zip->addFile($partFile, basename($partFile));
            }

            $zip->close();

            return $targetPath;
        } finally {
            // Clean up temporary part files and directory
            foreach ($partFiles as $partFile) {
                if (file_exists($partFile)) {
                    @unlink($partFile);
                }
            }

            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * Ensure the snapshot file exists.
     */
    protected function ensureSnapshotExists(string $snapshotId): void
    {
        if (! $this->snapshotManager->snapshotExists($snapshotId)) {
            $path = $this->snapshotManager->getSnapshotPath($snapshotId);
            throw new RuntimeException("Snapshot file [{$path}] does not exist.");
        }
    }

    /**
     * Prepare directory for the target file.
     */
    protected function prepareDirectoryForFile(string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }
    }
}
