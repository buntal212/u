<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('uih:verify-test-database', function (): void {
    TestDatabaseSafety::assertSafe();
    $this->info('The disposable UIH test database is safe.');
})->purpose('Verify the isolated disposable test database');
