<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SitTestRun extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_PASSED = 'PASSED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STEP_PENDING = 'PENDING';

    public const STEP_PASS = 'PASS';

    public const STEP_FAIL = 'FAIL';

    public const STEP_BLOCKED = 'BLOCKED';

    protected $table = 'sit_test_runs';

    protected $fillable = [
        'sit_template_id',
        'run_code',
        'run_title',
        'environment',
        'tester_name',
        'tested_at',
        'status',
        'summary_notes',
        'steps_data',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tested_at' => 'datetime',
            'steps_data' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SitTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SitTemplate::class, 'sit_template_id');
    }

    /**
     * Build initial steps array from a SitTemplate's default_steps.
     *
     * @return list<array<string, mixed>>
     */
    public static function buildStepsFromTemplate(SitTemplate $template): array
    {
        $fieldDefs = $template->resolvedStepFields();
        $defaults = is_array($template->default_steps) ? $template->default_steps : [];

        if (empty($defaults)) {
            $emptyFields = [];
            foreach ($fieldDefs as $fd) {
                $emptyFields[$fd['key']] = '';
            }

            return [[
                'uid' => 'step_'.Str::lower(Str::random(8)),
                'step_no' => 1,
                'title' => 'Bước 1',
                'status' => self::STEP_PENDING,
                'fields' => $emptyFields,
                'extra_fields' => [],
                'images' => [],
            ]];
        }

        $steps = [];
        foreach (array_values($defaults) as $idx => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $fields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
            foreach ($fieldDefs as $fd) {
                $k = $fd['key'];
                if (! array_key_exists($k, $fields)) {
                    $fields[$k] = (string) ($raw[$k] ?? '');
                }
            }

            $steps[] = [
                'uid' => (string) ($raw['uid'] ?? 'step_'.Str::lower(Str::random(8))),
                'step_no' => $idx + 1,
                'title' => (string) ($raw['title'] ?? 'Bước '.($idx + 1)),
                'status' => in_array($raw['status'] ?? '', [self::STEP_PASS, self::STEP_FAIL, self::STEP_BLOCKED, self::STEP_PENDING], true)
                    ? $raw['status']
                    : self::STEP_PENDING,
                'fields' => $fields,
                'extra_fields' => is_array($raw['extra_fields'] ?? null) ? array_values($raw['extra_fields']) : [],
                'images' => is_array($raw['images'] ?? null) ? array_values($raw['images']) : [],
            ];
        }

        return $steps;
    }

    /**
     * Compute step summary counts.
     *
     * @return array{total: int, pass: int, fail: int, blocked: int, pending: int}
     */
    public function computeMetrics(): array
    {
        $steps = is_array($this->steps_data) ? $this->steps_data : [];
        $counts = ['total' => count($steps), 'pass' => 0, 'fail' => 0, 'blocked' => 0, 'pending' => 0];

        foreach ($steps as $s) {
            $st = strtoupper((string) ($s['status'] ?? self::STEP_PENDING));
            match ($st) {
                self::STEP_PASS => $counts['pass']++,
                self::STEP_FAIL => $counts['fail']++,
                self::STEP_BLOCKED => $counts['blocked']++,
                default => $counts['pending']++,
            };
        }

        return $counts;
    }

    /**
     * Derive overall run status from step statuses.
     */
    public function syncOverallStatus(): string
    {
        $m = $this->computeMetrics();
        if ($m['fail'] > 0) {
            $this->status = self::STATUS_FAILED;
        } elseif ($m['blocked'] > 0) {
            $this->status = self::STATUS_BLOCKED;
        } elseif ($m['total'] > 0 && $m['pass'] === $m['total']) {
            $this->status = self::STATUS_PASSED;
        } else {
            $this->status = self::STATUS_IN_PROGRESS;
        }

        return $this->status;
    }
}
