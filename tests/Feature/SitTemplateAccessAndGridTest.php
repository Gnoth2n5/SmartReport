<?php

namespace Tests\Feature;

use App\Filament\Resources\SitTemplateResource;
use App\Filament\Resources\SitTemplateResource\Pages\ListSitTemplates;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class SitTemplateAccessAndGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_can_find_authorized_sit_templates_but_cannot_manage_them(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $reader = User::factory()->create(['roles' => ['report_viewer']]);
        $private = SitTemplate::create(['template_code' => 'private-sit', 'template_name' => 'Private SIT', 'is_active' => true]);
        $shared = SitTemplate::where('template_code', 'sit-erp-order-sync')->firstOrFail();
        $this->actingAs($reader);

        $this->get('/admin')->assertSuccessful()->assertSee('/admin/sit-templates', false);
        $this->get('/admin/sit-templates')->assertSuccessful()->assertSee($shared->template_name)->assertDontSee('Private SIT');
        $this->get('/admin/sit-templates/create')->assertForbidden();
        $this->get('/admin/sit-templates/'.$shared->template_code.'/edit')->assertForbidden();
        $this->assertNull(SitTemplateResource::resolveRecordRouteBinding($private->id));
        $this->assertFalse(Gate::allows('delete', $shared));
        $this->assertFalse(Gate::allows('deleteAny', SitTemplate::class));
        Livewire::test(ListSitTemplates::class)->assertCanSeeTableRecords([$shared])->assertCanNotSeeTableRecords([$private])->assertTableActionHidden('delete', $shared);
    }

    public function test_sit_grid_paginates_instead_of_loading_all_templates(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        foreach (range(1, 13) as $number) {
            SitTemplate::create(['template_code' => 'grid-'.$number, 'template_name' => 'Grid '.$number, 'is_active' => true]);
        }
        $page = Livewire::test(ListSitTemplates::class);
        $this->assertCount(12, $page->instance()->getTableRecords());
        $page->call('gotoPage', 2);
        $this->assertCount(2, $page->instance()->getTableRecords());
    }

    public function test_sit_list_honors_explicit_view_grants_and_hides_inactive_templates(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $private = SitTemplate::create(['template_code' => 'granted-sit', 'template_name' => 'Granted SIT', 'is_active' => true]);
        $inactive = SitTemplate::create(['template_code' => 'inactive-sit', 'template_name' => 'Inactive SIT', 'is_active' => false]);
        RolePermission::create(['role_code' => 'custom_reader', 'role_name' => 'Custom reader', 'allowed_sit_templates' => [$private->template_code, $inactive->template_code]]);
        $this->actingAs(User::factory()->create(['roles' => ['custom_reader']]));
        Livewire::test(ListSitTemplates::class)->assertCanSeeTableRecords([$private])->assertCanNotSeeTableRecords([$inactive]);
    }
}
