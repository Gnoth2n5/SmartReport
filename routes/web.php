<?php

use App\Services\Export\SitDocumentExporter;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/sit-evidence/{path}', function (string $path, SitDocumentExporter $exporter) {
    $absPath = $exporter->resolveImagePath($path);
    abort_if($absPath === null || ! str_starts_with(realpath($absPath) ?: '', realpath(storage_path('app/public')) ?: ''), 404);

    return response()->file($absPath);
})->where('path', '.*')->middleware(['web', 'auth'])->name('sit.evidence.image');
