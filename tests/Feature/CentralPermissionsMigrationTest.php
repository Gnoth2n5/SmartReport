<?php

namespace Tests\Feature;

use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use App\Services\Navigation\DynamicSidebarBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralPermissionsMigrationTest extends TestCase
{
    public function test_existing_grants_are_consolidated_without_granting_sources_or_group_only_access(): void
    {
        $migrationPath = database_path('migrations/2026_11_19_174919_centralize_content_permissions.php');
        $oldPaths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => $path !== $migrationPath));
        $this->artisan('migrate', ['--path' => $oldPaths, '--realpath' => true])->assertSuccessful();
        DB::table('role_permissions')->insert([
            ['role_code' => 'old', 'role_name' => 'Old role', 'allowed_groups' => '["group-a"]', 'allowed_views' => '["same-code"]', 'allowed_sources' => '["pg_north"]'],
            ['role_code' => 'all', 'role_name' => 'All content', 'allowed_groups' => '[]', 'allowed_views' => '["*"]', 'allowed_sources' => '[]'],
        ]);
        DB::table('menu_groups')->insert(['group_code' => 'group-a', 'title' => 'A', 'roles' => '["group-only"]']);
        DB::table('custom_views')->insert([
            ['view_code' => 'same-code', 'view_name' => 'Same', 'sql_text' => 'SELECT 1', 'execution_mode' => 'single_or_union', 'roles' => '[]'],
            ['view_code' => 'tagged-report', 'view_name' => 'Tagged', 'sql_text' => 'SELECT 1', 'execution_mode' => 'single_or_union', 'roles' => '["tagged"]'],
        ]);
        DB::table('sit_templates')->insert([
            ['template_code' => 'same-code', 'template_name' => 'Same SIT', 'roles' => '[]'],
            ['template_code' => 'tagged-sit', 'template_name' => 'Tagged SIT', 'roles' => '["tagged"]'],
        ]);
        $this->artisan('migrate', ['--path' => [$migrationPath], '--realpath' => true])->assertSuccessful();

        $old = RolePermission::where('role_code', 'old')->firstOrFail();
        $this->assertTrue($old->allowsView('same-code'));
        $this->assertTrue($old->allowsSitTemplate('same-code'));
        $this->assertSame(['pg_north'], $old->allowed_sources);
        $tagged = RolePermission::where('role_code', 'tagged')->firstOrFail();
        $this->assertTrue($tagged->allowsView('tagged-report'));
        $this->assertTrue($tagged->allowsSitTemplate('tagged-sit'));
        $this->assertSame([], $tagged->allowed_sources);
        $this->assertFalse(RolePermission::where('role_code', 'group-only')->exists());
        $all = RolePermission::where('role_code', 'all')->firstOrFail();
        $this->assertTrue($all->allowsView('future-report'));
        $this->assertTrue($all->allowsSitTemplate('future-sit'));
        $this->assertFalse(Schema::hasColumn('custom_views', 'roles'));
        $this->assertFalse(Schema::hasColumn('role_permissions', 'allowed_groups'));

        $user = new User(['roles' => ['tagged']]);
        $template = SitTemplate::where('template_code', 'tagged-sit')->firstOrFail();
        $tagged->delete();
        $this->assertFalse(app(DynamicSidebarBuilder::class)->canUserAccessSitTemplate($user, $template, collect([])));
        $this->assertFalse(SitTemplate::accessibleTo($user)->exists());
        $role = RolePermission::create(['role_code' => 'report-only', 'role_name' => 'Report only', 'allowed_views' => ['same-code'], 'allowed_sit_templates' => []]);
        $this->assertTrue($role->allowsView('same-code'));
        $this->assertFalse($role->allowsSitTemplate('same-code'));
    }
}
