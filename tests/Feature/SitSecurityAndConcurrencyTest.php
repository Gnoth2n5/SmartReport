<?php

namespace Tests\Feature;

use App\Filament\Pages\SitRunnerPage;
use App\Models\SitTemplate;
use App\Models\SitTestRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SitSecurityAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBkcAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::where('email', 'admin@smartreport.test')->firstOrFail());
    }

    public function test_server_file_paths_cannot_be_saved_as_evidence(): void
    {
        $template = $this->template('private');
        $page = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $path = storage_path('outside-evidence.png');
        file_put_contents($path, base64_decode(self::PNG));
        $page->set('steps.0.images', [['path' => $path]])->call('saveRun')->assertHasErrors(['steps.0.images.0.path']);

        $this->assertSame([], SitTestRun::findOrFail($page->get('loadedRunId'))->steps_data[0]['images']);
    }

    public function test_pasted_data_must_really_be_an_image(): void
    {
        $template = $this->template('private');
        $page = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $before = Storage::disk('local')->allFiles('sit-evidence');
        $page->call('pasteStepImage', 0, 'data:image/png;base64,'.base64_encode('not an image'))->assertHasErrors(['steps']);

        $this->assertSame($before, Storage::disk('local')->allFiles('sit-evidence'));
    }

    public function test_revoking_a_template_grant_blocks_existing_livewire_requests(): void
    {
        $template = $this->template('reader', ['report_viewer']);
        $this->actingAs(User::factory()->create(['roles' => ['report_viewer']]));
        $page = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $id = $page->get('loadedRunId');
        $template->update(['roles' => ['super_admin']]);
        $page->set('summaryNotes', 'WRITE_AFTER_REVOKE')->assertForbidden();

        $this->assertSame('', SitTestRun::findOrFail($id)->summary_notes);
    }

    public function test_a_stale_tab_cannot_overwrite_saved_results_and_can_reload(): void
    {
        $template = $this->template('shared');
        $a = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $b = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $a->set('steps.0.fields.actual', 'SAVED_BY_A')->call('saveRun')->assertHasNoErrors();
        $b->set('summaryNotes', 'SAVED_BY_B')->call('saveRun')->assertHasErrors(['runRevision']);
        $id = $a->get('loadedRunId');
        $run = SitTestRun::findOrFail($id);
        $this->assertSame('SAVED_BY_A', $run->steps_data[0]['fields']['actual']);
        $this->assertSame('', $run->summary_notes);

        $b->call('selectRun', $id)->set('summaryNotes', 'SAVED_BY_B')->call('saveRun')->assertHasNoErrors();
        $run->refresh();
        $this->assertSame('SAVED_BY_A', $run->steps_data[0]['fields']['actual']);
        $this->assertSame('SAVED_BY_B', $run->summary_notes);
        $this->assertSame(2, $run->revision);
    }

    public function test_pass_requires_fields_and_select_values_while_drafts_can_be_saved(): void
    {
        $template = $this->template('validation');
        $template->update(['step_fields' => [...SitTemplate::defaultStepFields(), ['key' => 'decision', 'label' => 'Quyết định', 'type' => 'select', 'required' => true, 'options' => ['yes' => 'Có']]]]);
        $page = Livewire::test(SitRunnerPage::class, ['templateCode' => $template->template_code]);
        $page->call('saveRun')->assertHasNoErrors();
        $page->call('setStepStatus', 0, 'PASS')->assertHasErrors(['steps.0.fields.action', 'steps.0.fields.expected', 'steps.0.fields.decision'])->assertSet('steps.0.status', 'PENDING');
        $page->set('steps.0.fields.action', 'Thao tác')->set('steps.0.fields.expected', 'Kết quả')->set('steps.0.fields.decision', 'invalid')->call('saveRun')->assertHasErrors(['steps.0.fields.decision']);
        $page->set('steps.0.fields.decision', 'yes')->call('setStepStatus', 0, 'PASS')->assertHasNoErrors();

        $this->assertSame('PASSED', SitTestRun::findOrFail($page->get('loadedRunId'))->status);
    }

    public function test_evidence_requires_template_permission_and_a_reference_in_the_requested_run(): void
    {
        $private = $this->template('private');
        $shared = $this->template('shared', ['report_viewer']);
        $a = Livewire::test(SitRunnerPage::class, ['templateCode' => $private->template_code])->call('pasteStepImage', 0, 'data:image/png;base64,'.self::PNG);
        $b = Livewire::test(SitRunnerPage::class, ['templateCode' => $shared->template_code])->call('pasteStepImage', 0, 'data:image/png;base64,'.self::PNG);
        $aPath = $a->get('steps')[0]['images'][0]['path'];
        $bPath = $b->get('steps')[0]['images'][0]['path'];
        $this->assertFalse(Storage::disk('public')->exists($bPath));
        $this->actingAs(User::factory()->create(['roles' => ['report_viewer']]));
        $this->get('/admin/sit-evidence/'.$a->get('loadedRunId').'/'.$aPath)->assertForbidden();
        $this->get('/admin/sit-evidence/'.$b->get('loadedRunId').'/'.$aPath)->assertNotFound();
        $this->get('/admin/sit-evidence/'.$b->get('loadedRunId').'/'.$bPath)->assertSuccessful();
    }

    public function test_existing_evidence_moves_to_private_storage_without_changing_its_path(): void
    {
        $path = 'sit-evidence/legacy/image.png';
        Storage::disk('public')->put($path, base64_decode(self::PNG));
        $this->artisan('smartreport:secure-sit-evidence')->assertSuccessful();

        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($path));
    }

    public function test_evidence_migration_refuses_to_overwrite_a_different_private_file(): void
    {
        $path = 'sit-evidence/legacy/image.png';
        Storage::disk('public')->put($path, 'original public evidence');
        Storage::disk('local')->put($path, 'existing private evidence');
        try {
            $this->artisan('smartreport:secure-sit-evidence')->run();
            $this->fail('Different existing evidence must not be overwritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No file was overwritten', $e->getMessage());
        }

        $this->assertSame('original public evidence', Storage::disk('public')->get($path));
        $this->assertSame('existing private evidence', Storage::disk('local')->get($path));
    }

    private function template(string $code, array $roles = ['super_admin']): SitTemplate
    {
        return SitTemplate::create(['template_code' => $code, 'template_name' => $code, 'step_fields' => SitTemplate::defaultStepFields(), 'roles' => $roles, 'is_active' => true]);
    }
}
