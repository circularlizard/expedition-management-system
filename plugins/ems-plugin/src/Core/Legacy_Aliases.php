<?php
namespace EMS\Core;

class Legacy_Aliases {

	/**
	 * Register class aliases for backwards compatibility.
	 */
	public static function register_aliases(): void {
		if ( ! class_exists( 'EMS\\Admin\\Admin_View_Controller', false ) && class_exists( \EMS\Modules\Signups\Admin_View_Controller::class ) ) {
			class_alias( \EMS\Modules\Signups\Admin_View_Controller::class, 'EMS\\Admin\\Admin_View_Controller' );
		}
		if ( ! class_exists( 'EMS\\Admin\\Expedition_Admin_Controller', false ) && class_exists( \EMS\Modules\Expeditions\Expedition_Admin_Controller::class ) ) {
			class_alias( \EMS\Modules\Expeditions\Expedition_Admin_Controller::class, 'EMS\\Admin\\Expedition_Admin_Controller' );
		}
		if ( ! class_exists( 'EMS\\Admin\\Volunteer_Controller', false ) && class_exists( \EMS\Modules\Volunteers\Volunteer_Controller::class ) ) {
			class_alias( \EMS\Modules\Volunteers\Volunteer_Controller::class, 'EMS\\Admin\\Volunteer_Controller' );
		}
		if ( ! class_exists( 'EMS\\Admin\\Unit_Leader_Controller', false ) && class_exists( \EMS\Modules\LeaderDashboard\Unit_Leader_Controller::class ) ) {
			class_alias( \EMS\Modules\LeaderDashboard\Unit_Leader_Controller::class, 'EMS\\Admin\\Unit_Leader_Controller' );
		}
		if ( ! class_exists( 'EMS\\Admin\\Unit_Leader_Page', false ) && class_exists( \EMS\Modules\LeaderDashboard\Unit_Leader_Page::class ) ) {
			class_alias( \EMS\Modules\LeaderDashboard\Unit_Leader_Page::class, 'EMS\\Admin\\Unit_Leader_Page' );
		}
	}
}
