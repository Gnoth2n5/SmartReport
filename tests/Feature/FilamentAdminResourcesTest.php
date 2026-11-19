<?php

namespace Tests\Feature;

use App\Filament\Resources\DataSourceResource;
use App\Filament\Resources\DataSourceResource\Pages\CreateDataSource;
use App\Filament\Resources\DataSourceResource\Pages\EditDataSource;
use App\Filament\Resources\DataSourceResource\Pages\ListDataSources;
use App\Filament\Resources\MenuGroupResource;
use App\Filament\Resources\MenuGroupResource\Pages\CreateMenuGroup;
use App\Filament\Resources\MenuGroupResource\Pages\EditMenuGroup;
use App\Filament\Resources\MenuGroupResource\Pages\ListMenuGroups;
use App\Filament\Resources\RolePermissionResource;
use App\Filament\Resources\RolePermissionResource\Pages\CreateRolePermission;
use App\Filament\Resources\RolePermissionResource\Pages\EditRolePermission;
use App\Filament\Resources\RolePermissionResource\Pages\ListRolePermissions;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\SmartReportSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid hammering external Docker DBs with DDL during UI feature tests

        $this->seed(SmartReportSeeder::class);

        $this->adminUser = User::where('email', 'admin@smartreport.test')->firstOrFail();
        $this->viewerUser = User::factory()->create([
            'email' => 'viewer@smartreport.test',
            'roles' => ['report_viewer'],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // 1. Authentication & Authorization Access Control
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_and_admin_can_access(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/data-sources')->assertRedirect('/admin/login');
        $this->get('/admin/menu-groups')->assertRedirect('/admin/login');
        $this->get('/admin/role-permissions')->assertRedirect('/admin/login');

        $this->actingAs($this->adminUser)->get('/admin')->assertSuccessful();
    }

    public function test_non_super_admin_cannot_access_administrative_resources(): void
    {
        // 1. As report_viewer (non-super-admin), access to admin resources must be rejected with 403 Forbidden
        $this->actingAs($this->viewerUser);

        // Resource method checks
        $this->assertFalse(DataSourceResource::canAccess());
        $this->assertFalse(MenuGroupResource::canAccess());
        $this->assertFalse(RolePermissionResource::canAccess());

        // HTTP GET index routes must return 403 Forbidden
        $this->get('/admin/data-sources')->assertForbidden();
        $this->get('/admin/menu-groups')->assertForbidden();
        $this->get('/admin/role-permissions')->assertForbidden();

        // HTTP GET create routes must return 403 Forbidden
        $this->get('/admin/data-sources/create')->assertForbidden();
        $this->get('/admin/menu-groups/create')->assertForbidden();
        $this->get('/admin/role-permissions/create')->assertForbidden();

        // HTTP GET edit routes must return 403 Forbidden
        $this->get('/admin/data-sources/pg_north/edit')->assertForbidden();
        $this->get('/admin/menu-groups/manufacturing/edit')->assertForbidden();
        $this->get('/admin/role-permissions/super_admin/edit')->assertForbidden();

        // Direct Livewire component mount must be forbidden
        Livewire::test(ListDataSources::class)->assertForbidden();
        Livewire::test(ListMenuGroups::class)->assertForbidden();
        Livewire::test(ListRolePermissions::class)->assertForbidden();

        // 2. As super_admin, access to administrative resources must succeed
        $this->flushSession();
        $this->actingAs($this->adminUser);

        $this->assertTrue(DataSourceResource::canAccess());
        $this->assertTrue(MenuGroupResource::canAccess());
        $this->assertTrue(RolePermissionResource::canAccess());

        $this->get('/admin/data-sources')->assertSuccessful();
        $this->get('/admin/menu-groups')->assertSuccessful();
        $this->get('/admin/role-permissions')->assertSuccessful();
        $this->get('/admin/data-sources')->assertSuccessful();
        $this->get('/admin/menu-groups')->assertSuccessful();
        $this->get('/admin/role-permissions')->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // 2. DataSourceResource
    // -------------------------------------------------------------------------

    public function test_data_source_resource_lists_seeded_sources(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(ListDataSources::class)
            ->assertCanSeeTableRecords(DataSource::all())
            ->assertCountTableRecords(4);
    }

    public function test_data_source_resource_restricts_driver_to_allowed_three(): void
    {
        $this->actingAs($this->adminUser);

        // Attempting to create with disallowed driver 'mysql'
        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'code' => 'mysql_db',
                'name' => 'MySQL DB',
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database_name' => 'test',
                'username' => 'root',
                'password' => 'secret',
            ])
            ->call('create')
            ->assertHasFormErrors(['driver']);

        // Attempting to create with disallowed driver 'sqlite'
        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'code' => 'sqlite_db',
                'name' => 'SQLite DB',
                'driver' => 'sqlite',
                'host' => '127.0.0.1',
                'port' => 0,
                'database_name' => 'test.db',
                'username' => 'root',
                'password' => 'secret',
            ])
            ->call('create')
            ->assertHasFormErrors(['driver']);

        // Creating with valid driver (sqlsrv) succeeds
        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'code' => 'new_mssql',
                'name' => 'New MSSQL DB',
                'driver' => DataSource::DRIVER_SQLSRV,
                'host' => '127.0.0.1',
                'port' => 1433,
                'database_name' => 'erp_backup',
                'username' => 'sa',
                'password' => 'DevPassword!123',
                'is_active' => true,
                'is_read_only' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('data_sources', ['code' => 'new_mssql']);

        // Password is encrypted in DB
        $raw = DB::table('data_sources')->where('code', 'new_mssql')->value('password');
        $this->assertNotEquals('DevPassword!123', $raw);
        $this->assertSame('DevPassword!123', DataSource::where('code', 'new_mssql')->first()->password);
    }

    public function test_data_source_resource_edit_preserves_password_when_blank_and_updates_when_filled(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Create a DataSource record with an initial password
        $source = DataSource::create([
            'code' => 'edit_pwd_test',
            'name' => 'Original Password Test Source',
            'driver' => DataSource::DRIVER_PGSQL,
            'host' => '127.0.0.1',
            'port' => 5432,
            'database_name' => 'test_db',
            'username' => 'test_user',
            'password' => 'InitialSecretPassword!123',
            'is_active' => true,
            'is_read_only' => true,
        ]);

        $this->assertSame('InitialSecretPassword!123', $source->password);
        $initialRawPassword = DB::table('data_sources')->where('code', 'edit_pwd_test')->value('password');
        $this->assertNotEquals('InitialSecretPassword!123', $initialRawPassword);

        // 2. Edit with blank password: password is preserved intact, other fields update
        Livewire::test(EditDataSource::class, ['record' => 'edit_pwd_test'])
            ->fillForm([
                'name' => 'Renamed Test Source',
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();
        $this->assertSame('Renamed Test Source', $source->name);
        $this->assertSame('InitialSecretPassword!123', $source->password);
        $this->assertSame($initialRawPassword, DB::table('data_sources')->where('code', 'edit_pwd_test')->value('password'));

        // 3. Edit with new password: password updates and is re-encrypted in the database
        Livewire::test(EditDataSource::class, ['record' => 'edit_pwd_test'])
            ->fillForm([
                'password' => 'UpdatedSecretPassword!456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();
        $this->assertSame('UpdatedSecretPassword!456', $source->password);
        $updatedRawPassword = DB::table('data_sources')->where('code', 'edit_pwd_test')->value('password');
        $this->assertNotEquals('UpdatedSecretPassword!456', $updatedRawPassword);
        $this->assertNotEquals($initialRawPassword, $updatedRawPassword);
    }

    public function test_data_source_test_connection_action_on_live_sources(): void
    {
        $this->actingAs($this->adminUser);

        $pgNorth = DataSource::where('code', 'pg_north')->firstOrFail();
        $mssqlErp = DataSource::where('code', 'mssql_erp')->firstOrFail();
        $oracleHr = DataSource::where('code', 'oracle_hr')->firstOrFail();

        // Test Connection on PostgreSQL (table action)
        Livewire::test(ListDataSources::class)
            ->callTableAction('test_connection', $pgNorth)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        // Test Connection on SQL Server (table action)
        Livewire::test(ListDataSources::class)
            ->callTableAction('test_connection', $mssqlErp)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        // Test Connection on Oracle (table action)
        Livewire::test(ListDataSources::class)
            ->callTableAction('test_connection', $oracleHr)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        // Test Connection on Edit Page header action
        Livewire::test(EditDataSource::class, ['record' => 'pg_north'])
            ->callAction('test_connection')
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_data_source_test_connection_fails_on_unreachable_target(): void
    {
        $this->actingAs($this->adminUser);

        $brokenSource = DataSource::create([
            'code' => 'broken_pg',
            'name' => 'Broken Postgres',
            'driver' => DataSource::DRIVER_PGSQL,
            'host' => '127.0.0.1',
            'port' => 5999, // Unused port
            'database_name' => 'none',
            'username' => 'user',
            'password' => 'pass',
            'is_active' => true,
        ]);

        Livewire::test(ListDataSources::class)
            ->callTableAction('test_connection', $brokenSource)
            ->assertNotified();
    }

    // -------------------------------------------------------------------------
    // 3. MenuGroupResource
    // -------------------------------------------------------------------------

    public function test_menu_group_resource_crud_operations(): void
    {
        $this->actingAs($this->adminUser);

        // List
        Livewire::test(ListMenuGroups::class)
            ->assertCanSeeTableRecords(MenuGroup::all());

        // Create
        Livewire::test(CreateMenuGroup::class)
            ->fillForm([
                'group_code' => 'finance',
                'title' => 'Tài Chính & Kế Toán',
                'icon' => 'heroicon-o-banknotes',
                'sort_order' => 40,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('menu_groups', ['group_code' => 'finance']);

        // Edit
        Livewire::test(EditMenuGroup::class, ['record' => 'finance'])
            ->fillForm([
                'title' => 'Tài Chính Doanh Nghiệp',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $group = MenuGroup::where('group_code', 'finance')->firstOrFail();
        $this->assertSame('Tài Chính Doanh Nghiệp', $group->title);
        $this->assertFalse($group->is_active);
    }

    // -------------------------------------------------------------------------
    // 4. RolePermissionResource
    // -------------------------------------------------------------------------

    public function test_role_permission_resource_crud_operations(): void
    {
        $this->actingAs($this->adminUser);

        // List
        Livewire::test(ListRolePermissions::class)
            ->assertCanSeeTableRecords(RolePermission::all());

        // Create
        Livewire::test(CreateRolePermission::class)
            ->fillForm([
                'role_code' => 'plant_operator',
                'role_name' => 'Quản Đốc Phân Xưởng',
                'description' => 'Xem lệnh sản xuất và định mức',
                'is_system' => false,
                'allowed_sit_templates' => ['sit-erp-order-sync'],
                'allowed_views' => ['mssql-production-orders'],
                'allowed_sources' => ['mssql_erp'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = RolePermission::where('role_code', 'plant_operator')->firstOrFail();
        $this->assertTrue($role->allowsSitTemplate('sit-erp-order-sync'));
        $this->assertFalse($role->allowsSitTemplate('other-template'));
        $this->assertTrue($role->allowsView('mssql-production-orders'));
        $this->assertFalse($role->allowsView('pg-multi-plant-contracts'));
        $this->assertTrue($role->allowsSource('mssql_erp'));
        $this->assertFalse($role->allowsSource('oracle_hr'));
    }

    public function test_role_permission_system_role_cannot_be_deleted(): void
    {
        $this->actingAs($this->adminUser);

        $superAdminRole = RolePermission::where('role_code', 'super_admin')->firstOrFail();

        $this->assertFalse(RolePermissionResource::canDelete($superAdminRole));
        $this->assertTrue(RolePermissionResource::getDeleteAuthorizationResponse($superAdminRole)->denied());

        Livewire::test(ListRolePermissions::class)
            ->assertTableActionHidden('delete', $superAdminRole);

        Livewire::test(EditRolePermission::class, ['record' => 'super_admin'])
            ->assertActionHidden('delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete system role: super_admin');
        $superAdminRole->delete();
    }

    // -------------------------------------------------------------------------
    // 5. DynamicSidebarBuilder Navigation
    // -------------------------------------------------------------------------

    public function test_dynamic_sidebar_for_super_admin_renders_all_active_groups(): void
    {
        $this->actingAs($this->adminUser);

        $panel = Filament::getPanel('admin');
        $navigation = $panel->getNavigation();

        $groupLabels = array_map(fn (NavigationGroup $g): string => (string) $g->getLabel(), $navigation);

        // System Admin resources group
        $this->assertTrue(
            in_array('Hệ Thống', $groupLabels, true) || in_array('Quản Trị Hệ Thống', $groupLabels, true)
        );

        // Report Menu Groups
        $this->assertContains('Sản Xuất & Vận Hành', $groupLabels);
        $this->assertContains('Xuất Nhập Khẩu & Hợp Đồng', $groupLabels);
        $this->assertContains('Báo Cáo Điều Hành & Nhân Sự', $groupLabels);

        // HTTP assertion
        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Sản Xuất & Vận Hành')
            ->assertSee('Xuất Nhập Khẩu & Hợp Đồng')
            ->assertSee('Báo Cáo Điều Hành & Nhân Sự');
    }

    public function test_dynamic_sidebar_filters_navigation_by_user_role(): void
    {
        $this->actingAs($this->viewerUser);

        $panel = Filament::getPanel('admin');
        $navigation = $panel->getNavigation();

        $groupLabels = array_map(fn (NavigationGroup $g): string => (string) $g->getLabel(), $navigation);

        // report_viewer has access to logistics & manufacturing, but NOT executive or admin
        $this->assertContains('Sản Xuất & Vận Hành', $groupLabels);
        $this->assertContains('Xuất Nhập Khẩu & Hợp Đồng', $groupLabels);
        $this->assertContains('Báo Cáo Điều Hành & Nhân Sự', $groupLabels);
        $this->assertNotContains('Hệ Thống', $groupLabels);
        $this->assertNotContains('Quản Trị Hệ Thống', $groupLabels);

        // HTTP assertion
        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Xuất Nhập Khẩu & Hợp Đồng')
            ->assertSee('Báo Cáo Điều Hành & Nhân Sự');
    }

    public function test_dynamic_sidebar_hides_inactive_groups_and_views(): void
    {
        $this->actingAs($this->adminUser);

        MenuGroup::where('group_code', 'executive')->update(['is_active' => false]);

        $panel = Filament::getPanel('admin');
        $navigation = $panel->getNavigation();

        $groupLabels = array_map(fn (NavigationGroup $g): string => (string) $g->getLabel(), $navigation);
        $this->assertNotContains('Báo Cáo Điều Hành & Nhân Sự', $groupLabels);
    }
}
