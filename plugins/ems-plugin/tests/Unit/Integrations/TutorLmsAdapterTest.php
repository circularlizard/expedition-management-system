<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Integrations;

use Brain\Monkey\Functions;
use EMS\Contracts\DTOs\TrainingStatusDTO;
use EMS\Integrations\TutorLms\TutorLmsAdapter;
use EMS\Tests\EMSTestCase;

class TutorLmsAdapterTest extends EMSTestCase {
	public function test_graceful_degradation_to_unavailable_when_tutor_lms_is_absent(): void {
		// When Tutor LMS is inactive (activeChecker returns false)
		$adapter = new TutorLmsAdapter( fn() => false );

		$this->assertFalse( $adapter->isTutorActive() );

		$progress = $adapter->getExplorerCourseProgress( 12345 );

		$this->assertInstanceOf( TrainingStatusDTO::class, $progress );
		$this->assertFalse( $progress->isComplete );
		$this->assertSame( 'unavailable', $progress->status );
		$this->assertSame( 0, $progress->completedModules );
		$this->assertSame( 0, $progress->totalModules );
		$this->assertNull( $progress->lastCompletedDate );
	}

	public function test_returns_not_started_when_tutor_active_but_user_not_enrolled(): void {
		$adapter = new TutorLmsAdapter( fn() => true );

		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( 10 );
		Functions\when( 'get_posts' )->justReturn( [] );

		$progress = $adapter->getExplorerCourseProgress( 999 );

		$this->assertInstanceOf( TrainingStatusDTO::class, $progress );
		$this->assertFalse( $progress->isComplete );
		$this->assertSame( 'not_started', $progress->status );
	}

	public function test_returns_completed_when_user_has_completed_course(): void {
		$adapter = new TutorLmsAdapter( fn() => true );

		Functions\when( 'get_users' )->justReturn( [ 42 ] );
		Functions\when( 'get_option' )->justReturn( 10 );
		Functions\when( 'get_posts' )->alias( function( $args ) {
			if ( ( $args['post_type'] ?? '' ) === 'lesson' ) {
				return [ 101, 102, 103 ];
			}
			return [];
		} );
		Functions\when( 'get_user_meta' )->alias( function( $userId, $key ) {
			if ( $key === '_tutor_completed_course_10' ) {
				return '2026-05-20 14:00:00';
			}
			return null;
		} );

		$progress = $adapter->getExplorerCourseProgress( 123 );

		$this->assertTrue( $progress->isComplete );
		$this->assertSame( 'completed', $progress->status );
		$this->assertSame( 3, $progress->completedModules );
		$this->assertSame( 3, $progress->totalModules );
		$this->assertSame( '2026-05-20 14:00:00', $progress->lastCompletedDate );
	}

	public function test_returns_in_progress_when_enrolled(): void {
		$adapter = new TutorLmsAdapter( fn() => true );

		Functions\when( 'get_users' )->justReturn( [ 42 ] );
		Functions\when( 'get_option' )->justReturn( 10 );
		Functions\when( 'get_posts' )->alias( function( $args ) {
			if ( ( $args['post_type'] ?? '' ) === 'lesson' ) {
				return [ 101, 102, 103, 104 ];
			}
			if ( ( $args['post_type'] ?? '' ) === 'tutor_enrolled' ) {
				return [ 501 ];
			}
			return [];
		} );
		Functions\when( 'get_user_meta' )->alias( function( $userId, $key ) {
			if ( $key === '_tutor_completed_course_10' ) {
				return false;
			}
			if ( $key === '_tutor_completed_lessons_count_10' ) {
				return 2;
			}
			return null;
		} );

		$progress = $adapter->getExplorerCourseProgress( 123 );

		$this->assertFalse( $progress->isComplete );
		$this->assertSame( 'in_progress', $progress->status );
		$this->assertSame( 2, $progress->completedModules );
		$this->assertSame( 4, $progress->totalModules );
	}

	public function test_catches_unhandled_exceptions_and_returns_unavailable(): void {
		$adapter = new TutorLmsAdapter( fn() => true );

		Functions\when( 'get_users' )->alias( function() {
			throw new \RuntimeException( 'Database connection dropped' );
		} );

		$progress = $adapter->getExplorerCourseProgress( 777 );

		$this->assertSame( 'unavailable', $progress->status );
		$this->assertFalse( $progress->isComplete );
	}
}
