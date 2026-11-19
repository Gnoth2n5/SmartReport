<?php

namespace Tests\Feature;

use Tests\TestCase;

class SqlPreviewPresentationTest extends TestCase
{
    public function test_preview_shows_actual_parameters_one_summary_and_readable_values(): void
    {
        $html = $this->view('filament.components.sql-preview-modal', [
            'columns' => ['name', 'enabled', 'amount'],
            'rows' => [['name' => '<script>test</script>', 'enabled' => false, 'amount' => null]],
            'latencyMs' => 12.5,
            'appliedParams' => ['Plant' => 'NORTH', 'StartDate' => null],
        ]);
        $html->assertSee('1 dòng · 3 cột · 12.50 ms')->assertSee('@Plant = NORTH')->assertSee('@StartDate = NULL')
            ->assertDontSee('{{ $k }}')->assertDontSee('Danh sách cột:')->assertSee('false')->assertSee('—');
        $html->assertSee('<script>test</script>')->assertDontSee('<script>test</script>', false);
    }
}
