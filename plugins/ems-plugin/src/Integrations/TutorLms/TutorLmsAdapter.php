<?php
declare(strict_types=1);

namespace EMS\Integrations\TutorLms;

use EMS\Contracts\DTOs\TrainingStatusDTO;
use EMS\Contracts\TrainingComplianceInterface;
use EMS\Core\ExplorerUserResolver;

class TutorLmsAdapter implements TrainingComplianceInterface {
	/**
	 * @var callable|null
	 */
	private $activeChecker;

	/**
	 * @param callable|null $activeChecker Optional checker returning bool for active status
	 */
	public function __construct( ?callable $activeChecker = null ) {
		$this->activeChecker = $activeChecker;
	}

	/**
	 * Check whether Tutor LMS is active.
	 */
	public function isTutorActive(): bool {
		if ( $this->activeChecker !== null ) {
			return (bool) call_user_func( $this->activeChecker );
		}

		return function_exists( 'tutor_utils' ) || defined( 'TUTOR_VERSION' );
	}

	/**
	 * Retrieves the training course progress for a given explorer (scoutId).
	 * If Tutor LMS is deactivated/unavailable, gracefully returns TrainingStatusDTO::unavailable().
	 *
	 * @param int $scoutId
	 * @return TrainingStatusDTO
	 */
	public function getExplorerCourseProgress( int $scoutId ): TrainingStatusDTO {
		if ( ! $this->isTutorActive() ) {
			return TrainingStatusDTO::unavailable();
		}

		try {
			return $this->queryCourseProgress( $scoutId );
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[EMS TutorLmsAdapter] Failed to query course progress for scout %d: %s', $scoutId, $e->getMessage() ) );
			return TrainingStatusDTO::unavailable();
		}
	}

	/**
	 * Internal query logic for Tutor LMS progress.
	 */
	protected function queryCourseProgress( int $scoutId ): TrainingStatusDTO {
		// 1. Resolve user ID for scoutId using canonical ems_scout_ids (with legacy fallback)
		$userId = ExplorerUserResolver::findWpUserIdByScoutId( $scoutId ) ?? 0;

		// 2. Identify target training course
		$courseId = (int) get_option( 'ems_tutor_training_course_id', 0 );
		if ( $courseId === 0 ) {
			$courses = get_posts( [
				'post_type'      => 'courses',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			] );
			if ( ! empty( $courses ) ) {
				$courseId = (int) $courses[0];
			}
		}

		// 3. Count total modules/lessons in course
		$totalModules = 0;
		if ( $courseId > 0 ) {
			$lessons = get_posts( [
				'post_type'      => 'lesson',
				'post_parent'    => $courseId,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
			$totalModules = count( $lessons );
		}

		// If no user associated with this scout yet
		if ( $userId === 0 || $courseId === 0 ) {
			return new TrainingStatusDTO(
				isComplete: false,
				status: 'not_started',
				completedModules: 0,
				totalModules: $totalModules,
				lastCompletedDate: null
			);
		}

		// 4. Check course completion
		$isCompleted = false;
		$lastCompletedDate = null;

		$metaCompleted = get_user_meta( $userId, '_tutor_completed_course_' . $courseId, true );
		if ( ! empty( $metaCompleted ) ) {
			$isCompleted = true;
			if ( is_string( $metaCompleted ) ) {
				$lastCompletedDate = $metaCompleted;
			}
		}

		if ( ! $isCompleted && function_exists( 'tutor_utils' ) ) {
			$utils = tutor_utils();
			if ( is_object( $utils ) && method_exists( $utils, 'is_completed_course' ) ) {
				$isCompleted = (bool) $utils->is_completed_course( $courseId, $userId );
			}
		}

		if ( $isCompleted ) {
			return new TrainingStatusDTO(
				isComplete: true,
				status: 'completed',
				completedModules: $totalModules > 0 ? $totalModules : 1,
				totalModules: $totalModules > 0 ? $totalModules : 1,
				lastCompletedDate: $lastCompletedDate ?? current_time( 'mysql' )
			);
		}

		// 5. Check enrollment for in_progress vs not_started
		$enrollments = get_posts( [
			'post_type'      => 'tutor_enrolled',
			'author'         => $userId,
			'post_parent'    => $courseId,
			'post_status'    => 'completed',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		if ( ! empty( $enrollments ) ) {
			$completedLessons = 0;
			$metaLessons = get_user_meta( $userId, '_tutor_completed_lessons_count_' . $courseId, true );
			if ( is_numeric( $metaLessons ) ) {
				$completedLessons = (int) $metaLessons;
			}

			return new TrainingStatusDTO(
				isComplete: false,
				status: 'in_progress',
				completedModules: $completedLessons,
				totalModules: $totalModules,
				lastCompletedDate: null
			);
		}

		return new TrainingStatusDTO(
			isComplete: false,
			status: 'not_started',
			completedModules: 0,
			totalModules: $totalModules,
			lastCompletedDate: null
		);
	}
}
