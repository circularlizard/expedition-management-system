<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Shortcode_Boundary;
use EMS\Tests\EMSTestCase;
use Mockery;

class Shortcode_BoundaryTest extends EMSTestCase {

	public function test_execute_returns_callback_output_on_success(): void {
		$output = Shortcode_Boundary::execute(
			function ( $atts, $content, $shortcode ) {
				return "<p>Rendered {$shortcode} with {$atts['key']}</p>";
			},
			array( 'key' => 'value' ),
			null,
			'ems_test_shortcode'
		);

		$this->assertSame( '<p>Rendered ems_test_shortcode with value</p>', $output );
	}

	public function test_execute_catches_exception_and_returns_error_boundary_html(): void {
		global $wpdb;

		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->prefix = 'wp_';

		$auditLogged = false;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_ems_audit_logs',
				Mockery::on( function ( $data ) use ( &$auditLogged ) {
					if ( isset( $data['action'] ) && $data['action'] === 'shortcode_error:ems_broken_shortcode' ) {
						$auditLogged = true;
					}
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( true );

		$output = Shortcode_Boundary::execute(
			function () {
				throw new \RuntimeException( 'Shortcode fatal render error' );
			},
			array(),
			null,
			'ems_broken_shortcode'
		);

		$this->assertSame(
			'<div class="ems-error-boundary ems-shortcode-error"><p>This section of the expedition portal is temporarily unavailable.</p></div>',
			$output
		);
		$this->assertTrue( $auditLogged );
	}
}
