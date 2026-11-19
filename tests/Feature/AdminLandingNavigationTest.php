<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLandingNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_lands_on_reports_with_contextual_tools_and_four_admin_destinations(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        $this->get('/admin')->assertRedirect('/admin/custom-views');
        $this->get('/admin/custom-views')->assertSuccessful()->assertSee('SmartReport')->assertSee('Nhóm báo cáo')->assertSee('Công cụ SQL');
        $urls = collect(Filament::getPanel('admin')->getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getUrl())->all();
        $this->assertNotContains(url('/admin/menu-groups'), $urls);
        $this->assertNotContains(url('/admin/sql-export'), $urls);
        $this->assertNotContains(url('/admin'), $urls);
        $this->assertContains(url('/admin/custom-views'), $urls);
        $this->assertContains(url('/admin/data-sources'), $urls);
        $this->assertContains(url('/admin/role-permissions'), $urls);
        $this->assertContains(url('/admin/sit-templates'), $urls);
    }

    public function test_sql_only_admin_retains_access_to_the_sql_tool(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get('/admin')->assertSuccessful()->assertSee('Công cụ SQL');
        $this->get('/admin/sql-export')->assertSuccessful();
    }
}
