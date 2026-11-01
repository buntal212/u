<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseConnectionTest extends TestCase
{
    public function test_laravel_storage_uses_the_disposable_test_container_path(): void
    {
        $this->assertSame(sys_get_temp_dir().'/uih-test-storage', storage_path());
    }

    public function test_mysql_test_database_is_version_8_0_40_and_uses_innodb(): void
    {
        $identity = DB::selectOne(
            'SELECT DATABASE() AS database_name, VERSION() AS version, @@default_storage_engine AS engine'
        );

        $this->assertSame('uih_test', $identity->database_name);
        $this->assertStringStartsWith('8.0.40', $identity->version);
        $this->assertSame('InnoDB', $identity->engine);
    }
}
