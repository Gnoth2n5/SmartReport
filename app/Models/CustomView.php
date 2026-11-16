<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomView extends Model
{
    use HasFactory;

    public const MODE_SINGLE_OR_UNION = 'single_or_union';

    public const MODE_FEDERATED_JOIN = 'federated_join';

    public const EXECUTION_MODES = [
        self::MODE_SINGLE_OR_UNION,
        self::MODE_FEDERATED_JOIN,
    ];

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
        'source_queries',
        'sql_text',
        'filters',
        'roles',
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
            'roles' => 'array',
            'is_active' => 'boolean',
        ];
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
