<?php

namespace Tests\Feature;

use App\Filament\Resources\RolePermissionResource\Pages\EditRolePermission;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CentralContentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_editor_is_the_only_place_to_grant_and_revoke_content_access(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::where('email', 'admin@smartreport.test')->firstOrFail();
        $reader = User::factory()->create(['roles' => ['report_viewer']]);
        $this->actingAs($admin);
        $this->get('/admin/custom-views/pg-multi-plant-contracts/edit')->assertSuccessful()->assertSee('Quản lý quyền');
        $this->get('/admin/sit-templates/sit-erp-order-sync/edit')->assertSuccessful()->assertSee('Quản lý quyền');
        Livewire::test(EditRolePermission::class, ['record' => 'report_viewer'])
            ->fillForm(['allowed_views' => [], 'allowed_sit_templates' => [], 'allowed_sources' => ['pg_north']])
            ->call('save')->assertHasNoFormErrors();
        $role = RolePermission::where('role_code', 'report_viewer')->firstOrFail();
        $this->assertSame(['pg_north'], $role->allowed_sources);
        $this->flushSession();
        $this->actingAs($reader);
        $this->get('/admin/reports/pg-multi-plant-contracts')->assertForbidden();
        $this->get('/admin/sit-tests/sit-erp-order-sync')->assertForbidden();
        $role->update(['allowed_sit_templates' => ['sit-erp-order-sync']]);
        $this->get('/admin/sit-tests/sit-erp-order-sync')->assertSuccessful();
        $role->delete();
        $this->get('/admin/sit-tests/sit-erp-order-sync')->assertForbidden();
        $this->assertFalse(SitTemplate::accessibleTo($reader)->exists());
    }
}
