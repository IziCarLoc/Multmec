<?php

namespace Tests;

use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Os ajustes ficam em memória durante o pedido; entre um teste e outro o banco é outro.
        Settings::flush();
    }
}
