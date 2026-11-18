<?php

namespace App\Filament\Pages;

use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\SitTestRun;
use App\Models\User;
use App\Services\Export\SitDocumentExporter;
use App\Services\Navigation\DynamicSidebarBuilder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SitRunnerPage extends Page
{
    protected static ?string $slug = 'sit-tests';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.sit-runner-page';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public string $templateCode = '';

    public ?int $selectedRunId = null;

    public string $runTitle = '';

    public string $runEnvironment = 'SIT';

    public string $testerName = '';

    public string $summaryNotes = '';

    /**
     * @var list<array<string, mixed>>
     */
    public array $steps = [];

    protected ?SitTemplate $cachedTemplate = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/sit-tests/{templateCode}';
    }

    public function mount(string $templateCode): void
    {
        $template = $this->resolveTemplate($templateCode);
        $this->authorizeTemplateAccess($template);

        $this->templateCode = $template->template_code;

        $latestRun = $template->testRuns()->latest('id')->first();
        if (! $latestRun) {
            $latestRun = $this->createRunRecord($template, 'Đợt kiểm thử SIT #1', 'SIT');
        }

        $this->loadRunState($latestRun);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getTemplate()->template_name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTemplate()->template_name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $t = $this->getTemplate();

        return "Mã kịch bản: {$t->template_code} | Phân hệ: ".($t->module_name ?: 'Chung')." | Phiên bản: v{$t->version}";
    }

    public function getTemplate(): SitTemplate
    {
        if ($this->cachedTemplate instanceof SitTemplate && $this->cachedTemplate->template_code === $this->templateCode) {
            return $this->cachedTemplate;
        }

        return $this->cachedTemplate = $this->resolveTemplate($this->templateCode);
    }

    /**
     * Select an existing test run by ID.
     */
    public function selectRun(int|string $runId): void
    {
        $template = $this->getTemplate();
        $run = $template->testRuns()->whereKey((int) $runId)->first();
        if (! $run) {
            return;
        }

        $this->loadRunState($run);
    }

    /**
     * Handle Livewire wire:model change on selectedRunId.
     */
    public function updatedSelectedRunId(mixed $value): void
    {
        if ($value) {
            $this->selectRun((int) $value);
        }
    }

    /**
     * Create a brand new SIT Test Run from template default steps.
     */
    public function createNewRun(?string $customTitle = null, string $environment = 'SIT'): void
    {
        $template = $this->getTemplate();
        $count = $template->testRuns()->count() + 1;
        $title = trim((string) $customTitle) !== '' ? trim((string) $customTitle) : "Đợt kiểm thử SIT #{$count}";

        $run = $this->createRunRecord($template, $title, $environment);
        $this->loadRunState($run);

        Notification::make()
            ->title('Đã tạo đợt kiểm thử mới')
            ->body("{$run->run_title} ({$run->run_code})")
            ->success()
            ->send();
    }

    /**
     * Clone current SIT Test Run for re-testing (preserves all steps, fields, and evidence images).
     */
    public function cloneCurrentRun(): void
    {
        $this->saveRun(false);

        $template = $this->getTemplate();
        $currentRun = $this->getCurrentRun();
        $count = $template->testRuns()->count() + 1;

        $cloned = SitTestRun::create([
            'sit_template_id' => $template->id,
            'run_code' => $this->generateUniqueRunCode($template->template_code, $count),
            'run_title' => "Retest #{$count} (Từ {$currentRun->run_code})",
            'environment' => $currentRun->environment,
            'tester_name' => User::current()?->name ?: $currentRun->tester_name,
            'tested_at' => now(),
            'status' => SitTestRun::STATUS_IN_PROGRESS,
            'summary_notes' => $currentRun->summary_notes,
            'steps_data' => $currentRun->steps_data,
            'created_by' => User::current()?->id,
        ]);
        $cloned->syncOverallStatus();
        $cloned->save();

        $this->loadRunState($cloned);

        Notification::make()
            ->title('Đã nhân bản đợt kiểm thử để Retest')
            ->body("Đã sao chép toàn bộ bước và ảnh bằng chứng sang {$cloned->run_code}")
            ->success()
            ->send();
    }

    /**
     * Persist current run metadata and steps to SQLite.
     */
    public function saveRun(bool $notify = true): void
    {
        $run = $this->getCurrentRun();

        $normalizedSteps = [];
        foreach (array_values($this->steps) as $idx => $step) {
            $normalizedSteps[] = [
                'uid' => (string) ($step['uid'] ?? 'step_'.Str::lower(Str::random(8))),
                'step_no' => $idx + 1,
                'title' => trim((string) ($step['title'] ?? '')) !== '' ? trim((string) $step['title']) : 'Bước '.($idx + 1),
                'status' => in_array($step['status'] ?? '', [SitTestRun::STEP_PASS, SitTestRun::STEP_FAIL, SitTestRun::STEP_BLOCKED, SitTestRun::STEP_PENDING], true)
                    ? $step['status']
                    : SitTestRun::STEP_PENDING,
                'fields' => is_array($step['fields'] ?? null) ? $step['fields'] : [],
                'extra_fields' => is_array($step['extra_fields'] ?? null) ? array_values($step['extra_fields']) : [],
                'images' => is_array($step['images'] ?? null) ? array_values($step['images']) : [],
            ];
        }

        $this->steps = $normalizedSteps;

        $run->run_title = trim($this->runTitle) !== '' ? trim($this->runTitle) : $run->run_title;
        $run->environment = in_array($this->runEnvironment, ['SIT', 'UAT', 'STAGING', 'PROD'], true) ? $this->runEnvironment : 'SIT';
        $run->tester_name = trim($this->testerName) ?: (User::current()?->name ?: 'Admin');
        $run->summary_notes = $this->summaryNotes;
        $run->tested_at = now();
        $run->steps_data = $this->steps;
        $run->syncOverallStatus();
        $run->save();

        if ($notify) {
            Notification::make()
                ->title('Đã lưu kết quả SIT')
                ->body("Đợt test {$run->run_code} đã được cập nhật ({$run->status}).")
                ->success()
                ->send();
        }
    }

    /**
     * Add a new step to the current test run.
     */
    public function addStep(?string $title = null): void
    {
        $fieldDefs = $this->getTemplate()->resolvedStepFields();
        $emptyFields = [];
        foreach ($fieldDefs as $fd) {
            $emptyFields[$fd['key']] = '';
        }

        $nextNo = count($this->steps) + 1;
        $this->steps[] = [
            'uid' => 'step_'.Str::lower(Str::random(8)),
            'step_no' => $nextNo,
            'title' => $title ?: "Bước {$nextNo}",
            'status' => SitTestRun::STEP_PENDING,
            'fields' => $emptyFields,
            'extra_fields' => [],
            'images' => [],
        ];

        $this->saveRun(false);
    }

    /**
     * Remove a step by index.
     */
    public function removeStep(int $stepIndex): void
    {
        if (! isset($this->steps[$stepIndex])) {
            return;
        }

        unset($this->steps[$stepIndex]);
        $this->steps = array_values($this->steps);
        $this->saveRun(false);
    }

    /**
     * Move step up.
     */
    public function moveStepUp(int $stepIndex): void
    {
        if ($stepIndex <= 0 || ! isset($this->steps[$stepIndex])) {
            return;
        }

        [$this->steps[$stepIndex - 1], $this->steps[$stepIndex]] = [$this->steps[$stepIndex], $this->steps[$stepIndex - 1]];
        $this->saveRun(false);
    }

    /**
     * Move step down.
     */
    public function moveStepDown(int $stepIndex): void
    {
        if ($stepIndex < 0 || $stepIndex >= count($this->steps) - 1 || ! isset($this->steps[$stepIndex])) {
            return;
        }

        [$this->steps[$stepIndex + 1], $this->steps[$stepIndex]] = [$this->steps[$stepIndex], $this->steps[$stepIndex + 1]];
        $this->saveRun(false);
    }

    /**
     * Update step status quickly.
     */
    public function setStepStatus(int $stepIndex, string $status): void
    {
        if (! isset($this->steps[$stepIndex])) {
            return;
        }

        $upper = strtoupper($status);
        if (! in_array($upper, [SitTestRun::STEP_PASS, SitTestRun::STEP_FAIL, SitTestRun::STEP_BLOCKED, SitTestRun::STEP_PENDING], true)) {
            return;
        }

        $this->steps[$stepIndex]['status'] = $upper;
        $this->saveRun(false);
    }

    /**
     * Add an optional custom field (Key/Value) to a specific step.
     */
    public function addExtraField(int $stepIndex, string $label = '', string $value = ''): void
    {
        if (! isset($this->steps[$stepIndex])) {
            return;
        }

        $this->steps[$stepIndex]['extra_fields'] ??= [];
        $this->steps[$stepIndex]['extra_fields'][] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    /**
     * Remove an optional custom field from a step.
     */
    public function removeExtraField(int $stepIndex, int $extraIndex): void
    {
        if (! isset($this->steps[$stepIndex]['extra_fields'][$extraIndex])) {
            return;
        }

        unset($this->steps[$stepIndex]['extra_fields'][$extraIndex]);
        $this->steps[$stepIndex]['extra_fields'] = array_values($this->steps[$stepIndex]['extra_fields']);
        $this->saveRun(false);
    }

    /**
     * Handle Ctrl+V Clipboard Paste or FileReader image upload (data:image/...;base64,...).
     */
    public function pasteStepImage(int $stepIndex, string $dataUrl, string $caption = ''): void
    {
        if (! isset($this->steps[$stepIndex])) {
            return;
        }

        if (! preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/is', trim($dataUrl), $matches)) {
            Notification::make()
                ->title('Định dạng ảnh không hợp lệ')
                ->body('Chỉ hỗ trợ ảnh PNG, JPEG, GIF hoặc WEBP.')
                ->danger()
                ->send();

            return;
        }

        $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);

        if ($binary === false || strlen($binary) === 0 || strlen($binary) > 10 * 1024 * 1024) {
            Notification::make()
                ->title('Kích thước ảnh không hợp lệ')
                ->body('Dữ liệu ảnh trống hoặc vượt quá 10MB.')
                ->danger()
                ->send();

            return;
        }

        $runId = $this->selectedRunId ?: 0;
        $stepNo = $stepIndex + 1;
        $fileName = "step{$stepNo}_".now()->format('Ymd_His').'_'.Str::lower(Str::random(5)).".{$ext}";
        $relativePath = "sit-evidence/{$runId}/{$fileName}";

        Storage::disk('public')->put($relativePath, $binary);

        $this->steps[$stepIndex]['images'] ??= [];
        $this->steps[$stepIndex]['images'][] = [
            'path' => $relativePath,
            'caption' => trim($caption) !== '' ? trim($caption) : 'Ảnh bước '.$stepNo.' #'.(count($this->steps[$stepIndex]['images']) + 1),
        ];

        $this->saveRun(false);

        Notification::make()
            ->title('Đã đính kèm ảnh bằng chứng')
            ->body("Đã lưu ảnh vào Bước {$stepNo}.")
            ->success()
            ->send();
    }

    /**
     * Remove an image from a step.
     */
    public function removeStepImage(int $stepIndex, int $imageIndex): void
    {
        if (! isset($this->steps[$stepIndex]['images'][$imageIndex])) {
            return;
        }

        unset($this->steps[$stepIndex]['images'][$imageIndex]);
        $this->steps[$stepIndex]['images'] = array_values($this->steps[$stepIndex]['images']);
        $this->saveRun(false);
    }

    /**
     * Export current SIT Test Run to Excel (.xlsx) with embedded images.
     */
    public function exportExcel(SitDocumentExporter $exporter): BinaryFileResponse
    {
        $this->saveRun(false);
        $template = $this->getTemplate();
        $run = $this->getCurrentRun();

        $filePath = $exporter->exportExcel($template, $run);
        $downloadName = "SIT_{$template->template_code}_{$run->run_code}.xlsx";

        return response()->download($filePath, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export current SIT Test Run to Word (.docx) with embedded images.
     */
    public function exportWord(SitDocumentExporter $exporter): BinaryFileResponse
    {
        $this->saveRun(false);
        $template = $this->getTemplate();
        $run = $this->getCurrentRun();

        $filePath = $exporter->exportWord($template, $run);
        $downloadName = "SIT_{$template->template_code}_{$run->run_code}.docx";

        return response()->download($filePath, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export current SIT Test Run to standalone printable HTML with Base64 embedded images.
     */
    public function exportHtml(SitDocumentExporter $exporter): BinaryFileResponse
    {
        $this->saveRun(false);
        $template = $this->getTemplate();
        $run = $this->getCurrentRun();

        $filePath = $exporter->exportHtml($template, $run);
        $downloadName = "SIT_{$template->template_code}_{$run->run_code}.html";

        return response()->download($filePath, $downloadName, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Compute real-time step metrics for the active state.
     *
     * @return array{total: int, pass: int, fail: int, blocked: int, pending: int}
     */
    public function getMetricsProperty(): array
    {
        $counts = ['total' => count($this->steps), 'pass' => 0, 'fail' => 0, 'blocked' => 0, 'pending' => 0];
        foreach ($this->steps as $s) {
            $st = strtoupper((string) ($s['status'] ?? 'PENDING'));
            match ($st) {
                'PASS' => $counts['pass']++,
                'FAIL' => $counts['fail']++,
                'BLOCKED' => $counts['blocked']++,
                default => $counts['pending']++,
            };
        }

        return $counts;
    }

    protected function getCurrentRun(): SitTestRun
    {
        $template = $this->getTemplate();
        if ($this->selectedRunId) {
            $run = $template->testRuns()->whereKey($this->selectedRunId)->first();
            if ($run) {
                return $run;
            }
        }

        $latest = $template->testRuns()->latest('id')->first();
        if ($latest) {
            return $latest;
        }

        return $this->createRunRecord($template, 'Đợt kiểm thử SIT #1', 'SIT');
    }

    protected function loadRunState(SitTestRun $run): void
    {
        $this->selectedRunId = $run->id;
        $this->runTitle = $run->run_title;
        $this->runEnvironment = $run->environment ?: 'SIT';
        $this->testerName = (string) ($run->tester_name ?: (User::current()?->name ?: 'Admin'));
        $this->summaryNotes = (string) ($run->summary_notes ?? '');
        $this->steps = is_array($run->steps_data) ? array_values($run->steps_data) : [];
    }

    protected function createRunRecord(SitTemplate $template, string $title, string $environment): SitTestRun
    {
        $seq = $template->testRuns()->count() + 1;
        $run = SitTestRun::create([
            'sit_template_id' => $template->id,
            'run_code' => $this->generateUniqueRunCode($template->template_code, $seq),
            'run_title' => $title,
            'environment' => $environment,
            'tester_name' => User::current()?->name ?: 'Admin',
            'tested_at' => now(),
            'status' => SitTestRun::STATUS_IN_PROGRESS,
            'summary_notes' => '',
            'steps_data' => SitTestRun::buildStepsFromTemplate($template),
            'created_by' => User::current()?->id,
        ]);
        $run->syncOverallStatus();
        $run->save();

        return $run;
    }

    protected function generateUniqueRunCode(string $templateCode, int $seq): string
    {
        $prefix = strtoupper(Str::limit(Str::slug($templateCode, ''), 8, ''));
        $base = "SIT-{$prefix}-".now()->format('Ymd').'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
        if (! SitTestRun::where('run_code', $base)->exists()) {
            return $base;
        }

        return $base.'-'.strtoupper(Str::random(3));
    }

    protected function resolveTemplate(string $templateCode): SitTemplate
    {
        $template = SitTemplate::where('template_code', $templateCode)
            ->where('is_active', true)
            ->first();

        abort_if(! $template, 404, "SIT Template [{$templateCode}] not found or inactive.");

        return $template;
    }

    protected function authorizeTemplateAccess(SitTemplate $template): void
    {
        $user = User::current();
        abort_if(! $user, 403);

        $permissions = RolePermission::whereIn('role_code', $user->roles ?? [])->get();
        $allowed = app(DynamicSidebarBuilder::class)->canUserAccessSitTemplate($user, $template, $permissions);

        abort_if(! $allowed, 403, 'Bạn không có quyền truy cập kịch bản SIT này.');
    }
}
