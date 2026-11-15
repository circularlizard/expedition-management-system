<?php
namespace EMS\Modules\Notifications;

use EMS\Core\Audit_Logger;

/**
 * REST Controller for viewing, editing, resetting, and testing registered email templates.
 */
class Email_Templates_Controller {

	private Email_Template_Registry $registry;
	private Email_Template_Service $service;

	public function __construct(
		?Email_Template_Registry $registry = null,
		?Email_Template_Service $service = null
	) {
		$this->registry = $registry ?? new Email_Template_Registry();
		$this->service  = $service ?? new Email_Template_Service();
	}

	/**
	 * Registers REST API routes under ems/v1.
	 */
	public function register_routes(): void {
		register_rest_route(
			'ems/v1',
			'/email-templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/email-templates/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_template' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/email-templates/(?P<slug>[a-z0-9_-]+)/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_template' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/email-templates/(?P<slug>[a-z0-9_-]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_test_email' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Lists all registered email templates with current saved values and available tokens.
	 */
	public function list_templates(): \WP_REST_Response {
		$result = array();
		foreach ( $this->registry->all() as $def ) {
			$result[] = $this->hydrate_template( $def );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Updates subject, reply_to, and HTML body for a registered template.
	 */
	public function update_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$def  = $this->registry->get( $slug );
		if ( ! $def ) {
			return new \WP_Error( 'ems_template_not_found', 'Email template not found.', array( 'status' => 404 ) );
		}

		$params = $this->extract_params( $request, array( 'subject', 'reply_to', 'body' ) );

		if ( array_key_exists( 'subject', $params ) ) {
			update_option( (string) $def['subject_option'], sanitize_text_field( (string) $params['subject'] ) );
		}
		if ( array_key_exists( 'reply_to', $params ) ) {
			update_option( (string) $def['reply_to_option'], sanitize_text_field( (string) $params['reply_to'] ) );
		}
		if ( array_key_exists( 'body', $params ) ) {
			update_option( (string) $def['body_option'], wp_kses_post( (string) $params['body'] ) );
		}

		Audit_Logger::log( 'email_template_updated_' . $slug );

		return new \WP_REST_Response( $this->hydrate_template( $def ), 200 );
	}

	/**
	 * Resets a registered template back to its default subject, reply_to, and body.
	 */
	public function reset_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$def  = $this->registry->get( $slug );
		if ( ! $def ) {
			return new \WP_Error( 'ems_template_not_found', 'Email template not found.', array( 'status' => 404 ) );
		}

		delete_option( (string) $def['subject_option'] );
		delete_option( (string) $def['reply_to_option'] );
		delete_option( (string) $def['body_option'] );

		Audit_Logger::log( 'email_template_reset_' . $slug );

		return new \WP_REST_Response( $this->hydrate_template( $def ), 200 );
	}

	/**
	 * Sends a test HTML email for a template using sample token values.
	 */
	public function send_test_email( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$def  = $this->registry->get( $slug );
		if ( ! $def ) {
			return new \WP_Error( 'ems_template_not_found', 'Email template not found.', array( 'status' => 404 ) );
		}

		$params = $this->extract_params( $request, array( 'to', 'subject', 'reply_to', 'body' ) );

		$to = sanitize_email( (string) ( $params['to'] ?? '' ) );
		if ( $to === '' || ! is_email( $to ) ) {
			return new \WP_Error( 'ems_invalid_recipient', 'Please provide a valid recipient email address.', array( 'status' => 400 ) );
		}

		$live_site_name = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		if ( $live_site_name !== '' && function_exists( 'wp_specialchars_decode' ) ) {
			$live_site_name = wp_specialchars_decode( $live_site_name, ENT_QUOTES );
		}

		$sample_tokens = array();
		$sample_names  = array();
		foreach ( (array) ( $def['tokens'] ?? array() ) as $tok ) {
			$raw_token = (string) ( $tok['token'] ?? '' );
			$key       = trim( $raw_token, '{}' );
			if ( $key !== '' ) {
				$example = ( $key === 'site_name' && $live_site_name !== '' )
					? $live_site_name
					: (string) ( $tok['example'] ?? $key );
				$sample_tokens[ $raw_token ] = $example;
				$sample_names[ $key ]        = $example;
			}
		}

		$subject_override = isset( $params['subject'] ) ? sanitize_text_field( (string) $params['subject'] ) : null;
		$body_override    = isset( $params['body'] ) ? wp_kses_post( (string) $params['body'] ) : null;
		$reply_to         = isset( $params['reply_to'] )
			? sanitize_text_field( (string) $params['reply_to'] )
			: (string) get_option( (string) $def['reply_to_option'], '' );

		$raw_subject = $subject_override !== null
			? $subject_override
			: (string) get_option( (string) $def['subject_option'], (string) $def['default_subject'] );
		$raw_body    = $body_override !== null
			? $body_override
			: (string) get_option( (string) $def['body_option'], (string) $def['default_body'] );

		// Expand any parameterized list tokens (e.g. {{explorer_list_osm levels="bronze"}}) with sample HTML.
		$expanded_body = preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)(?:\s+levels\s*=\s*(["\'])([^"\']*)\2)?\s*\}\}/i',
			static function ( array $matches ) use ( $sample_names ): string {
				$name = strtolower( $matches[1] );
				return $sample_names[ $name ] ?? $matches[0];
			},
			$raw_body
		);
		$raw_body = is_string( $expanded_body ) ? $expanded_body : $raw_body;

		$rendered = $this->service->render( $raw_subject, $raw_body, $sample_tokens );
		$headers  = $this->service->build_headers( (string) $def['reply_to_option'], $sample_tokens, array(), true );
		if ( $reply_to !== '' ) {
			$clean_reply = sanitize_text_field( str_replace( array( "\r", "\n" ), '', $reply_to ) );
			if ( $clean_reply !== '' ) {
				$headers[] = 'Reply-To: ' . $clean_reply;
			}
		}

		$sent = (bool) wp_mail( $to, $rendered['subject'], $rendered['message'], $headers );

		if ( ! $sent ) {
			return new \WP_Error( 'ems_mail_failed', 'Failed to send test email via wp_mail.', array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'sent'    => true,
				'to'      => $to,
				'subject' => $rendered['subject'],
			),
			200
		);
	}

	/**
	 * Extracts JSON body params or falls back to named WP_REST_Request params.
	 *
	 * @param \WP_REST_Request  $request REST request.
	 * @param array<int,string> $fields  Allowed fallback parameter names.
	 * @return array<string, mixed>
	 */
	private function extract_params( \WP_REST_Request $request, array $fields ): array {
		$params = $request->get_json_params();
		if ( is_array( $params ) && ! empty( $params ) ) {
			return $params;
		}
		$fallback = array();
		foreach ( $fields as $field ) {
			$val = $request->get_param( $field );
			if ( $val !== null ) {
				$fallback[ $field ] = $val;
			}
		}
		return $fallback;
	}

	/**
	 * @param array<string, mixed> $def Template definition.
	 * @return array<string, mixed>
	 */
	private function hydrate_template( array $def ): array {
		$saved_subject  = get_option( (string) $def['subject_option'], false );
		$saved_reply_to = get_option( (string) $def['reply_to_option'], false );
		$saved_body     = get_option( (string) $def['body_option'], false );

		return array(
			'slug'            => $def['slug'],
			'label'           => $def['label'],
			'category'        => $def['category'],
			'description'     => $def['description'],
			'subject'         => $saved_subject !== false && $saved_subject !== '' ? (string) $saved_subject : (string) $def['default_subject'],
			'reply_to'        => $saved_reply_to !== false ? (string) $saved_reply_to : '',
			'body'            => $saved_body !== false && $saved_body !== '' ? (string) $saved_body : (string) $def['default_body'],
			'default_subject' => $def['default_subject'],
			'default_body'    => $def['default_body'],
			'is_customized'   => ( $saved_subject !== false && $saved_subject !== '' ) || ( $saved_body !== false && $saved_body !== '' ) || ( $saved_reply_to !== false && $saved_reply_to !== '' ),
			'tokens'          => $def['tokens'],
		);
	}
}
