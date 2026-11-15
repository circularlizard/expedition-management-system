<?php
namespace EMS\Modules\Notifications;

/**
 * Shared email template rendering, header sanitization, and dispatch service.
 */
class Email_Template_Service {

	/**
	 * Renders a subject and body template using token replacement.
	 *
	 * @param string               $subject_tpl  Subject template string.
	 * @param string               $body_tpl     Body template string.
	 * @param array<string,string> $replacements Token => value map.
	 * @return array{subject: string, message: string}
	 */
	public function render( string $subject_tpl, string $body_tpl, array $replacements ): array {
		return array(
			'subject' => strtr( $subject_tpl, $replacements ),
			'message' => strtr( $body_tpl, $replacements ),
		);
	}

	/**
	 * Resolves templates from WP options (falling back to defaults) and renders them.
	 *
	 * @param string                              $subject_option  WP option key for subject.
	 * @param string                              $default_subject Fallback subject template.
	 * @param string                              $body_option     WP option key for body.
	 * @param string                              $default_body    Fallback body template.
	 * @param array<string,string>                $replacements    Token => value map.
	 * @param array<string,array<int,array<string,mixed>>> $token_datasets Optional explorer datasets for parameterized list shortcodes.
	 * @return array{subject: string, message: string}
	 */
	public function resolve_template(
		string $subject_option,
		string $default_subject,
		string $body_option,
		string $default_body,
		array $replacements,
		array $token_datasets = array()
	): array {
		$subject_tpl = trim( (string) get_option( $subject_option, '' ) );
		if ( $subject_tpl === '' ) {
			$subject_tpl = $default_subject;
		}

		$body_tpl = trim( (string) get_option( $body_option, '' ) );
		if ( $body_tpl === '' ) {
			$body_tpl = $default_body;
		}

		if ( ! empty( $token_datasets ) ) {
			$body_tpl = $this->expand_list_shortcodes( $body_tpl, $token_datasets );
		}

		return $this->render( $subject_tpl, $body_tpl, $replacements );
	}

	/**
	 * Expands parameterized list shortcodes such as {{explorer_list_osm levels="bronze,silver"}}.
	 *
	 * @param string                                      $template       Template body.
	 * @param array<string,array<int,array<string,mixed>>> $token_datasets Map of shortcode name => explorer list.
	 * @return string
	 */
	public function expand_list_shortcodes( string $template, array $token_datasets ): string {
		$result = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)(?:\s+levels\s*=\s*"([^"]*)")?\s*\}\}/',
			function ( array $matches ) use ( $token_datasets ): string {
				$token = $matches[1];
				if ( ! array_key_exists( $token, $token_datasets ) ) {
					return $matches[0];
				}

				$explorers = $token_datasets[ $token ];
				$raw_levels = isset( $matches[2] ) ? trim( $matches[2] ) : '';
				if ( $raw_levels !== '' ) {
					$allowed = array_values(
						array_filter(
							array_map(
								static fn( string $l ): string => strtolower( trim( $l ) ),
								explode( ',', $raw_levels )
							)
						)
					);
					$explorers = array_values(
						array_filter(
							$explorers,
							static fn( array $exp ): bool => in_array( strtolower( trim( (string) ( $exp['dofe_level'] ?? '' ) ) ), $allowed, true )
						)
					);
				}

				return $this->format_grouped_explorer_list( $explorers );
			},
			$template
		);

		return $result !== null ? $result : $template;
	}

	/**
	 * Builds sanitized email headers with optional Reply-To and HTML Content-Type.
	 *
	 * @param string               $reply_to_option  WP option key for Reply-To.
	 * @param array<string,string> $replacements     Token => value map.
	 * @param array<int,string>|string $existing_headers Existing headers.
	 * @param bool                 $is_html          Whether to append HTML Content-Type.
	 * @return array<int,string>
	 */
	public function build_headers(
		string $reply_to_option,
		array $replacements = array(),
		array|string $existing_headers = array(),
		bool $is_html = false
	): array {
		$headers = array();
		if ( is_array( $existing_headers ) ) {
			foreach ( $existing_headers as $h ) {
				if ( is_string( $h ) && trim( $h ) !== '' ) {
					$headers[] = trim( $h );
				}
			}
		} elseif ( trim( $existing_headers ) !== '' ) {
			foreach ( explode( "\n", $existing_headers ) as $line ) {
				$trimmed = trim( $line );
				if ( $trimmed !== '' ) {
					$headers[] = $trimmed;
				}
			}
		}

		$reply_to = trim( (string) get_option( $reply_to_option, '' ) );
		if ( $reply_to !== '' ) {
			$reply_to = strtr( $reply_to, $replacements );
			$reply_to = (string) preg_replace( '/^reply-to:\s*/i', '', $reply_to );
			$reply_to = (string) preg_replace( '/[\r\n\t]+/', '', $reply_to );
			$reply_to = trim( $reply_to );

			if ( $reply_to !== '' ) {
				$headers = array_values(
					array_filter(
						$headers,
						static function ( string $h ): bool {
							return ! str_starts_with( strtolower( $h ), 'reply-to:' );
						}
					)
				);
				$headers[] = 'Reply-To: ' . $reply_to;
			}
		}

		if ( $is_html ) {
			$headers = array_values(
				array_filter(
					$headers,
					static function ( string $h ): bool {
						return ! str_starts_with( strtolower( $h ), 'content-type:' );
					}
				)
			);
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		return $headers;
	}

	/**
	 * Sends a templated email via wp_mail(), with optional subject/body overrides.
	 *
	 * @param string               $to               Recipient email address.
	 * @param string               $subject_option   WP option key for subject.
	 * @param string               $default_subject  Fallback subject template.
	 * @param string               $body_option      WP option key for body.
	 * @param string               $default_body     Fallback body template.
	 * @param string               $reply_to_option  WP option key for Reply-To.
	 * @param array<string,string> $replacements     Token => value map.
	 * @param bool                 $is_html          Whether to send as HTML.
	 * @param string|null                         $subject_override Optional edited subject override.
	 * @param string|null                         $body_override    Optional edited body override.
	 * @param array<string,array<int,array<string,mixed>>> $token_datasets Optional explorer datasets for parameterized list shortcodes.
	 * @return bool
	 */
	public function send(
		string $to,
		string $subject_option,
		string $default_subject,
		string $body_option,
		string $default_body,
		string $reply_to_option,
		array $replacements = array(),
		bool $is_html = false,
		?string $subject_override = null,
		?string $body_override = null,
		array $token_datasets = array()
	): bool {
		$to = trim( $to );
		if ( $to === '' || ! is_email( $to ) ) {
			return false;
		}

		$rendered = $this->resolve_template(
			$subject_option,
			$default_subject,
			$body_option,
			$default_body,
			$replacements,
			$token_datasets
		);

		$subject = ( $subject_override !== null && trim( $subject_override ) !== '' )
			? $subject_override
			: $rendered['subject'];
		$message = ( $body_override !== null && trim( $body_override ) !== '' )
			? $body_override
			: $rendered['message'];

		$headers = $this->build_headers( $reply_to_option, $replacements, array(), $is_html );

		return (bool) wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Formats a list of explorers into HTML grouped by DofE level (Bronze, Silver, Gold)
	 * and sorted alphabetically by first name (then last name) within each level.
	 *
	 * @param array<int,array<string,mixed>> $explorers Explorer records with first_name/last_name/dofe_level.
	 * @param string                         $empty_html Fallback HTML when no explorers are present.
	 * @return string
	 */
	public function format_grouped_explorer_list( array $explorers, string $empty_html = '<p><em>None</em></p>' ): string {
		$levels = array(
			'bronze' => 'Bronze',
			'silver' => 'Silver',
			'gold'   => 'Gold',
		);

		$buckets = array(
			'bronze' => array(),
			'silver' => array(),
			'gold'   => array(),
			'other'  => array(),
		);

		foreach ( $explorers as $exp ) {
			$first = trim( (string) ( $exp['first_name'] ?? $exp['explorer_first_name'] ?? '' ) );
			$last  = trim( (string) ( $exp['last_name'] ?? $exp['explorer_last_name'] ?? '' ) );
			if ( $first === '' && $last === '' ) {
				continue;
			}
			$lvl = strtolower( trim( (string) ( $exp['dofe_level'] ?? '' ) ) );
			if ( ! isset( $buckets[ $lvl ] ) ) {
				$lvl = 'other';
			}
			$buckets[ $lvl ][] = array(
				'first' => $first,
				'last'  => $last,
			);
		}

		$html_parts = array();
		$all_groups = $levels + array( 'other' => 'Other' );

		foreach ( $all_groups as $key => $label ) {
			if ( empty( $buckets[ $key ] ) ) {
				continue;
			}

			usort(
				$buckets[ $key ],
				static function ( array $a, array $b ): int {
					$cmp = strcasecmp( $a['first'], $b['first'] );
					return $cmp !== 0 ? $cmp : strcasecmp( $a['last'], $b['last'] );
				}
			);

			$items = array();
			foreach ( $buckets[ $key ] as $person ) {
				$full_name = trim( $person['first'] . ' ' . $person['last'] );
				$escaped   = function_exists( 'esc_html' ) ? esc_html( $full_name ) : htmlspecialchars( $full_name, ENT_QUOTES, 'UTF-8' );
				$items[]   = '<li>' . $escaped . '</li>';
			}

			$html_parts[] = '<p><strong>' . $label . '</strong></p><ul>' . implode( '', $items ) . '</ul>';
		}

		if ( empty( $html_parts ) ) {
			return $empty_html;
		}

		return implode( "\n", $html_parts );
	}
}
