<?php
namespace EMS\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class EMSTestCase extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
