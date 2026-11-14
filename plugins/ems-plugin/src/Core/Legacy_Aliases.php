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
	}
}
