<?php

namespace App\Services\Export;

use App\Models\SitTemplate;
use App\Models\SitTestRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SitDocumentExporter
{
    private const EMU_PER_PIXEL = 9525;

    /**
     * Export a SIT Test Run to an OpenXML Excel (.xlsx) file with embedded images.
     */
    public function exportExcel(SitTemplate $template, SitTestRun $run, ?string $outputPath = null): string
    {
        $outputPath ??= $this->tempFilePath("sit_{$template->template_code}_{$run->run_code}", 'xlsx');
        $this->ensureParentDirectory($outputPath);

        $fieldDefs = $run->resolvedStepFields();
        $steps = is_array($run->steps_data) ? array_values($run->steps_data) : [];
        $metrics = $run->computeMetrics();

        // Collect all valid images across steps
        $drawings = [];
        $mediaFiles = [];
        $imageCounter = 0;

        // Columns: A=Step#, B=Title, C=Status, D..(D+count-1)=step_fields, next=Extra Fields, last=Evidence Images
        $headers = ['Bước #', 'Tiêu đề bước', 'Trạng thái'];
        foreach ($fieldDefs as $fd) {
            $headers[] = $fd['label'];
        }
        $headers[] = 'Thông tin bổ sung';
        $headers[] = 'Bằng chứng hình ảnh (Evidence)';
        $evidenceColIndex0 = count($headers) - 1;

        $rowsXml = '';
        // Row 1: Title
        $rowsXml .= $this->xlsxRow(1, [
            ['col' => 0, 'val' => 'BIÊN BẢN KIỂM THỬ TÍCH HỢP HỆ THỐNG (SIT)', 'style' => 1],
        ], 28);
        // Row 2: Template & Run info
        $rowsXml .= $this->xlsxRow(2, [
            ['col' => 0, 'val' => 'Kịch bản:', 'style' => 2],
            ['col' => 1, 'val' => "{$template->template_name} ({$template->template_code})", 'style' => 0],
            ['col' => 2, 'val' => 'Mã đợt test:', 'style' => 2],
            ['col' => 3, 'val' => "{$run->run_title} [{$run->run_code}]", 'style' => 0],
        ], 20);
        // Row 3: Tester, Environment, Metrics
        $testedAtStr = $run->tested_at ? $run->tested_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $summaryText = "Tổng: {$metrics['total']} | PASS: {$metrics['pass']} | FAIL: {$metrics['fail']} | BLOCKED: {$metrics['blocked']} | PENDING: {$metrics['pending']}";
        $rowsXml .= $this->xlsxRow(3, [
            ['col' => 0, 'val' => 'Môi trường / Tester:', 'style' => 2],
            ['col' => 1, 'val' => "{$run->environment} — ".($run->tester_name ?: 'Admin')." ({$testedAtStr})", 'style' => 0],
            ['col' => 2, 'val' => 'Kết quả tổng hợp:', 'style' => 2],
            ['col' => 3, 'val' => "{$run->status} ({$summaryText})", 'style' => 0],
        ], 20);

        // Row 5: Table Header
        $headerCells = [];
        foreach ($headers as $cIdx => $hText) {
            $headerCells[] = ['col' => $cIdx, 'val' => $hText, 'style' => 2];
        }
        $rowsXml .= $this->xlsxRow(5, $headerCells, 26);

        // Step rows start at Excel row 6 (0-based row 5)
        $currentExcelRow = 6;
        foreach ($steps as $idx => $step) {
            $stepNo = (string) ($step['step_no'] ?? ($idx + 1));
            $title = (string) ($step['title'] ?? 'Bước '.($idx + 1));
            $status = strtoupper((string) ($step['status'] ?? 'PENDING'));
            $statusStyle = match ($status) {
                'PASS' => 4,
                'FAIL' => 5,
                'BLOCKED' => 6,
                default => 3,
            };

            $cells = [
                ['col' => 0, 'val' => $stepNo, 'style' => 3],
                ['col' => 1, 'val' => $title, 'style' => 3],
                ['col' => 2, 'val' => $status, 'style' => $statusStyle],
            ];

            $stepFieldsData = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            $colCursor = 3;
            foreach ($fieldDefs as $fd) {
                $val = (string) ($stepFieldsData[$fd['key']] ?? '');
                $cells[] = ['col' => $colCursor++, 'val' => $val, 'style' => 3];
            }

            // Extra fields text
            $extraLines = [];
            foreach ((array) ($step['extra_fields'] ?? []) as $ef) {
                if (is_array($ef) && trim((string) ($ef['label'] ?? '')) !== '') {
                    $extraLines[] = trim((string) $ef['label']).': '.trim((string) ($ef['value'] ?? ''));
                }
            }
            $cells[] = ['col' => $colCursor++, 'val' => implode("\n", $extraLines), 'style' => 3];

            // Process step images
            $captions = [];
            $yOffsetPx = 8;
            $rowZeroBased = $currentExcelRow - 1;

            foreach ((array) ($step['images'] ?? []) as $imgIdx => $imgItem) {
                if (! is_array($imgItem) || empty($imgItem['path'])) {
                    continue;
                }
                $absPath = $this->resolveImagePath((string) $imgItem['path']);
                if ($absPath === null) {
                    continue;
                }

                $imageCounter++;
                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION) ?: 'png');
                if ($ext === 'jpg') {
                    $ext = 'jpeg';
                }
                $mediaName = "image{$imageCounter}.{$ext}";
                $rId = "rIdImg{$imageCounter}";

                [$wPx, $hPx] = $this->scaledDimensions($absPath, 320, 220);

                $mediaFiles[] = [
                    'rId' => $rId,
                    'mediaName' => $mediaName,
                    'absPath' => $absPath,
                    'ext' => $ext,
                ];

                $caption = trim((string) ($imgItem['caption'] ?? ''));
                $captions[] = $caption !== '' ? ('[Ảnh '.($imgIdx + 1)."] {$caption}") : ('[Ảnh '.($imgIdx + 1).']');

                $drawings[] = [
                    'rId' => $rId,
                    'id' => $imageCounter,
                    'name' => "Step_{$stepNo}_Img_{$imageCounter}",
                    'descr' => $caption ?: "Evidence Step {$stepNo}",
                    'col' => $evidenceColIndex0,
                    'row' => $rowZeroBased,
                    'colOff' => 8 * self::EMU_PER_PIXEL,
                    'rowOff' => $yOffsetPx * self::EMU_PER_PIXEL,
                    'cx' => $wPx * self::EMU_PER_PIXEL,
                    'cy' => $hPx * self::EMU_PER_PIXEL,
                ];

                $yOffsetPx += $hPx + 12;
            }

            $cells[] = ['col' => $evidenceColIndex0, 'val' => implode("\n", $captions), 'style' => 3];

            // Calculate row height in points (1 px ~ 0.78 pt)
            $rowHeightPt = $yOffsetPx > 8 ? max(55, (int) ceil($yOffsetPx * 0.80) + 18) : 42;
            $rowsXml .= $this->xlsxRow($currentExcelRow, $cells, $rowHeightPt);
            $currentExcelRow++;
        }

        $hasDrawings = ! empty($drawings);
        $zip = new ZipArchive;
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create Excel ZIP archive at {$outputPath}");
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml($hasDrawings));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml('xl/workbook.xml'));
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheetXml($rowsXml, count($headers), $hasDrawings));

        if ($hasDrawings) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->xlsxSheetRelsXml());
            $zip->addFromString('xl/drawings/drawing1.xml', $this->xlsxDrawingXml($drawings));
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->xlsxDrawingRelsXml($mediaFiles));
            foreach ($mediaFiles as $mf) {
                $zip->addFile($mf['absPath'], 'xl/media/'.$mf['mediaName']);
            }
        }

        $zip->close();

        return $outputPath;
    }

    /**
     * Export a SIT Test Run to an OpenXML Word (.docx) file with embedded images.
     */
    public function exportWord(SitTemplate $template, SitTestRun $run, ?string $outputPath = null): string
    {
        $outputPath ??= $this->tempFilePath("sit_{$template->template_code}_{$run->run_code}", 'docx');
        $this->ensureParentDirectory($outputPath);

        $fieldDefs = $run->resolvedStepFields();
        $steps = is_array($run->steps_data) ? array_values($run->steps_data) : [];
        $metrics = $run->computeMetrics();

        $mediaFiles = [];
        $imageCounter = 0;

        $testedAtStr = $run->tested_at ? $run->tested_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $summaryText = "Tổng số bước: {$metrics['total']} | PASS: {$metrics['pass']} | FAIL: {$metrics['fail']} | BLOCKED: {$metrics['blocked']} | PENDING: {$metrics['pending']}";

        $bodyXml = '';
        $bodyXml .= $this->docxParagraph('BIÊN BẢN KIỂM THỬ TÍCH HỢP HỆ THỐNG (SIT)', true, 32, '1E3A8A');
        $bodyXml .= $this->docxParagraph("{$template->template_name} ({$template->template_code})", true, 24, '334155');

        // Summary metadata table
        $bodyXml .= $this->docxKeyValueTable([
            ['Mã đợt chạy (Run Code)', "{$run->run_code} — {$run->run_title}"],
            ['Phân hệ / Phiên bản', ($template->module_name ?: '—')." (v{$run->template_version})"],
            ['Môi trường & Người test', "{$run->environment} | ".($run->tester_name ?: 'Admin')." | {$testedAtStr}"],
            ['Trạng thái tổng thể', "{$run->status} ({$summaryText})"],
            ['Ghi chú tổng kết', (string) ($run->summary_notes ?: '—')],
        ]);

        $bodyXml .= $this->docxParagraph('', false, 20);

        foreach ($steps as $idx => $step) {
            $stepNo = (string) ($step['step_no'] ?? ($idx + 1));
            $title = (string) ($step['title'] ?? 'Bước '.($idx + 1));
            $status = strtoupper((string) ($step['status'] ?? 'PENDING'));
            $statusColor = match ($status) {
                'PASS' => '15803D',
                'FAIL' => 'B91C1C',
                'BLOCKED' => 'B45309',
                default => '475569',
            };

            $bodyXml .= $this->docxParagraph("Bước {$stepNo}: {$title} [{$status}]", true, 24, $statusColor);

            $kvRows = [];
            $stepFieldsData = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            foreach ($fieldDefs as $fd) {
                $kvRows[] = [$fd['label'], (string) ($stepFieldsData[$fd['key']] ?? '—')];
            }

            foreach ((array) ($step['extra_fields'] ?? []) as $ef) {
                if (is_array($ef) && trim((string) ($ef['label'] ?? '')) !== '') {
                    $kvRows[] = [trim((string) $ef['label']), (string) ($ef['value'] ?? '—')];
                }
            }

            $bodyXml .= $this->docxKeyValueTable($kvRows);

            foreach ((array) ($step['images'] ?? []) as $imgIdx => $imgItem) {
                if (! is_array($imgItem) || empty($imgItem['path'])) {
                    continue;
                }
                $absPath = $this->resolveImagePath((string) $imgItem['path']);
                if ($absPath === null) {
                    continue;
                }

                $imageCounter++;
                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION) ?: 'png');
                if ($ext === 'jpg') {
                    $ext = 'jpeg';
                }
                $mediaName = "image{$imageCounter}.{$ext}";
                $rId = "rIdImg{$imageCounter}";

                [$wPx, $hPx] = $this->scaledDimensions($absPath, 540, 380);
                $mediaFiles[] = [
                    'rId' => $rId,
                    'mediaName' => $mediaName,
                    'absPath' => $absPath,
                    'ext' => $ext,
                ];

                $caption = trim((string) ($imgItem['caption'] ?? ''));
                $bodyXml .= $this->docxImageParagraph(
                    $rId,
                    $imageCounter,
                    $wPx * self::EMU_PER_PIXEL,
                    $hPx * self::EMU_PER_PIXEL,
                    $caption ?: "Hình {$stepNo}.".($imgIdx + 1)
                );
                $captionLabel = 'Hình '.$stepNo.'.'.($imgIdx + 1).($caption !== '' ? ": {$caption}" : '');
                $bodyXml .= $this->docxParagraph($captionLabel, false, 18, '64748B', true);
            }

            $bodyXml .= $this->docxParagraph('', false, 16);
        }

        $zip = new ZipArchive;
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create Word ZIP archive at {$outputPath}");
        }

        $zip->addFromString('[Content_Types].xml', $this->docxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml('word/document.xml'));
        $zip->addFromString('word/styles.xml', $this->docxStylesXml());
        $zip->addFromString('word/document.xml', $this->docxDocumentXml($bodyXml));
        $zip->addFromString('word/_rels/document.xml.rels', $this->docxDocumentRelsXml($mediaFiles));

        foreach ($mediaFiles as $mf) {
            $zip->addFile($mf['absPath'], 'word/media/'.$mf['mediaName']);
        }

        $zip->close();

        return $outputPath;
    }

    /**
     * Export a SIT Test Run to a standalone printable HTML file with Base64-embedded images.
     */
    public function exportHtml(SitTemplate $template, SitTestRun $run, ?string $outputPath = null): string
    {
        $outputPath ??= $this->tempFilePath("sit_{$template->template_code}_{$run->run_code}", 'html');
        $this->ensureParentDirectory($outputPath);

        $html = $this->renderHtmlContent($template, $run);
        File::put($outputPath, $html);

        return $outputPath;
    }

    /**
     * Render standalone HTML string with Base64 embedded images.
     */
    public function renderHtmlContent(SitTemplate $template, SitTestRun $run): string
    {
        $fieldDefs = $run->resolvedStepFields();
        $steps = is_array($run->steps_data) ? array_values($run->steps_data) : [];
        $metrics = $run->computeMetrics();
        $testedAtStr = $run->tested_at ? $run->tested_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i');

        $e = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $stepsHtml = '';
        foreach ($steps as $idx => $step) {
            $stepNo = $e((string) ($step['step_no'] ?? ($idx + 1)));
            $title = $e((string) ($step['title'] ?? 'Bước '.($idx + 1)));
            $status = strtoupper((string) ($step['status'] ?? 'PENDING'));
            $badgeClass = match ($status) {
                'PASS' => 'badge-pass',
                'FAIL' => 'badge-fail',
                'BLOCKED' => 'badge-blocked',
                default => 'badge-pending',
            };

            $rowsHtml = '';
            $fields = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            foreach ($fieldDefs as $fd) {
                $val = nl2br($e((string) ($fields[$fd['key']] ?? '—')));
                $rowsHtml .= '<tr><th>'.$e($fd['label'])."</th><td>{$val}</td></tr>";
            }
            foreach ((array) ($step['extra_fields'] ?? []) as $ef) {
                if (is_array($ef) && trim((string) ($ef['label'] ?? '')) !== '') {
                    $rowsHtml .= '<tr><th>'.$e((string) $ef['label']).'</th><td>'.nl2br($e((string) ($ef['value'] ?? '—'))).'</td></tr>';
                }
            }

            $imgsHtml = '';
            foreach ((array) ($step['images'] ?? []) as $imgIdx => $imgItem) {
                if (! is_array($imgItem) || empty($imgItem['path'])) {
                    continue;
                }
                $absPath = $this->resolveImagePath((string) $imgItem['path']);
                if ($absPath === null) {
                    continue;
                }
                $mime = @mime_content_type($absPath) ?: 'image/png';
                $b64 = base64_encode((string) file_get_contents($absPath));
                $cap = $e((string) ($imgItem['caption'] ?? 'Hình '.$stepNo.'.'.($imgIdx + 1)));
                $imgsHtml .= "<figure class=\"evidence-fig\"><img src=\"data:{$mime};base64,{$b64}\" alt=\"{$cap}\"/><figcaption>{$cap}</figcaption></figure>";
            }

            $evidenceBlock = $imgsHtml !== '' ? "<div class=\"evidence-grid\">{$imgsHtml}</div>" : '';

            $stepsHtml .= <<<HTML
            <section class="step-card">
                <div class="step-header">
                    <h3>Bước {$stepNo}: {$title}</h3>
                    <span class="badge {$badgeClass}">{$e($status)}</span>
                </div>
                <table class="meta-table">{$rowsHtml}</table>
                {$evidenceBlock}
            </section>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <title>Biên bản SIT - {$e($template->template_name)} ({$e($run->run_code)})</title>
            <style>
                body { font-family: system-ui, -apple-system, sans-serif; color: #0f172a; max-width: 1024px; margin: 24px auto; padding: 0 16px; line-height: 1.5; }
                h1 { color: #1e3a8a; margin-bottom: 4px; font-size: 22px; }
                .subtitle { color: #475569; margin-top: 0; font-size: 14px; }
                .meta-table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13px; }
                .meta-table th, .meta-table td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; vertical-align: top; }
                .meta-table th { width: 220px; background: #f8fafc; color: #334155; font-weight: 600; }
                .step-card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 18px; page-break-inside: avoid; }
                .step-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
                .step-header h3 { margin: 0; font-size: 16px; }
                .badge { padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
                .badge-pass { background: #dcfce7; color: #15803d; }
                .badge-fail { background: #fee2e2; color: #b91c1c; }
                .badge-blocked { background: #fef3c7; color: #b45309; }
                .badge-pending { background: #f1f5f9; color: #475569; }
                .evidence-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; margin-top: 12px; }
                .evidence-fig { margin: 0; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; background: #f8fafc; text-align: center; }
                .evidence-fig img { max-width: 100%; height: auto; border-radius: 4px; }
                .evidence-fig figcaption { font-size: 12px; color: #475569; margin-top: 6px; }
            </style>
        </head>
        <body>
            <h1>BIÊN BẢN KIỂM THỬ TÍCH HỢP HỆ THỐNG (SIT)</h1>
            <p class="subtitle">{$e($template->template_name)} ({$e($template->template_code)}) — Phiên bản {$e($run->template_version)}</p>
            <table class="meta-table">
                <tr><th>Đợt kiểm thử</th><td>{$e($run->run_title)} ({$e($run->run_code)})</td></tr>
                <tr><th>Môi trường & Người thực hiện</th><td>{$e($run->environment)} | {$e($run->tester_name ?: 'Admin')} | {$e($testedAtStr)}</td></tr>
                <tr><th>Kết quả tổng hợp</th><td><strong>{$e($run->status)}</strong> — Tổng: {$metrics['total']} | PASS: {$metrics['pass']} | FAIL: {$metrics['fail']} | BLOCKED: {$metrics['blocked']} | PENDING: {$metrics['pending']}</td></tr>
                <tr><th>Ghi chú</th><td>{$e($run->summary_notes ?: '—')}</td></tr>
            </table>
            {$stepsHtml}
        </body>
        </html>
        HTML;
    }

    /**
     * Resolve relative public storage path to absolute file path safely.
     */
    public function resolveImagePath(string $relativePath): ?string
    {
        if (! str_starts_with($relativePath, 'sit-evidence/') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || in_array('..', explode('/', $relativePath), true)) {
            return null;
        }

        $root = realpath(Storage::disk('local')->path('sit-evidence'));
        $path = realpath(Storage::disk('local')->path($relativePath));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
        $info = @getimagesize($path);

        return $mime !== null && is_array($info) && ($info['mime'] ?? '') === $mime ? $path : null;
    }

    /**
     * Calculate proportional image width & height in pixels within max bounds.
     *
     * @return array{0: int, 1: int}
     */
    private function scaledDimensions(string $absPath, int $maxWidth, int $maxHeight): array
    {
        $info = @getimagesize($absPath);
        $w = is_array($info) && ($info[0] ?? 0) > 0 ? (int) $info[0] : 320;
        $h = is_array($info) && ($info[1] ?? 0) > 0 ? (int) $info[1] : 200;

        $ratio = min($maxWidth / $w, $maxHeight / $h, 1.0);

        return [
            max(40, (int) round($w * $ratio)),
            max(30, (int) round($h * $ratio)),
        ];
    }

    private function tempFilePath(string $prefix, string $ext): string
    {
        $dir = storage_path('app/exports');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir.'/'.Str::slug($prefix, '_').'_'.Str::lower(Str::random(6)).'.'.$ext;
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
    }

    private function xmlEscape(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function excelColName(int $index0): string
    {
        $name = '';
        $n = $index0;
        while ($n >= 0) {
            $name = chr(($n % 26) + 65).$name;
            $n = intdiv($n, 26) - 1;
        }

        return $name;
    }

    /**
     * @param  list<array{col: int, val: string, style: int}>  $cells
     */
    private function xlsxRow(int $rowNumber, array $cells, int $heightPt = 22): string
    {
        $cellsXml = '';
        foreach ($cells as $c) {
            $ref = $this->excelColName($c['col']).$rowNumber;
            $val = $this->xmlEscape($c['val']);
            $style = $c['style'];
            $cellsXml .= "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$val}</t></is></c>";
        }

        return "<row r=\"{$rowNumber}\" ht=\"{$heightPt}\" customHeight=\"1\">{$cellsXml}</row>";
    }

    private function xlsxContentTypesXml(bool $hasDrawings): string
    {
        $drawingOverride = $hasDrawings
            ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="png" ContentType="image/png"/>
    <Default Extension="jpeg" ContentType="image/jpeg"/>
    <Default Extension="jpg" ContentType="image/jpeg"/>
    <Default Extension="gif" ContentType="image/gif"/>
    <Default Extension="webp" ContentType="image/webp"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    {$drawingOverride}
</Types>
XML;
    }

    private function rootRelsXml(string $target): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="{$target}"/>
</Relationships>
XML;
    }

    private function xlsxWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="SIT Report" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML;
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function xlsxStylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="4">
        <font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
        <font><b/><sz val="15"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
    </fonts>
    <fills count="7">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFCBD5E1"/></left>
            <right style="thin"><color rgb="FFCBD5E1"/></right>
            <top style="thin"><color rgb="FFCBD5E1"/></top>
            <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="7">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>
        <xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>
        <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>
        <xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>
    </cellXfs>
</styleSheet>
XML;
    }

    private function xlsxSheetXml(string $rowsXml, int $colCount, bool $hasDrawings): string
    {
        $colsXml = '<col min="1" max="1" width="10" customWidth="1"/>';
        $colsXml .= '<col min="2" max="2" width="30" customWidth="1"/>';
        $colsXml .= '<col min="3" max="3" width="14" customWidth="1"/>';
        if ($colCount > 4) {
            $colsXml .= '<col min="4" max="'.($colCount - 1).'" width="32" customWidth="1"/>';
        }
        $colsXml .= "<col min=\"{$colCount}\" max=\"{$colCount}\" width=\"52\" customWidth=\"1\"/>";

        $drawingTag = $hasDrawings ? '<drawing r:id="rIdDrawing1"/>' : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <cols>{$colsXml}</cols>
    <sheetData>{$rowsXml}</sheetData>
    {$drawingTag}
</worksheet>
XML;
    }

    private function xlsxSheetRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rIdDrawing1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>
</Relationships>
XML;
    }

    /**
     * @param  list<array{rId: string, id: int, name: string, descr: string, col: int, row: int, colOff: int, rowOff: int, cx: int, cy: int}>  $drawings
     */
    private function xlsxDrawingXml(array $drawings): string
    {
        $anchorsXml = '';
        foreach ($drawings as $d) {
            $name = $this->xmlEscape($d['name']);
            $descr = $this->xmlEscape($d['descr']);
            $anchorsXml .= <<<XML
    <xdr:oneCellAnchor>
        <xdr:from>
            <xdr:col>{$d['col']}</xdr:col>
            <xdr:colOff>{$d['colOff']}</xdr:colOff>
            <xdr:row>{$d['row']}</xdr:row>
            <xdr:rowOff>{$d['rowOff']}</xdr:rowOff>
        </xdr:from>
        <xdr:ext cx="{$d['cx']}" cy="{$d['cy']}"/>
        <xdr:pic>
            <xdr:nvPicPr>
                <xdr:cNvPr id="{$d['id']}" name="{$name}" descr="{$descr}"/>
                <xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>
            </xdr:nvPicPr>
            <xdr:blipFill>
                <a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="{$d['rId']}"/>
                <a:stretch><a:fillRect/></a:stretch>
            </xdr:blipFill>
            <xdr:spPr>
                <a:xfrm><a:off x="0" y="0"/><a:ext cx="{$d['cx']}" cy="{$d['cy']}"/></a:xfrm>
                <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
            </xdr:spPr>
        </xdr:pic>
        <xdr:clientData/>
    </xdr:oneCellAnchor>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
{$anchorsXml}
</xdr:wsDr>
XML;
    }

    /**
     * @param  list<array{rId: string, mediaName: string}>  $mediaFiles
     */
    private function xlsxDrawingRelsXml(array $mediaFiles): string
    {
        $rels = '';
        foreach ($mediaFiles as $mf) {
            $rels .= "<Relationship Id=\"{$mf['rId']}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"../media/{$mf['mediaName']}\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    {$rels}
</Relationships>
XML;
    }

    private function docxContentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="png" ContentType="image/png"/>
    <Default Extension="jpeg" ContentType="image/jpeg"/>
    <Default Extension="jpg" ContentType="image/jpeg"/>
    <Default Extension="gif" ContentType="image/gif"/>
    <Default Extension="webp" ContentType="image/webp"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>
XML;
    }

    private function docxStylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:docDefaults>
        <w:rPrDefault>
            <w:rPr>
                <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>
                <w:sz w:val="22"/>
            </w:rPr>
        </w:rPrDefault>
    </w:docDefaults>
</w:styles>
XML;
    }

    private function docxDocumentXml(string $bodyXml): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
            xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
    <w:body>
        {$bodyXml}
        <w:sectPr>
            <w:pgSz w:w="11906" w:h="16838"/>
            <w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/>
        </w:sectPr>
    </w:body>
</w:document>
XML;
    }

    /**
     * @param  list<array{rId: string, mediaName: string}>  $mediaFiles
     */
    private function docxDocumentRelsXml(array $mediaFiles): string
    {
        $rels = '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($mediaFiles as $mf) {
            $rels .= "<Relationship Id=\"{$mf['rId']}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"media/{$mf['mediaName']}\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    {$rels}
</Relationships>
XML;
    }

    private function docxParagraph(string $text, bool $bold = false, int $halfPtSize = 22, string $hexColor = '0F172A', bool $italic = false): string
    {
        $bTag = $bold ? '<w:b/>' : '';
        $iTag = $italic ? '<w:i/>' : '';
        $lines = preg_split('/\r?\n/', $text) ?: [''];
        $runs = '';
        foreach ($lines as $idx => $line) {
            $escaped = $this->xmlEscape($line);
            $br = $idx > 0 ? '<w:br/>' : '';
            $runs .= "<w:r><w:rPr>{$bTag}{$iTag}<w:color w:val=\"{$hexColor}\"/><w:sz w:val=\"{$halfPtSize}\"/></w:rPr>{$br}<w:t xml:space=\"preserve\">{$escaped}</w:t></w:r>";
        }

        return "<w:p><w:pPr><w:spacing w:before=\"60\" w:after=\"80\"/></w:pPr>{$runs}</w:p>";
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function docxKeyValueTable(array $rows): string
    {
        $rowsXml = '';
        foreach ($rows as [$k, $v]) {
            $kPar = $this->docxParagraph($k, true, 20, '1E293B');
            $vPar = $this->docxParagraph($v !== '' ? $v : '—', false, 20, '0F172A');
            $rowsXml .= <<<XML
            <w:tr>
                <w:tc>
                    <w:tcPr><w:tcW w:w="2800" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/></w:tcPr>
                    {$kPar}
                </w:tc>
                <w:tc>
                    <w:tcPr><w:tcW w:w="6800" w:type="dxa"/></w:tcPr>
                    {$vPar}
                </w:tc>
            </w:tr>
XML;
        }

        return <<<XML
        <w:tbl>
            <w:tblPr>
                <w:tblW w:w="9600" w:type="dxa"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                    <w:left w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                    <w:bottom w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                    <w:right w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                    <w:insideH w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                    <w:insideV w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>
                </w:tblBorders>
            </w:tblPr>
            {$rowsXml}
        </w:tbl>
XML;
    }

    private function docxImageParagraph(string $rId, int $id, int $cx, int $cy, string $title): string
    {
        $escapedTitle = $this->xmlEscape($title);

        return <<<XML
        <w:p>
            <w:pPr><w:spacing w:before="120" w:after="40"/></w:pPr>
            <w:r>
                <w:drawing>
                    <wp:inline distT="0" distB="0" distL="0" distR="0">
                        <wp:extent cx="{$cx}" cy="{$cy}"/>
                        <wp:docPr id="{$id}" name="Picture {$id}" descr="{$escapedTitle}"/>
                        <wp:cNvGraphicFramePr>
                            <a:graphicFrameLocks noChangeAspect="1"/>
                        </wp:cNvGraphicFramePr>
                        <a:graphic>
                            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:pic>
                                    <pic:nvPicPr>
                                        <pic:cNvPr id="{$id}" name="Picture {$id}" descr="{$escapedTitle}"/>
                                        <pic:cNvPicPr/>
                                    </pic:nvPicPr>
                                    <pic:blipFill>
                                        <a:blip r:embed="{$rId}"/>
                                        <a:stretch><a:fillRect/></a:stretch>
                                    </pic:blipFill>
                                    <pic:spPr>
                                        <a:xfrm><a:off x="0" y="0"/><a:ext cx="{$cx}" cy="{$cy}"/></a:xfrm>
                                        <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                                    </pic:spPr>
                                </pic:pic>
                            </a:graphicData>
                        </a:graphic>
                    </wp:inline>
                </w:drawing>
            </w:r>
        </w:p>
XML;
    }
}
