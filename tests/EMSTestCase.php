<?php
namespace EMS\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EMSTestCase extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs( [ 'delete_transient', 'get_transient' ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
