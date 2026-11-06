<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestDatabaseSafetyTest extends TestCase
{
    private const CONNECTION = ['database' => 'beautybook_test', 'host' => 'mysql_test', 'username' => 'beautybook_test', 'url' => ''];

    public function test_the_explicit_uncached_test_connection_is_allowed(): void
    {
        \Tests\TestCase::assertSafeDatabaseConfiguration(self::CONNECTION, 'mysql', true, false);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConnections')]
    public function test_unsafe_configuration_is_rejected_before_database_refresh(array $connection, string $driver, bool $testing, bool $cached): void
    {
        $this->expectException(\RuntimeException::class);
        \Tests\TestCase::assertSafeDatabaseConfiguration($connection, $driver, $testing, $cached);
    }

    public static function unsafeConnections(): array
    {
        return [
            'demo database' => [array_replace(self::CONNECTION, ['database' => 'beautybook_php']), 'mysql', true, false],
            'demo host' => [array_replace(self::CONNECTION, ['host' => 'mysql']), 'mysql', true, false],
            'demo account' => [array_replace(self::CONNECTION, ['username' => 'beautybook']), 'mysql', true, false],
            'URL override' => [array_replace(self::CONNECTION, ['url' => 'mysql://demo.invalid/beautybook_php']), 'mysql', true, false],
            'cached configuration' => [self::CONNECTION, 'mysql', true, true],
            'local environment' => [self::CONNECTION, 'mysql', false, false],
            'wrong driver' => [self::CONNECTION, 'sqlite', true, false],
        ];
    }
}
