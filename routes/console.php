<?php

use App\Services\Database\ExternalTestDatabaseProvisioner;
use App\Services\DuckDb\SnapshotManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn (): int => app(SnapshotManager::class)->cleanExpiredSnapshots())
    ->name('smartreport:clean-expired-snapshots')
    ->everyFiveMinutes();

Artisan::command('smartreport:provision-test-databases', function (ExternalTestDatabaseProvisioner $provisioner): void {
    $this->table(['Source', 'Rows'], collect($provisioner->provisionAll())->map(fn (int $rows, string $source): array => [$source, $rows])->all());
})->purpose('Reset the sample tables in the four local Docker test databases');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
