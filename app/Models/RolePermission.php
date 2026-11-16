<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'role_permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_code',
        'role_name',
        'description',
        'is_system',
        'allowed_groups',
        'allowed_views',
        'allowed_sources',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'allowed_groups' => 'array',
            'allowed_views' => 'array',
            'allowed_sources' => 'array',
        ];
    }

    /**
     * Bootstrap model events.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $role): void {
            if ($role->is_system) {
                throw new \RuntimeException("Cannot delete system role: {$role->role_code}");
            }
        });
    }

    /**
     * Check if this role grants permission for a given view.
     */
    public function allowsView(string $viewCode): bool
    {
        $views = $this->allowed_views ?? [];

        return in_array('*', $views, true) || in_array($viewCode, $views, true);
    }

    /**
     * Check if this role grants permission for a given menu group.
     */
    public function allowsGroup(string $groupCode): bool
    {
        $groups = $this->allowed_groups ?? [];

        return in_array('*', $groups, true) || in_array($groupCode, $groups, true);
    }

    /**
     * Check if this role grants permission for a given data source.
     */
    public function allowsSource(string $sourceCode): bool
    {
        $sources = $this->allowed_sources ?? [];

        return in_array('*', $sources, true) || in_array($sourceCode, $sources, true);
    }
}
