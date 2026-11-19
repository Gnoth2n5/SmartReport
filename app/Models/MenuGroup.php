<?php

namespace App\Models;

use App\Models\Concerns\GeneratesStableCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuGroup extends Model
{
    use GeneratesStableCode, HasFactory;

    protected const CODE_FIELD = 'group_code';

    protected const CODE_LABEL = 'title';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'menu_groups';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'group_code',
        'title',
        'icon',
        'sort_order',
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
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the custom views associated with this menu group.
     *
     * @return HasMany<CustomView, $this>
     */
    public function customViews(): HasMany
    {
        return $this->hasMany(CustomView::class, 'menu_group_code', 'group_code');
    }

    /**
     * Get the SIT templates associated with this menu group.
     *
     * @return HasMany<SitTemplate, $this>
     */
    public function sitTemplates(): HasMany
    {
        return $this->hasMany(SitTemplate::class, 'menu_group_code', 'group_code');
    }
}
