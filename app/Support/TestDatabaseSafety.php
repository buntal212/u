<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TestDatabaseSafety
{
    private const TEST_PASSWORD = 'uih-test-only-password';

    /** @param array<string, mixed> $config */
    public static function assertConfigurationIsSafe(array $config): void
    {
        $expected = [
            'app_env' => 'testing',
            'default_connection' => 'mysql',
            'connection_driver' => 'mysql',
            'host' => 'test-db',
            'port' => '3306',
            'database' => 'uih_test',
            'username' => 'uih_test',
            'password' => self::TEST_PASSWORD,
            'socket' => '',
        ];

        foreach ($expected as $key => $value) {
            if (($config[$key] ?? null) !== $value) {
                throw new RuntimeException("Unsafe test database configuration: {$key} is not approved.");
            }
        }

        if (trim((string) ($config['url'] ?? '')) !== '') {
            throw new RuntimeException('Unsafe test database configuration: DB_URL must be empty.');
        }
    }

    public static function assertSafe(): void
    {
        self::assertConfigurationIsSafe([
            'app_env' => app()->environment(),
            'default_connection' => config('database.default'),
            'connection_driver' => config('database.connections.mysql.driver'),
            'host' => config('database.connections.mysql.host'),
            'port' => (string) config('database.connections.mysql.port'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'socket' => config('database.connections.mysql.unix_socket'),
            'url' => config('database.connections.mysql.url'),
        ]);

        $identity = DB::selectOne(
            'SELECT DATABASE() AS database_name, @@port AS port, VERSION() AS version, @@default_storage_engine AS engine'
        );

        if (! $identity
            || $identity->database_name !== 'uih_test'
            || (string) $identity->port !== '3306'
            || ! str_starts_with((string) $identity->version, '8.0.40')
            || strtoupper((string) $identity->engine) !== 'INNODB') {
            throw new RuntimeException('Unsafe test database identity: connected MySQL instance does not match the disposable test target.');
        }
    }
}
