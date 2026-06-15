<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Flexi_Column_Map;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Flexi_Column_MapTest extends EMSTestCase {

    public function test_save_validates_required_fields(): void {
        $map = [
            'expedition_code' => 'f_1',
            // team_code missing
        ];

        $mapper = new Flexi_Column_Map();
        $result = $mapper->save( $map );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'ems_missing_field', $result->get_error_code() );
    }

    public function test_save_persists_valid_map(): void {
        $map = [
            'expedition_code' => 'f_1',
            'team_code'       => 'f_2',
        ];

        $called = false;
        Functions\when( 'update_option' )->alias( function( $name, $value ) use ( &$called, $map ) {
            if ( $name === 'ems_flexirecord_column_map' && $value === $map ) {
                $called = true;
            }
        } );

        $mapper = new Flexi_Column_Map();
        $result = $mapper->save( $map );

        $this->assertTrue( $result );
        $this->assertTrue( $called, 'update_option was not called with expected arguments' );
    }

    public function test_get_returns_option(): void {
        $map = [ 'foo' => 'bar' ];
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ems_flexirecord_column_map', [] )
            ->andReturn( $map );

        $mapper = new Flexi_Column_Map();
        $this->assertEquals( $map, $mapper->get() );
    }
}
