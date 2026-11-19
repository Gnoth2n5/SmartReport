<?php

namespace Tests\Feature;

use App\Filament\Resources\DataSourceResource\Pages\CreateDataSource;
use App\Models\CustomView;
use App\Models\DataSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SourceDriverAndMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_defaults_follow_driver_changes_without_replacing_custom_values(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        $page = Livewire::test(CreateDataSource::class)->set('data.driver', 'oracle')->assertSet('data.port', 1521)->assertSet('data.default_schema', 'APP');
        $page->set('data.port', 1599)->set('data.default_schema', 'CUSTOM')->set('data.driver', 'sqlsrv');
        $page->assertSet('data.port', 1599)->assertSet('data.default_schema', 'CUSTOM');
    }

    public function test_source_destination_is_required_for_the_selected_driver(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        Livewire::test(CreateDataSource::class)->fillForm(['name' => 'New PG', 'driver' => 'pgsql', 'username' => 'app', 'password' => 'app'])->call('create')->assertHasFormErrors(['database_name']);
        Livewire::test(CreateDataSource::class)->fillForm(['name' => 'New Oracle', 'driver' => 'oracle', 'port' => 1521, 'username' => 'app', 'password' => 'app'])->call('create')->assertHasFormErrors(['service_name']);
    }

    public function test_generated_codes_are_unique_and_do_not_change_with_the_name(): void
    {
        $attributes = ['view_name' => 'Doanh thu', 'execution_mode' => 'single_or_union', 'sql_text' => 'SELECT 1'];
        $first = CustomView::create($attributes);
        $second = CustomView::create($attributes);
        $this->assertSame('doanh_thu', $first->view_code);
        $this->assertNotSame($first->view_code, $second->view_code);
        $first->view_name = 'Tên mới';
        $first->save();
        $this->assertSame('doanh_thu', $first->refresh()->view_code);
    }

    public function test_existing_source_codes_cannot_be_renamed_by_a_save(): void
    {
        $this->seed();
        $source = DataSource::where('code', 'pg_north')->firstOrFail();
        $source->code = 'other';
        $this->expectException(ValidationException::class);
        $source->save();
    }
}
