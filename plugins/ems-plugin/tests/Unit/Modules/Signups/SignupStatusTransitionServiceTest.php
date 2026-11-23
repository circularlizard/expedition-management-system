<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Modules\Signups;

use Brain\Monkey\Functions;
use EMS\Data\Signup_Repository;
use EMS\Modules\Signups\Services\SignupStatusTransitionService;
use EMS\Tests\EMSTestCase;
use Mockery;

class SignupStatusTransitionServiceTest extends EMSTestCase {

	public function test_participant_place_allocated_syncs_to_expeditions(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_participant_signup' )
			->once()
			->with( 10 )
			->andReturn(
				array(
					'id'           => 10,
					'scout_id'     => 555,
					'kanban_stage' => 'new',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 10, 'participant', 'place_allocated', null )
			->andReturn( true );
		$signups->shouldReceive( 'sync_participant_place_allocation_to_expeditions' )
			->once()
			->with( 10, true )
			->andReturn( array( 21, 22 ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		$svc    = new SignupStatusTransitionService( $signups );
		$result = $svc->transition_stage( 10, 'participant', 'place_allocated' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'place_allocated', $result['kanban_stage'] );
		$this->assertSame( array( 21, 22 ), $result['synced_expedition_ids'] );
	}

	public function test_participant_leaving_place_allocated_clears_expedition_flag(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_participant_signup' )
			->once()
			->with( 10 )
			->andReturn(
				array(
					'id'           => 10,
					'scout_id'     => 0,
					'kanban_stage' => 'place_allocated',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 10, 'participant', 'on_hold', null )
			->andReturn( true );
		$signups->shouldReceive( 'sync_participant_place_allocation_to_expeditions' )
			->once()
			->with( 10, false )
			->andReturn( array( 21 ) );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		$svc    = new SignupStatusTransitionService( $signups );
		$result = $svc->transition_stage( 10, 'participant', 'on_hold' );

		$this->assertIsArray( $result );
		$this->assertSame( 'on_hold', $result['kanban_stage'] );
		$this->assertSame( array( 21 ), $result['synced_expedition_ids'] );
	}

	public function test_on_team_member_added_transitions_new_to_team_allocated_including_unmatched_scout_id_zero(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->twice()
			->with( 77 )
			->andReturn(
				array(
					'id'           => 77,
					'scout_id'     => 0,
					'kanban_stage' => 'new',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 77, 'expedition', 'team_allocated', null )
			->andReturn( true );

		Functions\when( 'get_post_meta' )->justReturn( 'S1-W-A' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		$svc    = new SignupStatusTransitionService( $signups );
		$result = $svc->on_team_member_added( 900, 77, 0 );

		$this->assertIsArray( $result );
		$this->assertSame( 'team_allocated', $result['kanban_stage'] );
	}

	public function test_on_team_member_added_ignores_unallocated_pool(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldNotReceive( 'update_kanban_stage' );

		Functions\when( 'get_post_meta' )->justReturn( 'UNALLOCATED' );

		$svc    = new SignupStatusTransitionService( $signups );
		$result = $svc->on_team_member_added( 901, 77, 0 );

		$this->assertNull( $result );
	}

	public function test_on_team_member_added_preserves_protected_stages(): void {
		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->once()
			->with( 77 )
			->andReturn(
				array(
					'id'           => 77,
					'scout_id'     => 123,
					'kanban_stage' => 'cancelled',
				)
			);
		$signups->shouldNotReceive( 'update_kanban_stage' );

		Functions\when( 'get_post_meta' )->justReturn( 'S1-W-A' );

		$svc    = new SignupStatusTransitionService( $signups );
		$result = $svc->on_team_member_added( 900, 77, 123 );

		$this->assertNull( $result );
	}

	public function test_on_team_member_removed_reverts_to_new_when_no_real_teams_remain(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->with( 'SQL' )->andReturn( '0' );

		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->twice()
			->with( 77 )
			->andReturn(
				array(
					'id'           => 77,
					'scout_id'     => 0,
					'kanban_stage' => 'team_allocated',
				)
			);
		$signups->shouldReceive( 'update_kanban_stage' )
			->once()
			->with( 77, 'expedition', 'new', null )
			->andReturn( true );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		$svc    = new SignupStatusTransitionService( $signups, $wpdb );
		$result = $svc->on_team_member_removed( 77, 0 );

		$this->assertIsArray( $result );
		$this->assertSame( 'new', $result['kanban_stage'] );
	}

	public function test_on_team_member_removed_keeps_team_allocated_for_split_stage_member(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->with( 'SQL' )->andReturn( '1' );

		$signups = Mockery::mock( Signup_Repository::class );
		$signups->shouldReceive( 'get_expedition_signup' )
			->once()
			->with( 77 )
			->andReturn(
				array(
					'id'           => 77,
					'scout_id'     => 456,
					'kanban_stage' => 'team_allocated',
				)
			);
		$signups->shouldNotReceive( 'update_kanban_stage' );

		$svc    = new SignupStatusTransitionService( $signups, $wpdb );
		$result = $svc->on_team_member_removed( 77, 456 );

		$this->assertNull( $result );
	}
}
