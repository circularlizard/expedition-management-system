<?php
declare(strict_types=1);

namespace EMS\Tests\Unit\Core;

use Brain\Monkey\Functions;
use EMS\Core\ExplorerUserResolver;
use EMS\Tests\EMSTestCase;

class ExplorerUserResolverTest extends EMSTestCase {

	public function test_get_scout_ids_prefers_plural_meta(): void {
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $userId, string $key ) {
				if ( $userId === 10 && $key === 'ems_scout_ids' ) {
					return array( 1001, '1002' );
				}
				return '';
			}
		);

		$this->assertSame( array( 1001, 1002 ), ExplorerUserResolver::getScoutIdsForUser( 10 ) );
	}

	public function test_get_scout_ids_falls_back_to_legacy_singular_meta(): void {
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $userId, string $key ) {
				if ( $userId === 11 && $key === 'ems_scout_ids' ) {
					return array();
				}
				if ( $userId === 11 && $key === 'ems_scout_id' ) {
					return '2005';
				}
				return '';
			}
		);

		$this->assertSame( array( 2005 ), ExplorerUserResolver::getScoutIdsForUser( 11 ) );
	}

	public function test_find_wp_user_id_by_scout_id_resolves_plural_meta(): void {
		Functions\when( 'get_users' )->justReturn( array( 42 ) );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $userId, string $key ) {
				if ( $userId === 42 && $key === 'ems_scout_ids' ) {
					return array( 30001, 30002 );
				}
				return '';
			}
		);

		$this->assertSame( 42, ExplorerUserResolver::findWpUserIdByScoutId( 30002 ) );
	}
}
