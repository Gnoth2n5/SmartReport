<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomViewResource\Pages\CreateCustomView;
use App\Models\CustomView;
use App\Models\User;
use App\Services\Sql\SqlParameterExtractor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SqlFilterSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_variants_are_one_parameter_without_guessed_business_options(): void
    {
        $parameters = app(SqlParameterExtractor::class)->extract('SELECT @Status, @status, @Plant');
        $this->assertSame(['Status', 'Plant'], array_column($parameters, 'name'));
        $this->assertSame('text', $parameters[0]['type']);
        $this->assertNull($parameters[0]['options']);
    }

    public function test_sql_edit_automatically_updates_filters_and_preserves_customizations(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        $page = Livewire::test(CreateCustomView::class);
        $page->set('data.sql_text', 'SELECT @Status, @status')->assertSet('data.filters.0.name', 'Status');
        $page->set('data.filters.0.label', 'Trạng thái duyệt')->set('data.filters.0.type', 'select')->set('data.filters.0.options', ['OPEN' => 'Mở']);
        $page->set('data.sql_text', 'SELECT @status, @StartDate');
        $filters = $page->get('data')['filters'];
        $this->assertCount(2, $filters);
        $this->assertSame('Trạng thái duyệt', $filters[0]['label']);
        $this->assertSame(['OPEN' => 'Mở'], $filters[0]['options']);
        $this->assertSame('date', $filters[1]['type']);
    }

    public function test_saving_uses_sql_names_and_keeps_existing_label_type_and_options(): void
    {
        $view = CustomView::create([
            'view_code' => 'sync', 'view_name' => 'Sync', 'execution_mode' => 'single_or_union', 'sql_text' => 'SELECT @status',
            'filters' => [['name' => 'Status', 'label' => 'Duyệt', 'type' => 'select', 'options' => ['OPEN' => 'Mở'], 'is_required' => true]],
        ]);
        $view->sql_text = 'SELECT @STATUS, @StartDate';
        $view->save();
        $this->assertCount(2, $view->filters);
        $this->assertSame('Status', $view->filters[0]['name']);
        $this->assertSame('Duyệt', $view->filters[0]['label']);
        $this->assertTrue($view->filters[0]['is_required']);
        $this->assertSame(['OPEN' => 'Mở'], $view->filters[0]['options']);
    }
}
