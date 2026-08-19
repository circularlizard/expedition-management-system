<?php
/**
 * OSM API Tester Tool
 * Developer-only utility to test Online Scout Manager (OSM) API endpoints.
 * Placed under tools/ directory, excluded from deployment and packaging.
 */

// Boot WordPress
$paths = array(
	dirname( __DIR__ ) . '/wp-load.php',            // Inside Docker container context
	dirname( __DIR__ ) . '/wordpress/wp-load.php',  // Host context
);

$wp_load_path = '';
foreach ( $paths as $path ) {
	if ( file_exists( $path ) ) {
		$wp_load_path = $path;
		break;
	}
}

if ( ! $wp_load_path ) {
	die( 'Error: WordPress wp-load.php not found in standard paths: ' . htmlspecialchars( implode( ', ', $paths ) ) );
}
require_once $wp_load_path;

// Only allow administrators
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Access Denied: You must be logged in as an administrator to use this tool.' );
}

// Start PHP session for OAuth state and temporary token storage
if ( ! session_id() ) {
	session_start();
}

$client_id        = get_option( 'ems_osm_client_id', '' );
$encrypted_secret = get_option( 'ems_osm_client_secret', '' );
$client_secret    = \EMS\Core\Encryption::decrypt( $encrypted_secret ) ?: '';
$auth_url         = get_option( 'ems_osm_auth_url', 'https://www.onlinescoutmanager.co.uk/oauth/authorize' );
$token_url        = get_option( 'ems_osm_token_url', 'https://www.onlinescoutmanager.co.uk/oauth/token' );
$base_url         = get_option( 'ems_osm_api_base_url', 'https://www.onlinescoutmanager.co.uk' );
$scope            = get_option( 'ems_osm_scope', 'section:member:read section:event:write section:flexirecord:write' );

$redirect_uri = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// Handle OAuth Authorization request
if ( isset( $_GET['authorize'] ) ) {
	$_SESSION['osm_tester_state'] = wp_create_nonce( 'osm_tester_auth' );
	$query = http_build_query(
		array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'scope'         => $scope,
			'redirect_uri'  => $redirect_uri,
			'state'         => $_SESSION['osm_tester_state'],
		)
	);
	wp_redirect( $auth_url . '?' . $query );
	exit;
}

// Handle OAuth callback (code exchange)
if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) {
	$state = $_GET['state'];
	if ( empty( $_SESSION['osm_tester_state'] ) || ! hash_equals( $_SESSION['osm_tester_state'], $state ) ) {
		wp_die( 'Invalid OAuth state.' );
	}
	unset( $_SESSION['osm_tester_state'] );

	$code     = $_GET['code'];
	$response = wp_safe_remote_post(
		$token_url,
		array(
			'body' => array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'code'          => $code,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_die( 'Token exchange failed: ' . esc_html( $response->get_error_message() ) );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || isset( $data['error'] ) ) {
		$error_msg = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
		wp_die( 'Token exchange failed: ' . esc_html( $error_msg ) );
	}

	$_SESSION['osm_tester_token']         = $data['access_token'];
	$_SESSION['osm_tester_token_expires'] = time() + (int) ( $data['expires_in'] ?? 3600 );

	// Redirect back to clean URL
	wp_redirect( $redirect_uri );
	exit;
}

// Handle Clear Token request
if ( isset( $_GET['clear_token'] ) ) {
	unset( $_SESSION['osm_tester_token'] );
	unset( $_SESSION['osm_tester_token_expires'] );
	wp_redirect( $redirect_uri );
	exit;
}

// Handle Manual Token submit
if ( isset( $_POST['manual_token'] ) ) {
	check_admin_referer( 'osm_tester_set_manual_token' );
	$manual_token = sanitize_text_field( $_POST['manual_token'] );
	if ( ! empty( $manual_token ) ) {
		$_SESSION['osm_tester_token']         = $manual_token;
		$_SESSION['osm_tester_token_expires'] = time() + 31536000; // 1 year
	}
	wp_redirect( $redirect_uri );
	exit;
}

// Resolve active token and source
$token        = '';
$token_source = 'None';
$token_expiry = null;

if ( ! empty( $_SESSION['osm_tester_token'] ) ) {
	$token        = $_SESSION['osm_tester_token'];
	$token_expiry = $_SESSION['osm_tester_token_expires'] ?? null;
	$token_source = 'Session (OAuth/Manual)';
}

if ( empty( $token ) ) {
	$auth_handler = new \EMS\Admin\OSM_Sync_Auth_Handler();
	$wp_token     = $auth_handler->get_cached_token();
	if ( ! empty( $wp_token ) ) {
		$token        = $wp_token;
		$user_id      = get_current_user_id();
		$token_expiry = (int) get_user_meta( $user_id, '_ems_osm_token_expires', true );
		$token_source = 'WordPress User Meta (Cached)';
	}
}

// Definitions of OSM Endpoints and expected inputs
$endpoints = array(
	'getDataPayload'        => array(
		'name'   => 'Get Data Payload (Startup / Sections)',
		'method' => 'GET',
		'path'   => '/ext/generic/startup/',
		'params' => array(
			'action'      => 'getDataPayload',
			'client_time' => time() * 1000,
		),
		'inputs' => array(),
	),
	'getListOfMembers'      => array(
		'name'   => 'Get List of Members',
		'method' => 'GET',
		'path'   => '/ext/members/contact/',
		'params' => array(
			'action' => 'getListOfMembers',
			'sort'   => 'dob',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'termid'    => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Term ID',
			),
			'section'   => array(
				'type'     => 'text',
				'default'  => 'explorers',
				'required' => true,
				'label'    => 'Section Type (e.g. explorers)',
			),
		),
	),
	'getSectionEvents'      => array(
		'name'   => 'Get Section Events',
		'method' => 'GET',
		'path'   => '/ext/events/summary/',
		'params' => array(
			'action' => 'get',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'termid'    => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Term ID',
			),
		),
	),
	'getMemberDetail'       => array(
		'name'   => 'Get Member Custom Data (Detail)',
		'method' => 'GET',
		'path'   => '/ext/customdata/',
		'params' => array(
			'action'                => 'getData',
			'associated_type'       => 'member',
			'associated_is_section' => 'null',
			'varname_filter'        => 'null',
			'context'               => 'members',
			'group_order'           => 'section',
		),
		'inputs' => array(
			'section_id'    => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'associated_id' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Scout (Member) ID',
			),
		),
	),
	'getPatrols'            => array(
		'name'   => 'Get Patrols',
		'method' => 'GET',
		'path'   => '/ext/settings/patrols/',
		'params' => array(
			'action' => 'get',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
		),
	),
	'getFlexiRecords'        => array(
		'name'   => 'Get Flexi Records List',
		'method' => 'GET',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action'   => 'getFlexiRecords',
			'archived' => 'n',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
		),
	),
	'getFlexiRecordStructure' => array(
		'name'   => 'Get Flexi Record Structure',
		'method' => 'GET',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action' => 'getStructure',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'extraid'   => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Flexi Record (Extra) ID',
			),
		),
	),
	'getFlexiRecordData'      => array(
		'name'   => 'Get Flexi Record Data',
		'method' => 'GET',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action'  => 'getData',
			'nototal' => '',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'extraid'   => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Flexi Record (Extra) ID',
			),
			'termid'    => array(
				'type'     => 'number',
				'required' => false,
				'label'    => 'Term ID (Optional)',
			),
		),
	),
	'getIndividual'         => array(
		'name'   => 'Get Individual Contact (Alternate)',
		'method' => 'GET',
		'path'   => '/ext/members/contact/',
		'params' => array(
			'action'  => 'getIndividual',
			'context' => 'members',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'scoutid'   => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Scout (Member) ID',
			),
			'termid'    => array(
				'type'     => 'number',
				'required' => false,
				'label'    => 'Term ID (Optional)',
			),
		),
	),
	'getContactDetails'     => array(
		'name'   => 'Get Contact Details',
		'method' => 'GET',
		'path'   => '/ext/mymember/details/',
		'params' => array(
			'action' => 'getContactDetails',
		),
		'inputs' => array(
			'section_id' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'member_id'  => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Scout (Member) ID',
			),
		),
	),
	'getEventAttendance'    => array(
		'name'   => 'Get Event Attendance (v3)',
		'method' => 'GET',
		'path'   => '/v3/events/event/{event_id}/members/attendance',
		'inputs' => array(
			'event_id' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Event ID (in URL)',
			),
			'term_id'  => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Term ID',
			),
		),
	),
	'updateEventAttendance' => array(
		'name'   => 'Update Event Attendance (v3 POST)',
		'method' => 'POST',
		'path'   => '/v3/events/event/{event_id}/members/attendance/updateMany',
		'body'   => array(
			'field' => 'attending',
			'value' => 'invited',
		),
		'inputs' => array(
			'event_id'   => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Event ID (in URL)',
			),
			'member_ids' => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'Member IDs (comma-separated, e.g. 123,456)',
			),
		),
	),
	'createFlexiRecord'     => array(
		'name'   => 'Create Flexi Record (POST)',
		'method' => 'POST',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action' => 'addRecordSet',
		),
		'body'   => array(
			'dob'    => 1,
			'age'    => 1,
			'patrol' => 1,
			'type'   => 'none',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'name'      => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'Record Set Name',
			),
		),
	),
	'addFlexiRecordColumn'   => array(
		'name'   => 'Add Flexi Record Column (POST)',
		'method' => 'POST',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action' => 'addColumn',
		),
		'inputs' => array(
			'sectionid'  => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'extraid'    => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Flexi Record (Extra) ID',
			),
			'columnName' => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'Column Name',
			),
		),
	),
	'updateFlexiRecordData'  => array(
		'name'   => 'Update Flexi Record Data (POST)',
		'method' => 'POST',
		'path'   => '/ext/members/flexirecords/',
		'params' => array(
			'action' => 'multiUpdate',
		),
		'inputs' => array(
			'sectionid' => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Section ID',
			),
			'extraid'   => array(
				'type'     => 'number',
				'required' => true,
				'label'    => 'Flexi Record (Extra) ID',
			),
			'scouts'    => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'Scout IDs (comma-separated, e.g. 12345, 12346)',
			),
			'col'       => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'Column ID (e.g. c_123)',
			),
			'value'     => array(
				'type'     => 'text',
				'required' => true,
				'label'    => 'New Value',
			),
		),
	),
);

// Handle AJAX Request execution
if ( isset( $_GET['ajax_action'] ) && $_GET['ajax_action'] === 'call_api' ) {
	check_ajax_referer( 'osm_tester_call_api', 'security' );

	if ( empty( $token ) ) {
		wp_send_json_error( array( 'message' => 'No active token. Please authorize or set a token first.' ), 401 );
	}

	$operation = sanitize_text_field( $_POST['operation'] ?? '' );
	if ( empty( $operation ) ) {
		wp_send_json_error( array( 'message' => 'No operation specified.' ), 400 );
	}

	$method     = 'GET';
	$path       = '';
	$query_args = array();
	$body_args  = array();

	if ( $operation === 'custom' ) {
		$method = sanitize_text_field( $_POST['custom_method'] ?? 'GET' );
		$path   = sanitize_text_field( $_POST['custom_path'] ?? '' );

		$raw_query = $_POST['custom_query'] ?? '';
		if ( ! empty( $raw_query ) ) {
			parse_str( $raw_query, $query_args );
		}

		$raw_body = $_POST['custom_body'] ?? '';
		if ( ! empty( $raw_body ) ) {
			if ( str_starts_with( trim( $raw_body ), '{' ) || str_starts_with( trim( $raw_body ), '[' ) ) {
				$body_args = json_decode( $raw_body, true );
				if ( ! is_array( $body_args ) ) {
					wp_send_json_error( array( 'message' => 'Invalid JSON in custom body.' ), 400 );
				}
			} else {
				parse_str( $raw_body, $body_args );
			}
		}
	} elseif ( isset( $endpoints[ $operation ] ) ) {
		$spec       = $endpoints[ $operation ];
		$method     = $spec['method'];
		$path       = $spec['path'];
		$query_args = $spec['params'] ?? array();
		$body_args  = $spec['body'] ?? array();

		foreach ( $spec['inputs'] ?? array() as $name => $input_spec ) {
			$val = $_POST[ $name ] ?? '';
			if ( $input_spec['required'] && $val === '' ) {
				wp_send_json_error( array( 'message' => "Field '{$input_spec['label']}' is required." ), 400 );
			}

			if ( str_contains( $path, '{' . $name . '}' ) ) {
				$path = str_replace( '{' . $name . '}', urlencode( $val ), $path );
			} else {
				if ( $method === 'POST' ) {
					if ( $name === 'scouts' ) {
						$scouts_raw          = explode( ',', $val );
						$scouts              = array_map( 'strval', array_map( 'trim', $scouts_raw ) );
						$body_args['scouts'] = wp_json_encode( $scouts );
					} else {
						$body_args[ $name ] = $val;
					}
				} else {
					$query_args[ $name ] = $val;
				}
			}
		}
	} else {
		wp_send_json_error( array( 'message' => 'Unknown operation.' ), 400 );
	}

	$url = rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
	if ( ! empty( $query_args ) ) {
		$url = add_query_arg( $query_args, $url );
	}

	$args = array(
		'method'      => $method,
		'headers'     => array(
			'Authorization' => 'Bearer ' . $token,
		),
		'timeout'     => 20,
		'redirection' => 5,
		'sslverify'   => false, // Useful for dev environments calling external APIs
	);

	if ( $method === 'POST' ) {
		$args['body'] = $body_args;
	}

	$start_time  = microtime( true );
	$response    = ( $method === 'POST' ) ? wp_safe_remote_post( $url, $args ) : wp_safe_remote_get( $url, $args );
	$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

	if ( is_wp_error( $response ) ) {
		wp_send_json_success(
			array(
				'request' => array(
					'url'    => $url,
					'method' => $method,
					'body'   => $body_args,
				),
				'error'   => $response->get_error_message(),
			)
		);
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$headers     = wp_remote_retrieve_headers( $response );
	$body        = wp_remote_retrieve_body( $response );

	$json_data = json_decode( $body, true );

	wp_send_json_success(
		array(
			'request'  => array(
				'url'    => $url,
				'method' => $method,
				'body'   => $body_args,
			),
			'response' => array(
				'status_code' => $status_code,
				'duration_ms' => $duration_ms,
				'headers'     => $headers->to_array(),
				'body'        => $json_data !== null ? $json_data : $body,
				'is_json'     => $json_data !== null,
			),
		)
	);
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
	<meta charset="UTF-8">
	<title>Online Scout Manager (OSM) API Tester</title>
	<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex flex-col font-sans text-gray-900">

	<!-- Header -->
	<header class="bg-indigo-600 text-white py-4 px-6 shadow-md flex items-center justify-between flex-shrink-0">
		<div class="flex items-center space-x-3">
			<span class="text-2xl font-bold tracking-tight">EMS / OSM API Tester</span>
			<span class="bg-indigo-700 text-xs font-semibold px-2.5 py-1 rounded text-indigo-100">Dev Tool</span>
		</div>
		<div class="text-sm text-indigo-100">
			WordPress Context: <span class="font-mono bg-indigo-700 px-2 py-1 rounded text-white">Active</span>
		</div>
	</header>

	<!-- Main Body Layout -->
	<div class="flex flex-1 min-h-0 overflow-hidden">
		<!-- Left Panel: Configuration & Auth -->
		<aside class="w-1/3 bg-white border-r border-gray-200 p-6 overflow-y-auto flex flex-col space-y-6 flex-shrink-0">
			
			<!-- Connection Parameters -->
			<div>
				<h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">Connection Config</h2>
				<div class="space-y-3 text-sm">
					<div>
						<span class="font-semibold text-gray-600">Base API URL:</span>
						<div class="font-mono text-xs bg-gray-50 p-2 rounded mt-1 select-all truncate"><?php echo esc_html( $base_url ); ?></div>
					</div>
					<div>
						<span class="font-semibold text-gray-600">Client ID:</span>
						<div class="font-mono text-xs bg-gray-50 p-2 rounded mt-1 truncate"><?php echo esc_html( $client_id ?: 'Not Set' ); ?></div>
					</div>
					<div>
						<span class="font-semibold text-gray-600">Client Secret:</span>
						<div class="font-mono text-xs bg-gray-50 p-2 rounded mt-1"><?php echo $client_secret ? '••••••••••••••••' : 'Not Set'; ?></div>
					</div>
					<div>
						<span class="font-semibold text-gray-600">Requested OAuth Scopes:</span>
						<div class="font-mono text-xs bg-gray-50 p-2 rounded mt-1 select-all"><?php echo esc_html( $scope ); ?></div>
					</div>
				</div>
			</div>

			<!-- Active Token Details -->
			<div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
				<h3 class="text-sm font-bold text-gray-800 mb-3 flex items-center justify-between">
					<span>Active Authentication Token</span>
					<?php if ( ! empty( $token ) ) : ?>
						<span class="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded-full font-medium">Valid</span>
					<?php else : ?>
						<span class="bg-red-100 text-red-800 text-xs px-2 py-0.5 rounded-full font-medium">Missing</span>
					<?php endif; ?>
				</h3>
				
				<div class="space-y-2 text-xs">
					<div>
						<span class="font-semibold text-gray-600">Token Source:</span>
						<span class="font-mono"><?php echo esc_html( $token_source ); ?></span>
					</div>
					<?php if ( $token_expiry ) : ?>
						<div>
							<span class="font-semibold text-gray-600">Expires:</span>
							<span class="font-mono">
								<?php echo esc_html( date( 'Y-m-d H:i:s', $token_expiry ) ); ?>
								(<?php echo esc_html( round( ( $token_expiry - time() ) / 60 ) ); ?> mins)
							</span>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $token ) ) : ?>
						<div class="mt-2">
							<span class="font-semibold text-gray-600 block">Bearer Token:</span>
							<div class="flex items-center space-x-2 mt-1">
								<input type="password" id="bearer-token-input" value="<?php echo esc_attr( $token ); ?>" readonly class="bg-white border border-gray-300 rounded px-2 py-1 font-mono text-xs flex-1 select-all min-w-0" />
								<button type="button" onclick="toggleTokenVisibility()" class="text-indigo-600 hover:text-indigo-800 font-semibold">Show</button>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Auth Actions -->
			<div class="space-y-4 pt-2">
				<h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2 mb-2">Auth Actions</h2>
				
				<?php if ( empty( $client_id ) || empty( $client_secret ) ) : ?>
					<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded text-sm">
						<strong>Warning:</strong> Client ID and Secret are missing from WordPress settings. Configure them on the settings page to enable OAuth authorization.
					</div>
				<?php else : ?>
					<a href="?authorize=1" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 text-center">
						Authenticate via OAuth2 Flow
					</a>
				<?php endif; ?>

				<form method="post" action="" class="border-t border-gray-100 pt-4">
					<?php wp_nonce_field( 'osm_tester_set_manual_token' ); ?>
					<label class="block text-sm font-semibold text-gray-700">Paste Manual Access Token</label>
					<div class="mt-1 flex rounded-md shadow-sm">
						<input type="text" name="manual_token" required class="flex-1 min-w-0 block w-full px-3 py-1.5 rounded-none rounded-l-md border border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm font-mono text-xs" placeholder="Bearer Token...">
						<button type="submit" class="inline-flex items-center px-3 py-1.5 border border-l-0 border-gray-300 rounded-r-md bg-gray-50 text-gray-700 hover:bg-gray-100 text-sm font-medium">
							Set
						</button>
					</div>
				</form>

				<?php if ( ! empty( $token ) ) : ?>
					<div class="pt-2">
						<a href="?clear_token=1" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
							Clear Tester Token Session
						</a>
					</div>
				<?php endif; ?>
			</div>
		</aside>

		<!-- Middle Panel: Endpoints Selector & Inputs -->
		<main class="w-1/3 bg-white border-r border-gray-200 p-6 overflow-y-auto flex-shrink-0">
			<h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">Request Constructor</h2>
			
			<form id="api-form" class="space-y-6">
				<!-- Endpoint Dropdown -->
				<div>
					<label class="block text-sm font-semibold text-gray-700">Select OSM API Endpoint</label>
					<select id="operation-select" name="operation" onchange="handleOperationChange(this.value)" class="mt-1 block w-full rounded-md border border-gray-300 bg-white py-2 px-3 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm font-medium text-gray-800">
						<option value="">-- Choose operation --</option>
						<?php foreach ( $endpoints as $key => $spec ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $spec['name'] ); ?></option>
						<?php endforeach; ?>
						<option value="custom">Custom Endpoint Query (Raw)</option>
					</select>
				</div>

				<!-- Dynamic Form Inputs -->
				<div id="form-inputs" class="border-t border-gray-50 pt-4">
					<!-- Populated by JS -->
					<div class="text-sm text-gray-500 italic">Select an API operation to configure input parameters.</div>
				</div>

				<!-- Submit Button -->
				<div class="pt-4 border-t border-gray-100">
					<button type="submit" id="submit-btn" disabled class="w-full inline-flex justify-center py-2px-4 border border-transparent py-2.5 rounded-md shadow-sm text-sm font-bold text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed">
						Send Request
					</button>
				</div>
			</form>
		</main>

		<!-- Right Panel: Response Viewer -->
		<section class="flex-1 bg-slate-900 text-slate-100 p-6 overflow-y-auto flex flex-col space-y-6">
			<div class="flex items-center justify-between border-b border-slate-800 pb-3 flex-shrink-0">
				<h2 class="text-lg font-bold">API Response Viewer</h2>
				<div class="flex space-x-2">
					<span id="status-badge" class="hidden inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"></span>
					<span id="duration-badge" class="text-xs font-mono text-slate-400 bg-slate-800 px-2 py-1 rounded"></span>
				</div>
			</div>

			<!-- Warnings and Notices -->
			<div id="deprecated-warning" class="hidden bg-yellow-900 border border-yellow-800 text-yellow-100 p-3 rounded text-sm font-semibold flex-shrink-0"></div>
			<div id="blocked-warning" class="hidden bg-red-900 border border-red-800 text-red-100 p-3 rounded text-sm font-semibold flex-shrink-0"></div>

			<!-- Details -->
			<div id="response-container" class="hidden flex flex-col flex-1 min-h-0 space-y-4">
				
				<!-- Request URL -->
				<div>
					<h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Target Endpoint</h3>
					<div id="request-details" class="font-mono text-sm bg-slate-950 p-3 rounded border border-slate-800 break-all select-all"></div>
				</div>

				<!-- Rate Limits Header Details -->
				<div id="ratelimit-info" class="text-xs text-slate-300 bg-slate-800 px-3 py-2 rounded"></div>

				<!-- Response Headers -->
				<div class="flex-shrink-0">
					<details class="group border border-slate-800 rounded bg-slate-950">
						<summary class="font-bold text-xs text-slate-400 uppercase tracking-wider p-3 cursor-pointer select-none hover:bg-slate-900 flex justify-between items-center">
							<span>Response Headers</span>
							<span class="text-slate-500 font-mono transition-transform duration-100 group-open:rotate-90">▶</span>
						</summary>
						<pre id="response-headers" class="font-mono text-xs p-3 overflow-x-auto text-slate-300 border-t border-slate-800 max-h-48 overflow-y-auto select-all"></pre>
					</details>
				</div>

				<!-- Response Body -->
				<div class="flex-1 flex flex-col min-h-0">
					<h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1 flex-shrink-0">Response Body</h3>
					<div class="flex-1 min-h-0 bg-slate-950 rounded border border-slate-800 overflow-hidden flex flex-col">
						<pre id="response-body" class="flex-1 font-mono text-xs p-4 overflow-auto text-green-400 select-all"></pre>
					</div>
				</div>

			</div>

			<!-- Idle View -->
			<div id="idle-view" class="flex-1 flex items-center justify-center text-slate-500 italic text-sm">
				Construct and send a request to view results.
			</div>
		</section>
	</div>

	<!-- JavaScript Controller Logic -->
	<script>
		const endpoints = <?php echo json_encode( $endpoints ); ?>;
		const hasToken = <?php echo ! empty( $token ) ? 'true' : 'false'; ?>;

		function toggleTokenVisibility() {
			const tokenInput = document.getElementById('bearer-token-input');
			if (tokenInput.type === 'password') {
				tokenInput.type = 'text';
			} else {
				tokenInput.type = 'password';
			}
		}

		function handleOperationChange(operation) {
			const submitBtn = document.getElementById('submit-btn');
			const formInputsContainer = document.getElementById('form-inputs');

			if (!operation) {
				formInputsContainer.innerHTML = '<div class="text-sm text-gray-500 italic">Select an API operation to configure input parameters.</div>';
				submitBtn.disabled = true;
				return;
			}

			submitBtn.disabled = !hasToken;
			
			// Render form inputs dynamically
			if (operation === 'custom') {
				formInputsContainer.innerHTML = `
					<div class="mb-4">
						<label class="block text-sm font-semibold text-gray-700">Custom HTTP Method</label>
						<select name="custom_method" class="mt-1 block w-full rounded-md border border-gray-300 bg-white py-1.5 px-3 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
							<option value="GET">GET</option>
							<option value="POST">POST</option>
						</select>
					</div>
					<div class="mb-4">
						<label class="block text-sm font-semibold text-gray-700">Custom Path (e.g. /ext/members/contact/)</label>
						<input type="text" name="custom_path" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" placeholder="/ext/members/contact/">
					</div>
					<div class="mb-4">
						<label class="block text-sm font-semibold text-gray-700">Custom Query Parameters (URL query string)</label>
						<input type="text" name="custom_query" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" placeholder="action=getListOfMembers&sectionid=123">
					</div>
					<div class="mb-4">
						<label class="block text-sm font-semibold text-gray-700">Custom Body parameters (JSON or urlencoded query string)</label>
						<textarea name="custom_body" rows="4" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono text-xs" placeholder='{"name": "New Flexi Set"}'></textarea>
					</div>
				`;
				return;
			}

			const spec = endpoints[operation];
			if (!spec) return;

			formInputsContainer.innerHTML = '';
			const inputs = spec.inputs || {};

			if (Object.keys(inputs).length === 0) {
				formInputsContainer.innerHTML = '<div class="text-sm text-gray-600 bg-gray-50 p-3 rounded">This request does not require any parameters. Ready to send.</div>';
				return;
			}

			for (const [name, input] of Object.entries(inputs)) {
				const required = input.required ? 'required' : '';
				const defaultValue = input.default || '';
				const star = input.required ? '<span class="text-red-500">*</span>' : '';
				formInputsContainer.innerHTML += `
					<div class="mb-4">
						<label class="block text-sm font-semibold text-gray-700">${input.label} ${star}</label>
						<input type="${input.type}" name="${name}" ${required} value="${defaultValue}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
					</div>
				`;
			}
		}

		document.getElementById('api-form').addEventListener('submit', async function(e) {
			e.preventDefault();
			const submitBtn = document.getElementById('submit-btn');
			const responseContainer = document.getElementById('response-container');
			const idleView = document.getElementById('idle-view');
			const requestDetails = document.getElementById('request-details');
			const responseHeaders = document.getElementById('response-headers');
			const responseBody = document.getElementById('response-body');
			const statusBadge = document.getElementById('status-badge');
			const durationBadge = document.getElementById('duration-badge');
			const deprecatedWarning = document.getElementById('deprecated-warning');
			const blockedWarning = document.getElementById('blocked-warning');
			const rateLimitInfo = document.getElementById('ratelimit-info');

			submitBtn.disabled = true;
			submitBtn.innerText = 'Sending Request...';
			responseContainer.classList.add('hidden');
			idleView.classList.remove('hidden');
			deprecatedWarning.classList.add('hidden');
			blockedWarning.classList.add('hidden');

			try {
				const formData = new FormData(this);
				const searchParams = new URLSearchParams();
				for (const pair of formData.entries()) {
					searchParams.append(pair[0], pair[1]);
				}
				searchParams.append('security', '<?php echo wp_create_nonce( "osm_tester_call_api" ); ?>');

				const response = await fetch('osm-tester.php?ajax_action=call_api', {
					method: 'POST',
					body: searchParams,
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					}
				});

				const result = await response.json();

				if (!result.success) {
					alert('Error: ' + (result.data ? result.data.message : 'Unknown error'));
					return;
				}

				const data = result.data;
				idleView.classList.add('hidden');
				responseContainer.classList.remove('hidden');

				// Request details
				requestDetails.innerText = `${data.request.method} ${data.request.url}`;

				if (data.error) {
					statusBadge.innerText = 'WP Error';
					statusBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-800 text-red-100';
					responseBody.innerText = data.error;
					responseHeaders.innerText = '';
					durationBadge.innerText = '';
					rateLimitInfo.innerText = '';
					return;
				}

				// Status code badge
				const code = data.response.status_code;
				statusBadge.innerText = `HTTP ${code}`;
				statusBadge.classList.remove('hidden');
				if (code >= 200 && code < 300) {
					statusBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-900 text-green-100';
				} else if (code >= 400) {
					statusBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-900 text-red-100';
				} else {
					statusBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-yellow-900 text-yellow-100';
				}

				// Duration badge
				durationBadge.innerText = `${data.response.duration_ms} ms`;

				// Headers
				responseHeaders.innerText = JSON.stringify(data.response.headers, null, 2);

				// Body
				if (data.response.is_json) {
					responseBody.innerText = JSON.stringify(data.response.body, null, 2);
				} else {
					responseBody.innerText = data.response.body;
				}

				// Deprecated warning
				const headers = data.response.headers;
				// Check for case-insensitive headers
				const depVal = headers['x-deprecated'] || headers['X-Deprecated'] || headers['x-deprecated-warning'];
				if (depVal) {
					deprecatedWarning.innerText = `Warning: This endpoint is deprecated! OSM returned: "${depVal}"`;
					deprecatedWarning.classList.remove('hidden');
				}

				// Blocked warning
				const blockVal = headers['x-blocked'] || headers['X-Blocked'];
				if (blockVal) {
					blockedWarning.innerText = `Warning: API request was blocked! OSM returned: "${blockVal}"`;
					blockedWarning.classList.remove('hidden');
				}

				// Rate Limit Info
				const limit = headers['x-ratelimit-limit'] || headers['X-Ratelimit-Limit'] || 'N/A';
				const remaining = headers['x-ratelimit-remaining'] || headers['X-Ratelimit-Remaining'] || 'N/A';
				const reset = headers['x-ratelimit-reset'] || headers['X-Ratelimit-Reset'] || 'N/A';
				rateLimitInfo.innerHTML = `
					<strong>Rate Limits:</strong> Limit: <span class="font-mono text-slate-300">${limit}</span> | 
					Remaining: <span class="font-mono ${remaining !== 'N/A' && parseInt(remaining) < 10 ? 'text-red-400 font-bold' : 'text-slate-300'}">${remaining}</span> | 
					Reset: <span class="font-mono text-slate-300">${reset}</span>
				`;

			} catch (err) {
				alert('Fetch Error: ' + err.message);
			} finally {
				submitBtn.disabled = false;
				submitBtn.innerText = 'Send Request';
			}
		});
	</script>
</body>
</html>
