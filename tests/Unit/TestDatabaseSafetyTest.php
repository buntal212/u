<?php

namespace Tests\Unit;

use App\Support\TestDatabaseSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_approved_test_configuration_is_accepted(): void
    {
        $this->assertNull(TestDatabaseSafety::assertConfigurationIsSafe($this->safeConfiguration()));
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_is_rejected(array $changes): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseSafety::assertConfigurationIsSafe(array_merge($this->safeConfiguration(), $changes));
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'wrong environment' => [['app_env' => 'local']],
            'wrong default connection' => [['default_connection' => 'sqlite']],
            'wrong database driver' => [['connection_driver' => 'sqlite']],
            'unapproved hostname' => [['host' => 'db']],
            'wrong port' => [['port' => '3307']],
            'wrong database name' => [['database' => 'uih_development']],
            'wrong database user' => [['username' => 'uih_dev']],
            'wrong database password' => [['password' => 'development-password']],
            'unapproved database socket' => [['socket' => '/var/run/mysqld/mysqld.sock']],
            'database URL override' => [['url' => 'mysql://example.invalid/uih_test']],
        ];
    }

    private function safeConfiguration(): array
    {
        return [
            'app_env' => 'testing',
            'default_connection' => 'mysql',
            'connection_driver' => 'mysql',
            'host' => 'test-db',
            'port' => '3306',
            'database' => 'uih_test',
            'username' => 'uih_test',
            'password' => 'uih-test-only-password',
            'socket' => '',
            'url' => '',
        ];
    }
}
