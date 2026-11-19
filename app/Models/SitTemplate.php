<?php

namespace App\Models;

use App\Models\Concerns\GeneratesStableCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitTemplate extends Model
{
    use GeneratesStableCode, HasFactory;

    protected const CODE_FIELD = 'template_code';

    protected const CODE_LABEL = 'template_name';

    protected $table = 'sit_templates';

    protected $fillable = [
        'template_code',
        'template_name',
        'menu_group_code',
        'module_name',
        'description',
        'version',
        'step_fields',
        'default_steps',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'step_fields' => 'array',
            'default_steps' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $views = RolePermission::whereIn('role_code', $user->roles ?? [])->pluck('allowed_sit_templates')->flatten()->all();
        $query->where('is_active', true);
        if (in_array('*', $views, true)) {
            return $query;
        }

        return $query->whereIn('template_code', $views);
    }

    /**
     * Standard default fields for each SIT step when creating a template.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: array<string, string>}>
     */
    public static function defaultStepFields(): array
    {
        return [
            ['key' => 'action', 'label' => 'Thao tác thực hiện', 'type' => 'textarea', 'required' => true],
            ['key' => 'test_data', 'label' => 'Dữ liệu đầu vào (Input)', 'type' => 'textarea', 'required' => false],
            ['key' => 'expected', 'label' => 'Kết quả mong đợi (Expected)', 'type' => 'textarea', 'required' => true],
            ['key' => 'actual', 'label' => 'Kết quả thực tế (Actual)', 'type' => 'textarea', 'required' => false],
        ];
    }

    /**
     * Normalized step field definitions (falls back to defaultStepFields if empty).
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options: array<string, string>}>
     */
    public function resolvedStepFields(): array
    {
        $fields = is_array($this->step_fields) && ! empty($this->step_fields)
            ? $this->step_fields
            : self::defaultStepFields();

        $normalized = [];
        foreach ($fields as $idx => $f) {
            if (! is_array($f)) {
                continue;
            }
            $key = trim((string) ($f['key'] ?? 'field_'.($idx + 1)));
            if ($key === '') {
                continue;
            }
            $normalized[] = [
                'key' => $key,
                'label' => trim((string) ($f['label'] ?? $key)),
                'type' => in_array($f['type'] ?? 'textarea', ['text', 'textarea', 'select'], true) ? $f['type'] : 'textarea',
                'required' => (bool) ($f['required'] ?? false),
                'options' => is_array($f['options'] ?? null) ? $f['options'] : [],
            ];
        }

        return $normalized ?: self::defaultStepFields();
    }

    /**
     * @return BelongsTo<MenuGroup, $this>
     */
    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class, 'menu_group_code', 'group_code');
    }

    /**
     * @return HasMany<SitTestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(SitTestRun::class, 'sit_template_id');
    }
}
