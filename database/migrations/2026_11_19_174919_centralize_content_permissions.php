<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->json('allowed_sit_templates')->nullable();
        });

        $reports = DB::table('custom_views')->get(['view_code', 'roles']);
        $templates = DB::table('sit_templates')->get(['template_code', 'roles']);
        $reportGrants = [];
        $sitGrants = [];
        foreach ($reports as $report) {
            foreach (json_decode($report->roles ?? '[]', true) ?: [] as $role) {
                $reportGrants[$role][] = $report->view_code;
            }
        }
        foreach ($templates as $template) {
            foreach (json_decode($template->roles ?? '[]', true) ?: [] as $role) {
                $sitGrants[$role][] = $template->template_code;
            }
        }

        $existing = DB::table('role_permissions')->pluck('role_code')->all();
        foreach (array_unique(array_merge(array_keys($reportGrants), array_keys($sitGrants))) as $code) {
            if (! in_array($code, $existing, true)) {
                DB::table('role_permissions')->insert([
                    'role_code' => $code, 'role_name' => $code,
                    'is_system' => $code === 'super_admin',
                    'allowed_views' => '[]', 'allowed_sources' => '[]',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        foreach (DB::table('role_permissions')->get() as $role) {
            $oldViews = json_decode($role->allowed_views ?? '[]', true) ?: [];
            $views = array_values(array_unique(array_merge(array_intersect($oldViews, $reports->pluck('view_code')->all()), $reportGrants[$role->role_code] ?? [])));
            $sits = array_values(array_unique(array_merge(array_intersect($oldViews, $templates->pluck('template_code')->all()), $sitGrants[$role->role_code] ?? [])));
            DB::table('role_permissions')->where('id', $role->id)->update([
                'allowed_views' => json_encode(in_array('*', $oldViews, true) ? ['*'] : $views),
                'allowed_sit_templates' => json_encode(in_array('*', $oldViews, true) ? ['*'] : $sits),
            ]);
        }

        foreach (['custom_views', 'sit_templates', 'menu_groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('roles');
            });
        }
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->dropColumn('allowed_groups');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Permission consolidation is forward-only. Restore the pre-migration database backup to revert.');
    }
};
