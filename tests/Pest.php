<?php

declare(strict_types=1);

/**
 * Pest 配置入口。所有 tests/Feature/** 默认 extends TestCase(Testbench Laravel 环境)。
 */

use Mooeen\Scaffold\Tests\TestCase;

uses(TestCase::class)->in('Feature');
