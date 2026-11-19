<?php

namespace Tests\Feature;

use App\Filament\Pages\SitRunnerPage;
use App\Filament\Resources\SitTemplateResource\Pages\CreateSitTemplate;
use App\Filament\Resources\SitTemplateResource\Pages\ListSitTemplates;
use App\Models\SitTemplate;
use App\Models\SitTestRun;
use App\Models\User;
use App\Services\Export\SitDocumentExporter;
use Database\Seeders\SmartReportSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class SitModuleAndExporterTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_sit_template_resource_creates_custom_template_and_renders_minimalist_cards_without_cluttering_sidebar(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(CreateSitTemplate::class)
            ->fillForm([
                'template_code' => 'sit-custom-warehouse',
                'template_name' => 'SIT Kiểm Thử Nhập Xuất Kho',
                'module_name' => 'WMS Kho Vận',
                'version' => '1.1.0',
                'is_active' => true,
                'step_fields' => [
                    ['key' => 'action', 'label' => 'Bước thao tác', 'type' => 'textarea', 'required' => true],
                    ['key' => 'expected', 'label' => 'Kỳ vọng', 'type' => 'textarea', 'required' => true],
                    ['key' => 'actual', 'label' => 'Thực tế', 'type' => 'textarea', 'required' => false],
                ],
                'default_steps' => [
                    [
                        'title' => 'Tạo phiếu nhập kho nguyên phụ liệu',
                        'action' => 'Vào WMS -> Nhập kho -> Lưu phiếu',
                        'test_data' => 'SKU=FAB-01, Qty=200',
                        'expected' => 'Tồn kho tăng 200',
                    ],
                ],
                'roles' => ['super_admin'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sit_templates', [
            'template_code' => 'sit-custom-warehouse',
        ]);

        // Verify individual SIT templates are NOT pushed onto the Sidebar
        $this->get('/admin')
            ->assertSuccessful()
            ->assertDontSee('/admin/sit-tests/sit-custom-warehouse');

        // Verify /admin/sit-templates renders minimalist cards (not a table) and supports cardSearch
        Livewire::test(ListSitTemplates::class)
            ->assertSee('sit-cards-grid', false)
            ->assertSee('SIT Kiểm Thử Nhập Xuất Kho')
            ->assertSee('/admin/sit-tests/sit-custom-warehouse', false)
            ->set('cardSearch', 'WMS Kho Vận')
            ->assertSee('SIT Kiểm Thử Nhập Xuất Kho')
            ->set('cardSearch', 'khong-ton-tai-xyz')
            ->assertDontSee('SIT Kiểm Thử Nhập Xuất Kho');

        // Verify viewer cannot access super_admin-only SIT template
        $this->flushSession();
        $this->actingAs($this->viewerUser)
            ->get('/admin/sit-tests/sit-custom-warehouse')
            ->assertForbidden();
    }

    public function test_sit_runner_page_manages_steps_extra_fields_ctrl_v_image_paste_and_clone_retest(): void
    {
        $this->actingAs($this->adminUser);

        $sampleDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH6AsTEg0x4r7I2QAAAB1pVFh0Q29tbWVudAAAAAAAQ3JlYXRlZCB3aXRoIEdJTVBkLmUHAAAAHklEQVQoz2NgYGD4z8DAwMgwquFUA8OoBgYGBgYACokBAd67X3gAAAAASUVORK5CYII=';

        $component = Livewire::test(SitRunnerPage::class, ['templateCode' => 'sit-erp-order-sync'])
            ->assertSuccessful()
            ->assertSee('Kiểm Thử SIT Đồng Bộ Đơn Hàng & Lệnh Sản Xuất ERP');

        $initialRunId = $component->get('selectedRunId');
        $this->assertNotNull($initialRunId);

        // Add a 3rd step, add custom extra field, paste image via Ctrl+V handler, and mark FAIL
        $component
            ->call('addStep', 'Bước 3 - Kiểm tra đối soát tồn kho sau sản xuất')
            ->call('addExtraField', 2, 'Mã phiếu kho', 'WH-2026-999')
            ->call('pasteStepImage', 2, $sampleDataUrl, 'Ảnh chụp lỗi lệch tồn kho')
            ->call('setStepStatus', 2, SitTestRun::STEP_FAIL)
            ->call('saveRun');

        $run = SitTestRun::findOrFail($initialRunId);
        $this->assertSame(SitTestRun::STATUS_FAILED, $run->status);
        $this->assertCount(3, $run->steps_data);
        $this->assertSame('FAIL', $run->steps_data[2]['status']);
        $this->assertSame('WH-2026-999', $run->steps_data[2]['extra_fields'][0]['value']);
        $this->assertCount(1, $run->steps_data[2]['images']);

        $pastedImagePath = $run->steps_data[2]['images'][0]['path'];
        $this->assertTrue(Storage::disk('local')->exists($pastedImagePath));

        // Verify authenticated evidence image route serves the pasted image
        $this->get('/admin/sit-evidence/'.$initialRunId.'/'.$pastedImagePath)
            ->assertSuccessful();

        // Clone / Retest current run and mark step 3 as PASS
        $component->call('cloneCurrentRun');
        $clonedRunId = $component->get('selectedRunId');
        $this->assertNotEquals($initialRunId, $clonedRunId);

        $component
            ->set('steps.2.fields.action', 'Đối soát tồn kho')
            ->set('steps.2.fields.expected', 'Số lượng khớp')
            ->call('setStepStatus', 2, SitTestRun::STEP_PASS)
            ->call('saveRun');

        $clonedRun = SitTestRun::findOrFail($clonedRunId);
        $this->assertSame(SitTestRun::STATUS_PASSED, $clonedRun->status);
        $this->assertCount(3, $clonedRun->steps_data);
        $this->assertCount(1, $clonedRun->steps_data[2]['images']);
    }

    public function test_sit_document_exporter_embeds_real_images_into_xlsx_docx_and_html(): void
    {
        $template = SitTemplate::where('template_code', 'sit-erp-order-sync')->firstOrFail();
        $run = $template->testRuns()->firstOrFail();
        $exporter = app(SitDocumentExporter::class);

        // 1. Verify Excel (.xlsx) export embeds binary image and drawing XML
        $xlsxPath = $exporter->exportExcel($template, $run);
        $this->assertFileExists($xlsxPath);

        $xlsxZip = new ZipArchive;
        $this->assertTrue($xlsxZip->open($xlsxPath) === true);
        $sheetXml = (string) $xlsxZip->getFromName('xl/worksheets/sheet1.xml');
        $drawingXml = (string) $xlsxZip->getFromName('xl/drawings/drawing1.xml');
        $embeddedExcelImg = $xlsxZip->getFromName('xl/media/image1.png');
        $xlsxZip->close();

        $this->assertStringContainsString('Tạo hợp đồng xuất khẩu mới tại chi nhánh Miền Bắc', $sheetXml);
        $this->assertStringContainsString('Mã chứng từ: HD-N-026', $sheetXml);
        $this->assertStringContainsString('<drawing r:id="rIdDrawing1"/>', $sheetXml);
        $this->assertStringContainsString('<xdr:oneCellAnchor>', $drawingXml);
        $this->assertStringContainsString('r:embed="rIdImg1"', $drawingXml);
        $this->assertNotFalse($embeddedExcelImg);
        $this->assertStringStartsWith("\x89PNG", (string) $embeddedExcelImg);

        // 2. Verify Word (.docx) export embeds binary image and inline drawing XML
        $docxPath = $exporter->exportWord($template, $run);
        $this->assertFileExists($docxPath);

        $docxZip = new ZipArchive;
        $this->assertTrue($docxZip->open($docxPath) === true);
        $docXml = (string) $docxZip->getFromName('word/document.xml');
        $embeddedWordImg = $docxZip->getFromName('word/media/image1.png');
        $docxZip->close();

        $this->assertStringContainsString('BIÊN BẢN KIỂM THỬ TÍCH HỢP HỆ THỐNG (SIT)', $docXml);
        $this->assertStringContainsString('Màn hình xác nhận tạo hợp đồng HD-N-026', $docXml);
        $this->assertStringContainsString('<a:blip r:embed="rIdImg1"/>', $docXml);
        $this->assertNotFalse($embeddedWordImg);
        $this->assertStringStartsWith("\x89PNG", (string) $embeddedWordImg);

        // 3. Verify HTML export embeds base64 image
        $htmlPath = $exporter->exportHtml($template, $run);
        $this->assertFileExists($htmlPath);
        $htmlContent = File::get($htmlPath);
        $this->assertStringContainsString('data:image/png;base64,', $htmlContent);
        $this->assertStringContainsString('Màn hình xác nhận tạo hợp đồng HD-N-026', $htmlContent);

        File::delete([$xlsxPath, $docxPath, $htmlPath]);
    }
}
