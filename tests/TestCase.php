<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?\PDO $testLease = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $database = $app['config']->get('database.connections.mysql');
        self::assertSafeDatabaseConfiguration($database, $app['config']->get('database.default'), $app->environment('testing'), $app->configurationIsCached());
        if (self::$testLease === null) {
            $connection = new \PDO('mysql:host=mysql_test;port='.($database['port'] ?? 3306).';dbname=beautybook_test', $database['username'], $database['password']);
            $statement = $connection->query("SELECT GET_LOCK('beautybook_test_suite', 60)");
            if ((int) $statement->fetchColumn() !== 1) throw new \RuntimeException('Another test suite owns the test database.');
            // A dedicated connection survives application refreshes and DDL.
            self::$testLease = $connection;
        }

        return $app;
    }

    public static function assertSafeDatabaseConfiguration(array $database, string $driver, bool $testing, bool $cached): void
    {
        if ($cached || ! $testing || $driver !== 'mysql'
            || ($database['database'] ?? null) !== 'beautybook_test'
            || ($database['host'] ?? null) !== 'mysql_test'
            || ($database['username'] ?? null) !== 'beautybook_test'
            || ! empty($database['url'])) {
            throw new \RuntimeException('Refusing database tests outside the uncached, isolated mysql_test/beautybook_test configuration.');
        }
    }
}
