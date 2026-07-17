<?php
namespace EMS\Integrations\Drivers;

use EMS\Integrations\Exceptions\Api_Blocked_Exception;
use EMS\Integrations\Exceptions\Api_Response_Exception;
use EMS\Integrations\Exceptions\Rate_Limit_Exception;

class Live_Driver implements Driver_Interface {
	private array $last_headers  = array();
	private string $access_token = '';

	/**
	 * @throws Rate_Limit_Exception on HTTP 429
	 * @throws Api_Blocked_Exception on X-Blocked header
	 * @throws Api_Response_Exception on WP_Error or unparseable response
	 */
	private function request( string $url, array $args = array() ): array {
		if ( $this->access_token ) {
			$args['headers'] = array_merge(
				$args['headers'] ?? array(),
				array(
					'Authorization' => 'Bearer ' . $this->access_token,
				)
			);
		}

		$args['timeout'] = $args['timeout'] ?? 15;
		$method          = $args['method'] ?? 'GET';

		if ( ( defined( 'EMS_DEBUG' ) && EMS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			error_log( sprintf( '[EMS Debug] OSM Call Request: %s %s - Args: %s', $method, $url, wp_json_encode( $args ) ) );
		}

		if ( $method === 'POST' ) {
			$response = wp_safe_remote_post( $url, $args );
		} else {
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			if ( ( defined( 'EMS_DEBUG' ) && EMS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				error_log( sprintf( '[EMS Debug] OSM Call WP_Error: %s %s - Error: %s', $method, $url, $response->get_error_message() ) );
			}
			throw new Api_Response_Exception( $response->get_error_message(), $url );
		}

		$http_status        = (int) wp_remote_retrieve_response_code( $response );
		$headers            = wp_remote_retrieve_headers( $response );
		$this->last_headers = $this->parse_all_headers( $headers, $http_status, $url );
		$body               = wp_remote_retrieve_body( $response );

		if ( ( defined( 'EMS_DEBUG' ) && EMS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			error_log( sprintf( '[EMS Debug] OSM Call Response: %s %s - Code: %d - Body: %s', $method, $url, $http_status, $body ) );
		}

		if ( ! empty( $this->last_headers['x-blocked'] ) ) {
			throw new Api_Blocked_Exception( (string) $this->last_headers['x-blocked'], $url );
		}

		if ( $http_status === 429 ) {
			throw new Rate_Limit_Exception(
				(int) ( $this->last_headers['retry-after'] ?? 0 ),
				(int) ( $this->last_headers['x-ratelimit-reset'] ?? 0 ),
				$url
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$preview = substr( $body, 0, 500 );
			throw new Api_Response_Exception( 'Response was not valid JSON. HTTP ' . $http_status . '. Body: ' . $preview, $url );
		}

		return $data;
	}

	private function parse_all_headers( $headers, int $http_status, string $url ): array {
		$parsed = array(
			'http_status'           => $http_status,
			'url'                   => $url,
			'x-ratelimit-limit'     => isset( $headers['x-ratelimit-limit'] ) ? (int) $headers['x-ratelimit-limit'] : null,
			'x-ratelimit-remaining' => isset( $headers['x-ratelimit-remaining'] ) ? (int) $headers['x-ratelimit-remaining'] : null,
			'x-ratelimit-reset'     => isset( $headers['x-ratelimit-reset'] ) ? (int) $headers['x-ratelimit-reset'] : null,
			'retry-after'           => isset( $headers['retry-after'] ) ? (int) $headers['retry-after'] : null,
			'x-deprecated'          => $headers['x-deprecated'] ?? null,
			'x-blocked'             => $headers['x-blocked'] ?? null,
		);

		return $parsed;
	}

	public function get_last_response_headers(): array {
		return $this->last_headers;
	}

	public function set_access_token( string $token ): void {
		$this->access_token = $token;
	}

	public function get_data_payload(): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'      => 'getDataPayload',
				'client_time' => time() * 1000,
			),
			$base . '/ext/generic/startup/'
		);

		return $this->request( $url );
	}

	public function get_section_members( int $section_id, int $term_id, string $section_type = 'explorers' ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'getListOfMembers',
				'sort'      => 'dob',
				'sectionid' => $section_id,
				'termid'    => $term_id,
				'section'   => $section_type,
			),
			$base . '/ext/members/contact/'
		);

		return $this->request( $url );
	}

	public function get_section_events( int $section_id, int $term_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'get',
				'sectionid' => $section_id,
				'termid'    => $term_id,
			),
			$base . '/ext/events/summary/'
		);

		return $this->request( $url );
	}

	public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'                => 'getData',
				'section_id'            => $section_id,
				'associated_id'         => $scout_id,
				'associated_type'       => 'member',
				'associated_is_section' => 'null',
				'varname_filter'        => 'null',
				'context'               => 'members',
				'group_order'           => 'section',
			),
			$base . '/ext/customdata/'
		);

		return $this->request( $url );
	}

	public function get_patrols( int $section_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'get',
				'sectionid' => $section_id,
			),
			$base . '/ext/settings/patrols/'
		);

		return $this->request( $url );
	}

	public function get_flexi_records( int $section_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'getFlexiRecords',
				'sectionid' => $section_id,
				'archived'  => 'n',
			),
			$base . '/ext/members/flexirecords/'
		);

		return $this->request( $url );
	}

	public function get_flexi_record_structure( int $section_id, int $flexi_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'getStructure',
				'sectionid' => $section_id,
				'extraid'   => $flexi_id,
			),
			$base . '/ext/members/flexirecords/'
		);

		return $this->request( $url );
	}

	public function get_flexi_record_data( int $section_id, int $flexi_id, int $term_id = 0 ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$args = array(
			'action'    => 'getData',
			'extraid'   => $flexi_id,
			'sectionid' => $section_id,
			'nototal'   => '',
		);
		if ( $term_id > 0 ) {
			$args['termid'] = $term_id;
		}
		$url = add_query_arg( $args, $base . '/ext/members/flexirecords/' );

		return $this->request( $url );
	}

	public function get_individual( int $section_id, int $member_id, int $term_id = 0 ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$args = array(
			'action'    => 'getIndividual',
			'sectionid' => $section_id,
			'scoutid'   => $member_id,
			'context'   => 'members',
		);
		if ( $term_id > 0 ) {
			$args['termid'] = $term_id;
		}
		$url = add_query_arg( $args, $base . '/ext/members/contact/' );

		return $this->request( $url );
	}

	public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'     => 'getContactDetails',
				'section_id' => $section_id,
				'member_id'  => $scout_id,
			),
			$base . '/ext/mymember/details/'
		);

		return $this->request( $url );
	}


	public function get_event_attendance( int $event_id, int $term_id ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array( 'term_id' => $term_id ),
			$base . '/v3/events/event/' . $event_id . '/members/attendance'
		);

		return $this->request( $url );
	}

	public function update_event_attendance( int $section_id, int $event_id, array $member_updates ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = $base . '/v3/events/event/' . $event_id . '/members/attendance/updateMany';
		return $this->request( $url, array(
			'method' => 'POST',
			'body'   => array(
				'field'      => 'attending',
				'value'      => 'invited',
				'member_ids' => implode( ',', $member_updates ),
			),
		) );
	}

	public function create_flexi_record( int $section_id, string $name ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'addRecordSet',
				'sectionid' => $section_id,
			),
			$base . '/ext/members/flexirecords/'
		);
		return $this->request( $url, array(
			'method' => 'POST',
			'body'   => array(
				'name'   => $name,
				'patrol' => 1,
				'type'   => 'none',
			),
		) );
	}

	public function add_flexi_record_column( int $section_id, int $flexi_id, string $column_name ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'addColumn',
				'sectionid' => $section_id,
				'extraid'   => $flexi_id,
			),
			$base . '/ext/members/flexirecords/'
		);
		return $this->request( $url, array(
			'method' => 'POST',
			'body'   => array(
				'columnName' => $column_name,
			),
		) );
	}

	public function update_flexi_record_data( int $section_id, int $flexi_id, array $values ): array {
		$base = rtrim( (string) get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' ), '/' );
		$url  = add_query_arg(
			array(
				'action'    => 'multiUpdate',
				'sectionid' => $section_id,
			),
			$base . '/ext/members/flexirecords/'
		);
		return $this->request( $url, array(
			'method' => 'POST',
			'body'   => array(
				'scouts'  => wp_json_encode( array_map( 'strval', $values['scouts'] ) ),
				'value'   => $values['value'],
				'col'     => $values['col'],
				'extraid' => $flexi_id,
			),
		) );
	}
}
