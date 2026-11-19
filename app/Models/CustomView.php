<?php

namespace App\Models;

use App\Models\Concerns\GeneratesStableCode;
use App\Services\Sql\SqlParameterExtractor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CustomView extends Model
{
    use GeneratesStableCode, HasFactory;

    public const MODE_SINGLE_OR_UNION = 'single_or_union';

    public const MODE_FEDERATED_JOIN = 'federated_join';

    public const EXECUTION_MODES = [
        self::MODE_SINGLE_OR_UNION,
        self::MODE_FEDERATED_JOIN,
    ];

    protected const CODE_FIELD = 'view_code';

    protected const CODE_LABEL = 'view_name';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom_views';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'view_code',
        'view_name',
        'menu_group_code',
        'execution_mode',
        'allowed_sources',
        'default_sources',
        'source_selections',
        'source_queries',
        'sql_text',
        'filters',
        'description',
        'version',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_sources' => 'array',
            'default_sources' => 'array',
            'source_queries' => 'array',
            'filters' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $view): void {
            $view->filters = app(SqlParameterExtractor::class)->synchronizeFilters($view);
        });
    }

    public function setSourceSelectionsAttribute(array $rows): void
    {
        $allowed = [];
        $defaults = [];
        foreach ($rows as $row) {
            if ($row['is_allowed'] ?? false) {
                $allowed[] = $row['source_code'];
                if ($row['is_default'] ?? false) {
                    $defaults[] = $row['source_code'];
                }
            } elseif ($row['is_default'] ?? false) {
                throw ValidationException::withMessages(['source_selections' => 'Nguồn mặc định phải nằm trong nguồn được cho phép.']);
            }
        }
        if ($allowed === []) {
            throw ValidationException::withMessages(['source_selections' => 'Chọn ít nhất một nguồn dữ liệu.']);
        }
        $this->allowed_sources = array_values(array_unique($allowed));
        $this->default_sources = count($this->allowed_sources) === 1 ? $this->allowed_sources : array_values(array_unique($defaults));
    }

    /** @param list<string> $availableCodes
     * @return list<string>
     */
    public function defaultSourceCodes(array $availableCodes): array
    {
        $permitted = array_values(array_intersect($availableCodes, $this->allowed_sources ?: $availableCodes));
        $defaults = array_values(array_intersect($this->default_sources ?? [], $permitted));

        return $defaults ?: array_slice($permitted, 0, 1);
    }

    /**
     * Get the menu group that owns this view.
     *
     * @return BelongsTo<MenuGroup, $this>
     */
    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class, 'menu_group_code', 'group_code');
    }

    /**
     * Check if this view operates in single or union mode.
     */
    public function isSingleOrUnion(): bool
    {
        return $this->execution_mode === self::MODE_SINGLE_OR_UNION;
    }

    /**
     * Check if this view operates in federated join mode.
     */
    public function isFederatedJoin(): bool
    {
        return $this->execution_mode === self::MODE_FEDERATED_JOIN;
    }
}
