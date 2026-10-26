<?php
namespace EMS\Data;

class Unit_Repository {
	private ?object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( $wpdb === null ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Sync patrol from OSM (Insert or update metadata while preserving admin configurations)
	 */
	public function sync_patrol( array $data ): int {
		if ( empty( $data['patrol_id'] ) || empty( $data['section_id'] ) ) {
			throw new \InvalidArgumentException( 'patrol_id and section_id are required for syncing' );
		}

		$now        = current_time( 'mysql' );
		$patrol_id  = (int) $data['patrol_id'];
		$section_id = (int) $data['section_id'];
		$name       = sanitize_text_field( $data['name'] ?? '' );
		$active     = isset( $data['active'] ) ? (int) $data['active'] : 1;
		$synced_at  = $data['synced_at'] ?? $now;

		// 1. Find all active units to run matching logic
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units        = $this->wpdb->get_results( "SELECT * FROM {$this->wpdb->prefix}ems_units", ARRAY_A ) ?: array();
		$matched_unit = $this->find_matching_unit( $name, $units );
		$unit_id      = $matched_unit ? (int) $matched_unit['unit_id'] : null;

		// 2. Insert or update the patrol in ems_unit_patrols
		$sql = "INSERT INTO {$this->wpdb->prefix}ems_unit_patrols 
            (unit_id, section_id, patrol_id, name, active, synced_at) 
            VALUES (%d, %d, %d, %s, %d, %s) 
            ON DUPLICATE KEY UPDATE 
            unit_id = VALUES(unit_id),
            name = VALUES(name), 
            active = VALUES(active), 
            synced_at = VALUES(synced_at)";

		$prepared = $this->wpdb->prepare(
			$sql,
			$unit_id,
			$section_id,
			$patrol_id,
			$name,
			$active,
			$synced_at
		);

		$this->wpdb->query( $prepared );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_unit_patrols WHERE patrol_id = %d AND section_id = %d",
				$patrol_id,
				$section_id
			)
		);
	}

	/**
	 * Find a master unit matching a patrol name.
	 */
	public function find_matching_unit( string $patrol_name, array $master_units ): ?array {
		$patrol_clean = strtolower( trim( $patrol_name ) );
		if ( empty( $patrol_clean ) ) {
			return null;
		}

		// 1. Exact match on short_code (case-insensitive)
		foreach ( $master_units as $unit ) {
			if ( ! empty( $unit['short_code'] ) && strtolower( trim( $unit['short_code'] ) ) === $patrol_clean ) {
				return $unit;
			}
		}

		// 2. Exact match on unit name (case-insensitive)
		foreach ( $master_units as $unit ) {
			if ( ! empty( $unit['name'] ) && strtolower( trim( $unit['name'] ) ) === $patrol_clean ) {
				return $unit;
			}
		}

		// 3. Substring match: unit name contains patrol name, or vice versa
		foreach ( $master_units as $unit ) {
			$unit_name_clean  = ! empty( $unit['name'] ) ? strtolower( trim( $unit['name'] ) ) : '';
			$short_code_clean = ! empty( $unit['short_code'] ) ? strtolower( trim( $unit['short_code'] ) ) : '';

			if ( ( $unit_name_clean !== '' && str_contains( $unit_name_clean, $patrol_clean ) ) ||
				 ( $short_code_clean !== '' && str_contains( $short_code_clean, $patrol_clean ) ) ) {
				return $unit;
			}
		}

		// 4. Substring match: patrol name contains unit name or short code
		foreach ( $master_units as $unit ) {
			$unit_name_clean  = ! empty( $unit['name'] ) ? strtolower( trim( $unit['name'] ) ) : '';
			$short_code_clean = ! empty( $unit['short_code'] ) ? strtolower( trim( $unit['short_code'] ) ) : '';

			if ( ( $unit_name_clean !== '' && str_contains( $patrol_clean, $unit_name_clean ) ) ||
				 ( $short_code_clean !== '' && str_contains( $patrol_clean, $short_code_clean ) ) ) {
				return $unit;
			}
		}

		return null;
	}

	/**
	 * Update custom mapping configurations manually set by administrator
	 */
	public function update_custom_mappings( int $id, array $data ): bool {
		if ( isset( $data['leader_email'] ) && ! empty( $data['leader_email'] ) && ! is_email( $data['leader_email'] ) ) {
			throw new \InvalidArgumentException( 'Invalid leader email format' );
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);

		$format = array( '%s' );

		if ( array_key_exists( 'unit_id', $data ) ) {
			$update_data['unit_id'] = empty( $data['unit_id'] ) ? null : (int) $data['unit_id'];
			$format[]               = '%d';
		}
		if ( isset( $data['short_code'] ) ) {
			$update_data['short_code'] = sanitize_text_field( $data['short_code'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$format[]            = '%s';
		}
		if ( isset( $data['district'] ) ) {
			$update_data['district'] = sanitize_text_field( $data['district'] );
			$format[]                = '%s';
		}
		if ( isset( $data['leader_email'] ) ) {
			$update_data['leader_email'] = sanitize_text_field( $data['leader_email'] );
			$format[]                    = '%s';
		}

		$updated = $this->wpdb->update(
			$this->wpdb->prefix . 'ems_units',
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return $updated !== false;
	}

	/**
	 * Adds a master unit
	 *
	 * @param array $data The unit data.
	 * @return int The generated unit database ID.
	 */
	public function add_custom_unit( array $data ): int {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( empty( $name ) ) {
			throw new \InvalidArgumentException( 'Unit name is required.' );
		}

		$unit_id = isset( $data['unit_id'] ) ? (int) $data['unit_id'] : null;
		if ( ! $unit_id ) {
			throw new \InvalidArgumentException( 'Unit ID is required.' );
		}

		$insert_data = array(
			'unit_id'      => $unit_id,
			'district'     => sanitize_text_field( $data['district'] ?? '' ),
			'name'         => $name,
			'short_code'   => sanitize_text_field( $data['short_code'] ?? $name ),
			'leader_email' => sanitize_text_field( $data['leader_email'] ?? '' ),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		$format = array(
			'%d', // unit_id
			'%s', // district
			'%s', // name
			'%s', // short_code
			'%s', // leader_email
			'%s', // created_at
			'%s', // updated_at
		);

		$this->wpdb->insert( $this->wpdb->prefix . 'ems_units', $insert_data, $format );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d",
				$unit_id
			)
		);
	}

	/**
	 * Deletes a master unit
	 *
	 * @param int $id The unit database ID.
	 * @return bool True if deleted successfully, false otherwise.
	 */
	public function delete_custom_unit( int $id ): bool {
		$unit = $this->find_by_id( $id );
		if ( ! $unit ) {
			return false;
		}

		$unit_id = (int) $unit['unit_id'];

		if ( $unit_id ) {
			$this->wpdb->update(
				$this->wpdb->prefix . 'ems_unit_patrols',
				array( 'unit_id' => null ),
				array( 'unit_id' => $unit_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		$deleted = $this->wpdb->delete(
			$this->wpdb->prefix . 'ems_units',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $deleted !== false;
	}

	public function find_by_id( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function find_by_patrol_section( int $patrol_id, int $section_id ): ?array {
		$patrol = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT unit_id FROM {$this->wpdb->prefix}ems_unit_patrols WHERE patrol_id = %d AND section_id = %d LIMIT 1",
				$patrol_id,
				$section_id
			),
			ARRAY_A
		);

		if ( empty( $patrol ) || empty( $patrol['unit_id'] ) ) {
			return null;
		}

		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE unit_id = %d LIMIT 1",
				$patrol['unit_id']
			),
			ARRAY_A
		) ?: null;
	}

	public function list_active_units(): array {
		/** @var array<int, array<string, mixed>> $units */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$units = $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}ems_units ORDER BY name",
			ARRAY_A
		) ?: array();

		foreach ( $units as &$u ) {
			$u['matched_patrols'] = array();
			$unit_id              = (int) ( $u['unit_id'] ?? 0 );
			if ( $unit_id ) {
				$patrols = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->wpdb->prefix}ems_unit_patrols WHERE unit_id = %d AND active = 1",
						$unit_id
					),
					ARRAY_A
				) ?: array();
				$u['matched_patrols'] = $patrols;
			}
		}

		return $units;
	}

	public function find_by_short_code( string $short_code ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ems_units WHERE short_code = %s LIMIT 1",
				$short_code
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Consolidates duplicate master units sharing the same name or short code.
	 *
	 * Merges all matched patrols from duplicate units into the primary unit
	 * and removes duplicate master unit records.
	 *
	 * @return array{merged_count: int, details: array<string>}
	 */
	public function consolidate_duplicate_units(): array {
		$units  = $this->list_active_units();
		$groups = array();

		// Group by normalized name
		foreach ( $units as $u ) {
			$norm_name = strtolower( trim( (string) $u['name'] ) );
			$groups[ $norm_name ][] = $u;
		}

		$merged_count = 0;
		$details      = array();

		foreach ( $groups as $norm_name => $unit_list ) {
			if ( count( $unit_list ) <= 1 ) {
				continue;
			}

			// Choose primary unit: prefer one with district / leader_email or lowest ID
			usort(
				$unit_list,
				function( $a, $b ) {
					$score_a = ( ! empty( $a['district'] ) ? 2 : 0 ) + ( ! empty( $a['leader_email'] ) ? 1 : 0 );
					$score_b = ( ! empty( $b['district'] ) ? 2 : 0 ) + ( ! empty( $b['leader_email'] ) ? 1 : 0 );
					if ( $score_a !== $score_b ) {
						return $score_b <=> $score_a;
					}
					return (int) $a['id'] <=> (int) $b['id'];
				}
			);

			$primary            = $unit_list[0];
			$primary_unit_id    = (int) $primary['unit_id'];
			$duplicates         = array_slice( $unit_list, 1 );
			$duplicate_ids      = array();
			$duplicate_unit_ids = array();

			foreach ( $duplicates as $dup ) {
				$duplicate_ids[] = (int) $dup['id'];
				if ( ! empty( $dup['unit_id'] ) ) {
					$duplicate_unit_ids[] = (int) $dup['unit_id'];
				}
			}

			// 1. Reassign all patrols pointing to duplicate unit_ids to primary_unit_id
			if ( ! empty( $duplicate_unit_ids ) ) {
				$in_unit_ids = implode( ',', array_map( 'intval', $duplicate_unit_ids ) );
				$this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE {$this->wpdb->prefix}ems_unit_patrols SET unit_id = %d WHERE unit_id IN ({$in_unit_ids})",
						$primary_unit_id
					)
				);
			}

			// 2. Delete duplicate ems_units rows
			if ( ! empty( $duplicate_ids ) ) {
				$in_ids = implode( ',', array_map( 'intval', $duplicate_ids ) );
				$this->wpdb->query( "DELETE FROM {$this->wpdb->prefix}ems_units WHERE id IN ({$in_ids})" );
			}

			$merged_count += count( $duplicates );
			$details[]     = sprintf(
				'%s: Merged %d duplicate(s) into Unit ID %d',
				$primary['name'],
				count( $duplicates ),
				$primary_unit_id
			);
		}

		return array(
			'merged_count' => $merged_count,
			'details'      => $details,
		);
	}

	/**
	 * Returns invitation and login status for a unit leader email.
	 *
	 * @param string $email The leader's email address.
	 * @return array{invited: bool, invited_at: ?string, logged_in: bool, last_login_at: ?string, user_id: ?int}
	 */
	public function get_leader_access_status( string $email ): array {
		$email = trim( $email );
		if ( $email === '' || ! is_email( $email ) ) {
			return array(
				'invited'       => false,
				'invited_at'    => null,
				'logged_in'     => false,
				'last_login_at' => null,
				'user_id'       => null,
			);
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'invited'       => false,
				'invited_at'    => null,
				'logged_in'     => false,
				'last_login_at' => null,
				'user_id'       => null,
			);
		}

		$user_id       = (int) $user->ID;
		$invited_at    = (string) get_user_meta( $user_id, 'ems_leader_invited_at', true );
		$last_login_at = (string) get_user_meta( $user_id, 'ems_last_login_at', true );

		return array(
			'invited'       => $invited_at !== '' || $last_login_at !== '',
			'invited_at'    => $invited_at !== '' ? $invited_at : null,
			'logged_in'     => $last_login_at !== '',
			'last_login_at' => $last_login_at !== '' ? $last_login_at : null,
			'user_id'       => $user_id,
		);
	}

	public const DEFAULT_INVITE_SUBJECT = '[{{site_name}}] Your Unit Leader Dashboard Access';

	public const DEFAULT_INVITE_BODY = "Hello,\n\nYou have been invited as the Unit Leader for {{unit_name}} on {{site_name}}.\n\nTo set your password and access the Unit Leader Dashboard, please visit the following address:\n{{set_password_url}}\n\nOnce you have set your password, you will be signed in and redirected to the Unit Leader Dashboard:\n{{dashboard_url}}\n\nYour login email is: {{user_email}}\n";

	/**
	 * Resolves the front-end URL for the Unit Leader Dashboard page.
	 *
	 * @return string
	 */
	public function get_leader_dashboard_url(): string {
		$page_id = (int) get_option( 'ems_leader_dashboard_page_id', 0 );
		if ( $page_id > 0 && function_exists( 'get_permalink' ) ) {
			$url = (string) get_permalink( $page_id );
			if ( $url !== '' ) {
				return $url;
			}
		}

		if ( function_exists( 'get_pages' ) && function_exists( 'get_permalink' ) ) {
			$pages = get_pages( array( 'post_status' => 'publish' ) );
			if ( is_array( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( is_object( $page ) && property_exists( $page, 'post_content' ) && str_contains( (string) $page->post_content, '[ems-leader-dashboard' ) ) {
						$url = (string) get_permalink( (int) $page->ID );
						if ( $url !== '' ) {
							return $url;
						}
					}
				}
			}
		}

		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}

	/**
	 * Formats a list of unit names with comma separation and "and" between the last two items.
	 *
	 * @param array<string> $unit_names Array of unit names.
	 * @return string Formatted string, e.g. "Kelso ESU, Peebles ESU and Gala ESU".
	 */
	public function format_unit_names_list( array $unit_names ): string {
		$clean_names = array_values(
			array_filter(
				array_map( 'trim', $unit_names ),
				function ( $name ) {
					return $name !== '';
				}
			)
		);
		$count = count( $clean_names );

		if ( $count === 0 ) {
			return 'your Explorer Scout Unit';
		}
		if ( $count === 1 ) {
			return $clean_names[0];
		}
		if ( $count === 2 ) {
			return $clean_names[0] . ' and ' . $clean_names[1];
		}

		$last = array_pop( $clean_names );
		return implode( ', ', $clean_names ) . ' and ' . $last;
	}

	/**
	 * Customizes the WordPress new-user notification email for a Unit Leader invitation
	 * using the configurable subject and body templates and placeholders.
	 *
	 * @param array<string, mixed> $email_data Default wp_new_user_notification_email array.
	 * @param object               $user       WP_User object.
	 * @param string               $blogname   Site title.
	 * @param array<string, mixed> $unit       Unit row array.
	 * @return array<string, mixed>
	 */
	public function customize_leader_notification_email( array $email_data, $user, string $blogname, array $unit = array() ): array {
		$subject_tpl = trim( (string) get_option( 'ems_leader_invite_email_subject', '' ) );
		if ( $subject_tpl === '' ) {
			$subject_tpl = self::DEFAULT_INVITE_SUBJECT;
		}

		$body_tpl = trim( (string) get_option( 'ems_leader_invite_email_body', '' ) );
		if ( $body_tpl === '' ) {
			$body_tpl = self::DEFAULT_INVITE_BODY;
		}

		$set_password_url = '';
		$orig_message     = (string) ( $email_data['message'] ?? '' );
		if ( preg_match( '#https?://[^\s]+action=rp[^\s]*#', $orig_message, $matches ) ) {
			$set_password_url = trim( $matches[0] );
		} elseif ( is_object( $user ) && isset( $user->user_login ) && function_exists( 'get_password_reset_key' ) && function_exists( 'network_site_url' ) ) {
			$key = get_password_reset_key( $user );
			if ( is_string( $key ) ) {
				$set_password_url = network_site_url( "wp-login.php?action=rp&key={$key}&login=" . rawurlencode( (string) $user->user_login ), 'login' );
			}
		}

		$user_email = ( is_object( $user ) && isset( $user->user_email ) ) ? (string) $user->user_email : (string) ( $email_data['to'] ?? '' );
		$user_email = trim( strtolower( $user_email ) );

		// Find all units associated with this leader's email
		$matching_unit_names = array();
		if ( is_object( $this->wpdb ) && $user_email !== '' && is_email( $user_email ) ) {
			$table = $this->wpdb->prefix . 'ems_units';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT name, short_code FROM {$table} WHERE LOWER(leader_email) = %s ORDER BY name ASC", $user_email ),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$n = trim( (string) ( $row['name'] ?? $row['short_code'] ?? '' ) );
					if ( $n !== '' && ! in_array( $n, $matching_unit_names, true ) ) {
						$matching_unit_names[] = $n;
					}
				}
			}
		}

		// Fallback to the single passed $unit if no rows found in DB
		if ( empty( $matching_unit_names ) && ! empty( $unit ) ) {
			$single_name = trim( (string) ( $unit['name'] ?? $unit['short_code'] ?? '' ) );
			if ( $single_name !== '' ) {
				$matching_unit_names[] = $single_name;
			}
		}

		$unit_name     = $this->format_unit_names_list( $matching_unit_names );
		$dashboard_url = $this->get_leader_dashboard_url();

		$replacements = array(
			'{{unit_name}}'        => $unit_name,
			'{{units}}'            => $unit_name,
			'{{set_password_url}}' => $set_password_url,
			'{{dashboard_url}}'    => $dashboard_url,
			'{{site_name}}'        => $blogname,
			'{{user_email}}'       => $user_email,
		);

		$email_data['subject'] = strtr( $subject_tpl, $replacements );
		$email_data['message'] = strtr( $body_tpl, $replacements );

		return $email_data;
	}

	/**
	 * Automatically logs in an ems_leader user after they set their password on wp-login.php
	 * and redirects them directly to the Unit Leader Dashboard page.
	 *
	 * @param mixed $user WP_User instance passed by after_password_reset.
	 * @return void
	 */
	public function handle_leader_password_reset_redirect( $user ): void {
		if ( ! is_object( $user ) || ! isset( $user->ID, $user->roles ) ) {
			return;
		}

		$roles = (array) $user->roles;
		if ( ! in_array( 'ems_leader', $roles, true ) || in_array( 'administrator', $roles, true ) ) {
			return;
		}

		$dashboard_url = $this->get_leader_dashboard_url();
		if ( $dashboard_url === '' ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_id );
		}
		if ( function_exists( 'wp_set_auth_cookie' ) ) {
			wp_set_auth_cookie( $user_id, true );
		}
		update_user_meta( $user_id, 'ems_last_login_at', current_time( 'mysql' ) );

		wp_safe_redirect( $dashboard_url );
		if ( ! class_exists( '\EMS\Tests\EMSTestCase' ) ) {
			exit;
		}
	}

	/**
	 * Redirects ems_leader users to the Unit Leader Dashboard upon standard WordPress login
	 * when no explicit non-admin redirect target was requested.
	 *
	 * @param string $redirect_to           The redirect destination URL.
	 * @param string $requested_redirect_to The requested redirect destination URL.
	 * @param mixed  $user                  WP_User object or WP_Error.
	 * @return string
	 */
	public function filter_leader_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( ! is_object( $user ) || ! isset( $user->roles ) ) {
			return $redirect_to;
		}

		$roles = (array) $user->roles;
		if ( ! in_array( 'ems_leader', $roles, true ) || in_array( 'administrator', $roles, true ) ) {
			return $redirect_to;
		}

		$default_admin = function_exists( 'admin_url' ) ? rtrim( (string) admin_url(), '/' ) : '';
		$req_trimmed   = rtrim( $requested_redirect_to, '/' );

		if ( $requested_redirect_to === '' || ( $default_admin !== '' && $req_trimmed === $default_admin ) ) {
			$dashboard_url = $this->get_leader_dashboard_url();
			if ( $dashboard_url !== '' ) {
				return $dashboard_url;
			}
		}

		return $redirect_to;
	}

	/**
	 * Sends a leader invitation notification for a specific user and unit, applying the custom email filter.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $unit    Unit row array.
	 * @return void
	 */
	private function send_leader_invitation_notification( int $user_id, array $unit ): void {
		$filter_cb = function ( array $email_data, $user, string $blogname ) use ( $unit ): array {
			return $this->customize_leader_notification_email( $email_data, $user, $blogname, $unit );
		};

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_new_user_notification_email', $filter_cb, 10, 3 );
		}

		wp_new_user_notification( $user_id, null, 'user' );

		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'wp_new_user_notification_email', $filter_cb, 10 );
		}
	}

	/**
	 * Lists all unique leaders with their assigned units, districts, and portal access status.
	 *
	 * @return array<string, array{
	 *     email: string,
	 *     units: array<array<string, mixed>>,
	 *     unit_names: array<string>,
	 *     districts: array<string>,
	 *     access_status: array{invited: bool, invited_at: ?string, logged_in: bool, last_login_at: ?string, user_id: ?int},
	 *     user: ?\WP_User
	 * }>
	 */
	public function list_leaders_with_units(): array {
		$units   = $this->list_active_units();
		$leaders = array();

		foreach ( $units as $u ) {
			$email = strtolower( trim( (string) ( $u['leader_email'] ?? '' ) ) );
			if ( $email === '' || ! is_email( $email ) ) {
				continue;
			}

			if ( ! isset( $leaders[ $email ] ) ) {
				$status  = $this->get_leader_access_status( $email );
				$wp_user = get_user_by( 'email', $email );
				$leaders[ $email ] = array(
					'email'         => $email,
					'units'         => array(),
					'unit_names'    => array(),
					'districts'     => array(),
					'access_status' => $status,
					'user'          => ( $wp_user instanceof \WP_User ) ? $wp_user : null,
				);
			}

			$leaders[ $email ]['units'][] = $u;

			$name = trim( (string) ( $u['name'] ?? $u['short_code'] ?? '' ) );
			if ( $name !== '' && ! in_array( $name, $leaders[ $email ]['unit_names'], true ) ) {
				$leaders[ $email ]['unit_names'][] = $name;
			}

			$dist = trim( (string) ( $u['district'] ?? '' ) );
			if ( $dist !== '' && ! in_array( $dist, $leaders[ $email ]['districts'], true ) ) {
				$leaders[ $email ]['districts'][] = $dist;
			}
		}

		ksort( $leaders );
		return $leaders;
	}

	/**
	 * Invites a leader by their email address, ensuring an ems_leader WP account exists and dispatching the notification email.
	 *
	 * @param string $email        Leader email address.
	 * @param bool   $force_resend Whether to resend the invitation even if already invited.
	 * @return bool True if an invitation was sent, false otherwise.
	 */
	public function invite_leader_by_email( string $email, bool $force_resend = false ): bool {
		$email = strtolower( trim( $email ) );
		if ( $email === '' || ! is_email( $email ) ) {
			return false;
		}

		$now  = current_time( 'mysql' );
		$user = get_user_by( 'email', $email );

		if ( $user ) {
			if ( is_callable( array( $user, 'add_role' ) ) ) {
				$user->add_role( 'ems_leader' );
			}
			$user_id       = (int) $user->ID;
			$invited_at    = (string) get_user_meta( $user_id, 'ems_leader_invited_at', true );
			$last_login_at = (string) get_user_meta( $user_id, 'ems_last_login_at', true );

			if ( ! $force_resend && ( $invited_at !== '' || $last_login_at !== '' ) ) {
				return false;
			}

			$this->send_leader_invitation_notification( $user_id, array() );
			update_user_meta( $user_id, 'ems_leader_invited_at', $now );
			return true;
		}

		$base_login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( $base_login === '' ) {
			$base_login = 'ems_leader';
		}
		$user_login = $base_login;
		$suffix     = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $base_login . '_' . $suffix;
			++$suffix;
		}

		$new_user_id = wp_insert_user(
			array(
				'user_login' => $user_login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => 'ems_leader',
			)
		);

		if ( is_wp_error( $new_user_id ) ) {
			return false;
		}

		$this->send_leader_invitation_notification( (int) $new_user_id, array() );
		update_user_meta( (int) $new_user_id, 'ems_leader_invited_at', $now );
		return true;
	}

	/**
	 * Bulk-invites unit leaders (or invites a single unit's leader) by creating/updating
	 * WordPress user accounts with the ems_leader role and sending the WP password setup email.
	 *
	 * @param int|null $only_unit_row_id Optional unit row ID to invite a single unit.
	 * @param bool     $force_resend     Whether to resend the invitation email even if already invited.
	 * @return array{invited: int, already_invited: int, skipped_no_email: int, errors: array<string>}
	 */
	public function invite_unit_leaders( ?int $only_unit_row_id = null, bool $force_resend = false ): array {
		if ( $only_unit_row_id !== null ) {
			$unit  = $this->find_by_id( $only_unit_row_id );
			$units = $unit ? array( $unit ) : array();
		} else {
			$units = $this->list_active_units();
		}

		$invited          = 0;
		$already_invited  = 0;
		$skipped_no_email = 0;
		$errors           = array();
		$seen_emails      = array();
		$now              = current_time( 'mysql' );

		foreach ( $units as $u ) {
			$email = strtolower( trim( (string) ( $u['leader_email'] ?? '' ) ) );
			if ( $email === '' || ! is_email( $email ) ) {
				++$skipped_no_email;
				continue;
			}

			if ( isset( $seen_emails[ $email ] ) ) {
				continue;
			}
			$seen_emails[ $email ] = true;

			$user = get_user_by( 'email', $email );
			if ( $user ) {
				if ( is_callable( array( $user, 'add_role' ) ) ) {
					$user->add_role( 'ems_leader' );
				}
				$user_id       = (int) $user->ID;
				$invited_at    = (string) get_user_meta( $user_id, 'ems_leader_invited_at', true );
				$last_login_at = (string) get_user_meta( $user_id, 'ems_last_login_at', true );

				if ( ! $force_resend && ( $invited_at !== '' || $last_login_at !== '' ) ) {
					++$already_invited;
					continue;
				}

				$this->send_leader_invitation_notification( $user_id, $u );
				update_user_meta( $user_id, 'ems_leader_invited_at', $now );
				++$invited;
				continue;
			}

			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			if ( $base_login === '' ) {
				$base_login = 'ems_leader';
			}
			$user_login = $base_login;
			$suffix     = 1;
			while ( username_exists( $user_login ) ) {
				$user_login = $base_login . '_' . $suffix;
				++$suffix;
			}

			$new_user_id = wp_insert_user(
				array(
					'user_login' => $user_login,
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24, true, true ),
					'role'       => 'ems_leader',
				)
			);

			if ( is_wp_error( $new_user_id ) ) {
				$errors[] = $email . ': ' . $new_user_id->get_error_message();
				continue;
			}

			$this->send_leader_invitation_notification( (int) $new_user_id, $u );
			update_user_meta( (int) $new_user_id, 'ems_leader_invited_at', $now );
			++$invited;
		}

		return array(
			'invited'          => $invited,
			'already_invited'  => $already_invited,
			'skipped_no_email' => $skipped_no_email,
			'errors'           => $errors,
		);
	}
}
