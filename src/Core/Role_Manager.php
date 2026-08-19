<?php
namespace EMS\Core;

class Role_Manager {
	/**
	 * Registers custom WordPress roles.
	 * Can be run on plugin activation or init hook.
	 */
	public function register_roles(): void {
		$roles = array(
			'ems_parent'   => array(
				'display_name' => 'EMS Parent',
				'capabilities' => array(
					'read'                     => true,
					'access_ems_parent_portal' => true,
				),
			),
			'ems_explorer' => array(
				'display_name' => 'EMS Explorer',
				'capabilities' => array(
					'read'                       => true,
					'access_ems_explorer_portal' => true,
				),
			),
			'ems_network_member' => array(
				'display_name' => 'EMS Network Member',
				'capabilities' => array(
					'read'                       => true,
					'access_ems_explorer_portal' => true,
				),
			),
			'ems_leader'   => array(
				'display_name' => 'EMS Leader',
				'capabilities' => array(
					'read'                     => true,
					'edit_posts'               => true,
					'access_ems_leader_portal' => true,
				),
			),
		);

		foreach ( $roles as $role_slug => $role_info ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				add_role( $role_slug, $role_info['display_name'], $role_info['capabilities'] );
			} else {
				// Keep capabilities aligned
				foreach ( $role_info['capabilities'] as $cap => $grant ) {
					if ( $grant ) {
						$role->add_cap( $cap );
					} else {
						$role->remove_cap( $cap );
					}
				}
			}
		}
	}
}
