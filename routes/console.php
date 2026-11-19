<?php

use App\Services\Database\ExternalTestDatabaseProvisioner;
use App\Services\DuckDb\SnapshotManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('smartreport:secure-sit-evidence', function (): void {
    $source = Storage::disk('public')->path('sit-evidence');
    if (! is_dir($source)) {
        $this->info('No public SIT evidence to move.');

        return;
    }
    $count = 0;
    foreach (File::allFiles($source) as $file) {
        $target = Storage::disk('local')->path('sit-evidence/'.$file->getRelativePathname());
        File::ensureDirectoryExists(dirname($target));
        if (is_file($target)) {
            if (hash_file('sha256', $file->getPathname()) !== hash_file('sha256', $target)) {
                throw new RuntimeException('Different evidence files have the same path. No file was overwritten.');
            }
            if (! File::delete($file->getPathname())) {
                throw new RuntimeException('Could not remove the public copy of SIT evidence.');
            }
        } else {
            if (! File::move($file->getPathname(), $target)) {
                throw new RuntimeException('Could not move SIT evidence into private storage.');
            }
        }
        $count++;
    }
    $this->info("Moved {$count} evidence files into private storage.");
})->purpose('Move existing SIT evidence out of public storage without changing saved references');

Schedule::call(fn (): int => app(SnapshotManager::class)->cleanExpiredSnapshots())
    ->name('smartreport:clean-expired-snapshots')
    ->everyFiveMinutes();

Artisan::command('smartreport:provision-test-databases', function (ExternalTestDatabaseProvisioner $provisioner): void {
    $this->table(['Source', 'Rows'], collect($provisioner->provisionAll())->map(fn (int $rows, string $source): array => [$source, $rows])->all());
})->purpose('Reset the sample tables in the four local Docker test databases');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
