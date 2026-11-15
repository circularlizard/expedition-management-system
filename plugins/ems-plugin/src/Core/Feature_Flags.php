<?php
namespace EMS\Core;

class Feature_Flags {

	/**
	 * Check if a module is enabled by slug.
	 *
	 * @param string $slug
	 * @param bool   $is_always_on
	 * @return bool
	 */
	public static function is_enabled( string $slug, bool $is_always_on = false ): bool {
		if ( $is_always_on ) {
			return true;
		}

		$normalized_slug = strtoupper( str_replace( '-', '_', $slug ) );
		$const_norm      = 'EMS_DISABLE_MODULE_' . $normalized_slug;
		if ( defined( $const_norm ) && constant( $const_norm ) ) {
			return false;
		}

		$const_raw = 'EMS_DISABLE_MODULE_' . strtoupper( $slug );
		if ( $const_norm !== $const_raw && defined( $const_raw ) && constant( $const_raw ) ) {
			return false;
		}

		$flags = self::get_all();
		if ( array_key_exists( $slug, $flags ) ) {
			return (bool) $flags[ $slug ];
		}

		return true;
	}

	/**
	 * Retrieve all configured feature flags.
	 *
	 * @return array<string, bool>
	 */
	public static function get_all(): array {
		$flags = get_option( 'ems_feature_flags', array() );
		if ( ! is_array( $flags ) ) {
			return array();
		}

		$result = array();
		foreach ( $flags as $key => $value ) {
			$result[ (string) $key ] = (bool) $value;
		}

		return $result;
	}

	/**
	 * Set a module feature flag state.
	 *
	 * @param string $slug
	 * @param bool   $enabled
	 */
	public static function set_flag( string $slug, bool $enabled ): void {
		$flags = self::get_all();
		$flags[ $slug ] = $enabled;
		update_option( 'ems_feature_flags', $flags );
	}
}
