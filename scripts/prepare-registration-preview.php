<?php

// Destructive only within the explicitly acknowledged isolated test database.
if (getenv('REGISTRATION_TEST_DATABASE') !== 'beautybook_test') {
    throw new RuntimeException('Explicit REGISTRATION_TEST_DATABASE=beautybook_test acknowledgement required.');
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = config('database.connections.mysql');
Tests\TestCase::assertSafeDatabaseConfiguration($database, config('database.default'), $app->environment('testing'), $app->configurationIsCached());
$lease = new PDO('mysql:host=mysql_test;port='.($database['port'] ?? 3306).';dbname=beautybook_test', $database['username'], $database['password']);
if ((int) $lease->query("SELECT GET_LOCK('beautybook_test_suite', 0)")->fetchColumn() !== 1) {
    throw new RuntimeException('A test suite is still using this database.');
}
try {
    $status = Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    if ($status !== 0) throw new RuntimeException('Test fixture preparation failed.');
} finally {
    $lease->query("SELECT RELEASE_LOCK('beautybook_test_suite')");
}
