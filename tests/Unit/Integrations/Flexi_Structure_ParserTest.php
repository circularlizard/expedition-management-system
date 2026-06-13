<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\Flexi_Structure_Parser;
use EMS\Tests\EMSTestCase;

class Flexi_Structure_ParserTest extends EMSTestCase {

    public function test_parse_returns_flat_list_of_columns(): void {
        $raw = [
            'config' => json_encode( [
                [ 'id' => 'f_1', 'name' => 'Column A' ],
                [ 'id' => 'f_2', 'name' => 'Column B' ],
            ] )
        ];

        $parser  = new Flexi_Structure_Parser();
        $columns = $parser->parse( $raw );

        $this->assertCount( 2, $columns );
        $this->assertEquals( 'f_1', $columns[0]['id'] );
        $this->assertEquals( 'Column A', $columns[0]['name'] );
    }

    public function test_parse_handles_missing_config(): void {
        $parser  = new Flexi_Structure_Parser();
        $this->assertEquals( [], $parser->parse( [] ) );
    }

    public function test_parse_handles_malformed_config(): void {
        $parser  = new Flexi_Structure_Parser();
        $this->assertEquals( [], $parser->parse( [ 'config' => 'not-json' ] ) );
    }
}
