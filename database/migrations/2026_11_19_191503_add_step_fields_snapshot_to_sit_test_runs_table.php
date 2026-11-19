<?php

use App\Models\SitTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sit_test_runs', function (Blueprint $table): void {
            $table->json('step_fields')->nullable();
            $table->string('template_version', 50)->nullable();
        });
        $templates = DB::table('sit_templates')->get()->keyBy('id');
        foreach (DB::table('sit_test_runs')->get() as $run) {
            $template = $templates->get($run->sit_template_id);
            $fields = json_decode($template->step_fields ?? 'null', true) ?: SitTemplate::defaultStepFields();
            $keys = array_column($fields, 'key');
            foreach (json_decode($run->steps_data ?? '[]', true) ?: [] as $step) {
                foreach (array_keys($step['fields'] ?? []) as $key) {
                    if (! in_array($key, $keys, true)) {
                        $fields[] = ['key' => $key, 'label' => $key, 'type' => 'textarea', 'required' => false];
                        $keys[] = $key;
                    }
                }
            }
            DB::table('sit_test_runs')->where('id', $run->id)->update(['step_fields' => json_encode($fields), 'template_version' => $template->version ?? '1.0.0']);
        }
        foreach ($templates as $template) {
            $steps = json_decode($template->default_steps ?? '[]', true) ?: [];
            foreach ($steps as &$step) {
                $step['uid'] ??= 'step_'.Str::lower(Str::random(8));
                $step['fields'] ??= [];
                foreach ($step as $key => $value) {
                    if (! in_array($key, ['uid', 'step_no', 'title', 'status', 'fields', 'extra_fields', 'images'], true)) {
                        $step['fields'][$key] = $value;
                    }
                }
            }
            unset($step);
            DB::table('sit_templates')->where('id', $template->id)->update(['default_steps' => json_encode($steps)]);
        }
    }

    public function down(): void
    {
        Schema::table('sit_test_runs', function (Blueprint $table): void {
            $table->dropColumn(['step_fields', 'template_version']);
        });
    }
};
