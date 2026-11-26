<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Expeditions\Rest;

use Brain\Monkey\Functions;
use EMS\Data\Expedition_Repository;
use EMS\Data\Team_Member_Repository;
use EMS\Data\Team_Repository;
use EMS\Modules\Expeditions\Rest\TeamController;
use EMS\Modules\Expeditions\Services\TeamSolverService;
use EMS\Modules\Expeditions\Services\WishlistMatcherService;
use EMS\Modules\Expeditions\Services\WishlistPreferenceRepository;
use EMS\Tests\EMSTestCase;

class TeamControllerSolverTest extends EMSTestCase {

	public function test_registers_preference_and_solver_routes_with_manage_options(): void {
		if ( ! class_exists( 'WP_REST_Server' ) ) {
			eval( 'class WP_REST_Server { const READABLE = "GET"; const CREATABLE = "POST"; const EDITABLE = "POST, PUT, PATCH"; const DELETABLE = "DELETE"; }' );
		}

		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$registered ): void {
				$registered[ $namespace . $route ] = $args;
			}
		);

		$controller = new TeamController(
			\Mockery::mock( Expedition_Repository::class ),
			\Mockery::mock( Team_Repository::class ),
			\Mockery::mock( Team_Member_Repository::class )
		);
		$controller->register_routes();

		$expected = array(
			'ems/v1/preferences/parse',
			'ems/v1/preferences/queue',
			'ems/v1/preferences/candidates',
			'ems/v1/preferences/genders',
			'ems/v1/preferences/(?P<source_signup_id>\d+)',
			'ems/v1/teams/solver/propose',
			'ems/v1/teams/solver/apply',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $registered, "Missing REST route: {$route}" );
			$this->assertSame( array( $controller, 'check_permission' ), $registered[ $route ]['permission_callback'] );
		}

		Functions\when( 'current_user_can' )->alias(
			static fn( string $cap ): bool => $cap === 'manage_options'
		);
		$this->assertTrue( $controller->check_permission() );
	}

	public function test_solver_and_preference_endpoints_delegate_to_services(): void {
		$prefRepo = \Mockery::mock( WishlistPreferenceRepository::class );
		$matcher  = \Mockery::mock( WishlistMatcherService::class );
		$solver   = \Mockery::mock( TeamSolverService::class );

		$matcher->shouldReceive( 'parseAll' )->once()->andReturn(
			array(
				'parsed_count'       => 5,
				'skipped_count'      => 2,
				'needs_review_count' => 1,
			)
		);
		$matcher->shouldReceive( 'getQueue' )->once()->andReturn(
			array(
				'items'   => array(),
				'summary' => array( 'total' => 0, 'needs_review' => 0, 'auto_matched' => 0, 'confirmed' => 0, 'ignored' => 0 ),
			)
		);
		$matcher->shouldReceive( 'searchCandidates' )->with( 12, 'Lucy' )->once()->andReturn(
			array( array( 'signup_id' => 15, 'explorer_name' => 'Lucy Hann' ) )
		);
		$matcher->shouldReceive( 'saveDecisions' )->with( 12, array( array( 'id' => 1, 'target_signup_id' => 15, 'preference_type' => 'prefer', 'match_status' => 'confirmed' ) ) )->once()->andReturn(
			array( 'updated' => 1, 'items' => array() )
		);
		$solver->shouldReceive( 'propose' )->once()->andReturn(
			array( 'scope' => 'bucket', 'buckets_solved' => array( 'silver|foot' ), 'proposed_teams' => array() )
		);
		$solver->shouldReceive( 'apply' )->once()->andReturn(
			array( 'created_teams' => 2, 'created_members' => 10, 'cleared_draft_teams' => 0, 'preserved_notified_teams' => 0 )
		);

		$controller = new TeamController(
			\Mockery::mock( Expedition_Repository::class ),
			\Mockery::mock( Team_Repository::class ),
			\Mockery::mock( Team_Member_Repository::class ),
			null,
			$prefRepo,
			$matcher,
			$solver
		);

		$reqParse = new \WP_REST_Request();
		$this->assertSame( 5, $controller->parse_preferences( $reqParse )->get_data()['parsed_count'] );

		$reqQueue = new \WP_REST_Request();
		$this->assertArrayHasKey( 'summary', $controller->get_preferences_queue( $reqQueue )->get_data() );

		$reqCand = new \WP_REST_Request();
		$reqCand->set_param( 'source_signup_id', 12 );
		$reqCand->set_param( 'q', 'Lucy' );
		$this->assertCount( 1, $controller->get_preference_candidates( $reqCand )->get_data()['candidates'] );

		$reqPut = new \WP_REST_Request();
		$reqPut->set_param( 'source_signup_id', 12 );
		$reqPut->set_body_params(
			array(
				'decisions' => array(
					array( 'id' => 1, 'target_signup_id' => 15, 'preference_type' => 'prefer', 'match_status' => 'confirmed' ),
				),
			)
		);
		$this->assertSame( 1, $controller->update_preference_decisions( $reqPut )->get_data()['updated'] );

		$reqPropose = new \WP_REST_Request();
		$this->assertSame( 'bucket', $controller->propose_teams( $reqPropose )->get_data()['scope'] );

		$reqApply = new \WP_REST_Request();
		$this->assertSame( 2, $controller->apply_proposed_teams( $reqApply )->get_data()['created_teams'] );
	}

	public function test_gender_endpoints_batch_and_single_update(): void {
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $val ) use ( &$options ): bool {
				$options[ $key ] = $val;
				return true;
			}
		);

		$prefRepo = new WishlistPreferenceRepository();
		$matcher  = \Mockery::mock( WishlistMatcherService::class );
		$matcher->shouldReceive( 'saveDecisions' )
			->with( 12, array(), 'female' )
			->once()
			->andReturn( array( 'updated' => 0, 'items' => array() ) );

		$controller = new TeamController(
			\Mockery::mock( Expedition_Repository::class ),
			\Mockery::mock( Team_Repository::class ),
			\Mockery::mock( Team_Member_Repository::class ),
			null,
			$prefRepo,
			$matcher,
			\Mockery::mock( TeamSolverService::class )
		);

		$reqBatch = new \WP_REST_Request();
		$reqBatch->set_body_params(
			array(
				'genders' => array(
					12 => 'male',
					13 => 'female',
				),
			)
		);
		$resBatch = $controller->update_preference_genders( $reqBatch );
		$this->assertSame( 200, $resBatch->get_status() );
		$this->assertSame( 2, $resBatch->get_data()['updated'] );
		$this->assertSame( array( 12 => 'male', 13 => 'female' ), $options['ems_explorer_genders'] );

		$reqSingle = new \WP_REST_Request();
		$reqSingle->set_param( 'source_signup_id', 12 );
		$reqSingle->set_body_params(
			array(
				'decisions' => array(),
				'gender'    => 'female',
			)
		);
		$resSingle = $controller->update_preference_decisions( $reqSingle );
		$this->assertSame( 200, $resSingle->get_status() );
	}
}
