<?php

namespace Tests\Feature;

use App\Filament\Pages\SitRunnerPage;
use App\Filament\Resources\SitTemplateResource\Pages\CreateSitTemplate;
use App\Models\SitTemplate;
use App\Models\SitTestRun;
use App\Models\User;
use App\Services\Export\SitDocumentExporter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SitSharedSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_fields_in_default_steps_reach_runner_and_exports(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
        Livewire::test(CreateSitTemplate::class)->fillForm([
            'template_name' => 'Kho', 'version' => '1.0', 'is_active' => true,
            'step_fields' => [['key' => 'warehouse', 'label' => 'Mã kho', 'type' => 'select', 'required' => false, 'options' => ['A' => 'Kho A']]],
            'default_steps' => [['title' => 'Kiểm tra kho', 'fields' => ['warehouse' => 'A']]],
        ])->call('create')->assertHasNoFormErrors();
        $template = SitTemplate::where('template_name', 'Kho')->firstOrFail();
        $page = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code])->assertSet('steps.0.fields.warehouse', 'A');
        $run = SitTestRun::findOrFail($page->get('loadedRunId'));
        $this->assertSame('warehouse', $run->resolvedStepFields()[0]['key']);
        $this->assertStringContainsString('Mã kho', app(SitDocumentExporter::class)->renderHtmlContent($template, $run));
    }

    public function test_template_edits_preserve_old_run_schema_version_and_old_default_values(): void
    {
        $this->seed();
        $template = SitTemplate::create([
            'template_name' => 'Schema history', 'version' => '1.0', 'is_active' => true,
            'step_fields' => [['key' => 'warehouse', 'label' => 'Mã kho', 'type' => 'text', 'required' => false]],
            'default_steps' => [['title' => 'Kho', 'fields' => ['warehouse' => 'A']]],
        ]);
        $run = SitTestRun::create(['sit_template_id' => $template->id, 'run_code' => 'history', 'run_title' => 'History', 'steps_data' => SitTestRun::buildStepsFromTemplate($template)]);
        $uid = $template->default_steps[0]['uid'];
        $template->update([
            'version' => '2.0', 'step_fields' => [['key' => 'lot', 'label' => 'Lô', 'type' => 'text', 'required' => false]],
            'default_steps' => [['uid' => $uid, 'title' => 'Lô', 'fields' => ['lot' => 'L']]],
        ]);
        $this->assertSame('A', $template->default_steps[0]['fields']['warehouse']);
        $this->assertSame('1.0', $run->refresh()->template_version);
        $this->assertSame('warehouse', $run->resolvedStepFields()[0]['key']);
        $html = app(SitDocumentExporter::class)->renderHtmlContent($template, $run);
        $this->assertStringContainsString('Mã kho', $html);
        $this->assertStringContainsString('Phiên bản 1.0', $html);
        $newSteps = SitTestRun::buildStepsFromTemplate($template);
        $this->assertSame(['lot' => 'L'], $newSteps[0]['fields']);
    }
}
