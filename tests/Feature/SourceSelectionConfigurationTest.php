<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportRunnerPage;
use App\Filament\Resources\CustomViewResource\Pages\CreateCustomView;
use App\Models\CustomView;
use App\Models\User;
use App\Services\DuckDb\DuckDbReportEngine;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SourceSelectionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_source_is_automatically_the_default_and_invalid_default_is_refused(): void
    {
        $view = new CustomView(['source_selections' => [['source_code' => 'pg_south', 'is_allowed' => true, 'is_default' => false]]]);
        $this->assertSame(['pg_south'], $view->allowed_sources);
        $this->assertSame(['pg_south'], $view->default_sources);
        $this->expectException(ValidationException::class);
        $view->source_selections = [['source_code' => 'pg_north', 'is_allowed' => false, 'is_default' => true]];
    }

    public function test_unified_source_form_saves_both_lists_without_a_virtual_database_column(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        Livewire::test(CreateCustomView::class)->fillForm([
            'view_code' => 'south-only', 'view_name' => 'South only',
            'execution_mode' => 'single_or_union', 'version' => '1.0.0', 'sql_text' => 'SELECT 1 AS value',
            'source_selections' => [['source_code' => 'pg_south', 'source_name' => 'South', 'is_allowed' => true, 'is_default' => false]],
        ])->call('create')->assertHasNoFormErrors();
        $view = CustomView::where('view_code', 'south-only')->firstOrFail();
        $this->assertSame(['pg_south'], $view->allowed_sources);
        $this->assertSame(['pg_south'], $view->default_sources);
        $this->assertArrayNotHasKey('source_selections', $view->getAttributes());
    }

    public function test_preview_plan_and_runner_use_the_same_default_for_legacy_and_empty_defaults(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        foreach ([['pg_north'], ['pg_north', 'pg_south']] as $index => $allowed) {
            $view = CustomView::create([
                'view_code' => 'defaults-'.$index, 'view_name' => 'Defaults',
                'execution_mode' => 'single_or_union', 'allowed_sources' => $allowed,
                'default_sources' => $index === 0 ? ['pg_south'] : [], 'sql_text' => 'SELECT 1 AS value', 'is_active' => true,
            ]);
            $preview = app(DuckDbReportEngine::class)->buildInspectionData($view);
            $this->assertSame(['pg_north'], $preview['sources']->pluck('code')->all());
            Livewire::test(ReportRunnerPage::class, ['viewCode' => $view->view_code])->assertSet('selectedSources', ['pg_north']);
        }
    }
}
