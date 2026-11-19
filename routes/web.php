<?php

use App\Models\RolePermission;
use App\Models\SitTestRun;
use App\Models\User;
use App\Services\Export\SitDocumentExporter;
use App\Services\Navigation\DynamicSidebarBuilder;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/sit-evidence/{run}/{path}', function (SitTestRun $run, string $path, SitDocumentExporter $exporter, DynamicSidebarBuilder $sidebar) {
    $template = $run->template;
    abort_unless($template?->is_active, 404);
    $user = User::current() ?? abort(403);
    $permissions = RolePermission::whereIn('role_code', $user->roles ?? [])->get();
    abort_unless($sidebar->canUserAccessSitTemplate($user, $template, $permissions), 403);
    $images = collect($run->steps_data ?? [])->flatMap(fn (array $step): array => $step['images'] ?? []);
    abort_unless($images->contains(fn (array $image): bool => ($image['path'] ?? '') === $path), 404);
    $absPath = $exporter->resolveImagePath($path);
    abort_if($absPath === null, 404);

    return response()->file($absPath);
})->where('path', '.*')->middleware('auth')->name('sit.evidence.image');
