<?php
namespace EMS\Core;

class Shortcode_Boundary {

	public const DEFAULT_ERROR_HTML = '<div class="ems-error-boundary ems-shortcode-error"><p>This section of the expedition portal is temporarily unavailable.</p></div>';

	/**
	 * Safely execute a shortcode callback inside an error boundary.
	 *
	 * @param callable               $callback
	 * @param array<string, mixed>   $atts
	 * @param string|null            $content
	 * @param string                 $shortcode
	 * @return string
	 */
	public static function execute( callable $callback, array $atts = array(), ?string $content = null, string $shortcode = '' ): string {
		try {
			$result = $callback( $atts, $content, $shortcode );
			return is_string( $result ) ? $result : '';
		} catch ( \Throwable $e ) {
			Audit_Logger::log( 'shortcode_error:' . $shortcode );
			error_log( sprintf( '[EMS] Shortcode error for [%s]: %s', $shortcode, $e->getMessage() ) );
			return self::DEFAULT_ERROR_HTML;
		}
	}
}
