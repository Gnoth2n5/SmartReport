<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $testStoragePath = null;

    public function createApplication(): Application
    {
        $this->testStoragePath ??= sys_get_temp_dir().'/smartreport-tests-'.bin2hex(random_bytes(12));
        $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $this->testStoragePath;
        $_ENV['SMARTREPORT_SNAPSHOT_DIR'] = $this->testStoragePath.'/app/snapshots';
        $_ENV['DUCKDB_BINARY'] = dirname(__DIR__).'/storage/bin/duckdb';

        foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            (new Filesystem)->ensureDirectoryExists($this->testStoragePath.'/'.$directory);
        }

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->testStoragePath !== null) {
                (new Filesystem)->deleteDirectory($this->testStoragePath);
            }
        }
    }
}
