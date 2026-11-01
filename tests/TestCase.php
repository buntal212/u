<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        TestDatabaseSafety::assertSafe();

        return parent::setUpTraits();
    }
}
