<?php
namespace EMS\Core;

use EMS\Contracts\BackupContributorInterface;

class Backup_Manager {

	/**
	 * Default EMS database tables without prefix.
	 */
	public const DEFAULT_TABLES = array(
		'ems_osm_explorers',
		'ems_units',
		'ems_unit_patrols',
		'ems_participant_signups',
		'ems_expedition_signups',
		'ems_team_members',
		'ems_volunteers',
		'ems_volunteer_availability',
		'ems_route_submissions',
		'ems_audit_logs',
	);

	/**
	 * Default EMS custom post types.
	 */
	public const DEFAULT_CPTS = array(
		'expedition',
		'team',
	);

	/**
	 * Registered backup contributor instances.
	 *
	 * @var array<BackupContributorInterface>
	 */
	private array $contributors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->register_cli_commands();
	}

	/**
	 * Register a backup contributor.
	 *
	 * @param BackupContributorInterface $contributor
	 */
	public function register_contributor( BackupContributorInterface $contributor ): void {
		$this->contributors[] = $contributor;
	}

	/**
	 * Get all registered backup contributors.
	 *
	 * @return array<BackupContributorInterface>
	 */
	public function get_contributors(): array {
		return $this->contributors;
	}

	/**
	 * Get combined list of backup table names without prefix.
	 *
	 * @return array<string>
	 */
	public function get_all_tables(): array {
		$tables = self::DEFAULT_TABLES;
		foreach ( $this->contributors as $contributor ) {
			$tables = array_merge( $tables, $contributor->getBackupTables() );
		}
		return array_values( array_unique( $tables ) );
	}

	/**
	 * Get combined list of backup custom post types.
	 *
	 * @return array<string>
	 */
	public function get_all_cpts(): array {
		$cpts = self::DEFAULT_CPTS;
		foreach ( $this->contributors as $contributor ) {
			$cpts = array_merge( $cpts, $contributor->getBackupPostTypes() );
		}
		return array_values( array_unique( $cpts ) );
	}

	/**
	 * Get option names declared by contributors.
	 *
	 * @return array<string>
	 */
	public function get_contributor_option_names(): array {
		$options = array();
		foreach ( $this->contributors as $contributor ) {
			$options = array_merge( $options, $contributor->getBackupOptionNames() );
		}
		return array_values( array_unique( $options ) );
	}

	/**
	 * Export full system backup data.
	 *
	 * @return array<string, mixed>
	 */
	public function export_backup(): array {
		/** @var \wpdb|null $wpdb */
		global $wpdb;

		$backup = array(
			'version'      => '1.0.0',
			'exported_at'  => current_time( 'mysql' ) ?: gmdate( 'Y-m-d H:i:s' ),
			'options'      => array(),
			'tables'       => array(),
			'cpts'         => array(),
			'contributors' => array(),
		);

		// 1. Export options matching ems_* and contributor options
		$options_to_export = array();
		if ( isset( $wpdb ) && ! empty( $wpdb->options ) ) {
			$like = $wpdb->esc_like( 'ems_' ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$opt_name = (string) ( $row['option_name'] ?? '' );
					if ( $opt_name !== '' ) {
						$options_to_export[ $opt_name ] = get_option( $opt_name );
					}
				}
			}
		}

		foreach ( $this->get_contributor_option_names() as $opt_name ) {
			if ( ! array_key_exists( $opt_name, $options_to_export ) ) {
				$options_to_export[ $opt_name ] = get_option( $opt_name );
			}
		}
		$backup['options'] = $options_to_export;

		// 2. Export database tables
		if ( isset( $wpdb ) ) {
			foreach ( $this->get_all_tables() as $table_name ) {
				$full_table = $wpdb->prefix . $table_name;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( "SELECT * FROM {$full_table}", ARRAY_A );
				$backup['tables'][ $table_name ] = is_array( $rows ) ? $rows : array();
			}
		}

		// 3. Export custom post types
		foreach ( $this->get_all_cpts() as $cpt ) {
			$posts = get_posts(
				array(
					'post_type'      => $cpt,
					'post_status'    => 'any',
					'posts_per_page' => -1,
				)
			);
			$cpt_entries = array();
			if ( is_array( $posts ) ) {
				foreach ( $posts as $post ) {
					$post_id   = (int) $post->ID;
					$meta      = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id ) : array();
					$cpt_entries[] = array(
						'post' => (array) $post,
						'meta' => is_array( $meta ) ? $meta : array(),
					);
				}
			}
			$backup['cpts'][ $cpt ] = $cpt_entries;
		}

		// 4. Export contributor data
		foreach ( $this->contributors as $contributor ) {
			$class_key = get_class( $contributor );
			$backup['contributors'][ $class_key ] = $contributor->exportData();
		}

		return $backup;
	}

	/**
	 * Import backup data.
	 *
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function import_backup( array $data ): bool {
		/** @var \wpdb|null $wpdb */
		global $wpdb;

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		// 1. Ensure core tables exist
		if ( defined( 'ABSPATH' ) && class_exists( Table_Installer::class ) ) {
			$installer = new Table_Installer();
			$installer->install();
		}

		// 2. Restore Options
		if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
			foreach ( $data['options'] as $name => $value ) {
				$opt_name = (string) $name;
				if ( $value === null ) {
					delete_option( $opt_name );
				} else {
					update_option( $opt_name, $value );
				}
			}
		}

		// 3. Restore Tables
		if ( isset( $wpdb ) && ! empty( $data['tables'] ) && is_array( $data['tables'] ) ) {
			$allowed_tables = $this->get_all_tables();
			foreach ( $data['tables'] as $table_name => $rows ) {
				if ( ! in_array( $table_name, $allowed_tables, true ) ) {
					continue;
				}
				$full_table = $wpdb->prefix . $table_name;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "TRUNCATE TABLE {$full_table}" );

				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( is_array( $row ) ) {
							$wpdb->insert( $full_table, $row );
						}
					}
				}
			}
		}

		// 4. Restore CPTs
		if ( ! empty( $data['cpts'] ) && is_array( $data['cpts'] ) ) {
			$allowed_cpts = $this->get_all_cpts();
			foreach ( $data['cpts'] as $cpt => $items ) {
				if ( ! in_array( $cpt, $allowed_cpts, true ) || ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $item ) {
					$post_arr = $item['post'] ?? null;
					if ( ! is_array( $post_arr ) ) {
						continue;
					}
					$post_arr['post_type'] = $cpt;
					unset( $post_arr['ID'] );

					$new_id = wp_insert_post( $post_arr );
					if ( $new_id > 0 && ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
						foreach ( $item['meta'] as $meta_key => $meta_values ) {
							if ( is_array( $meta_values ) ) {
								foreach ( $meta_values as $val ) {
									add_post_meta( (int) $new_id, (string) $meta_key, maybe_unserialize( $val ) );
								}
							}
						}
					}
				}
			}
		}

		// 5. Restore Contributor Data
		if ( ! empty( $data['contributors'] ) && is_array( $data['contributors'] ) ) {
			foreach ( $this->contributors as $contributor ) {
				$class_key = get_class( $contributor );
				if ( isset( $data['contributors'][ $class_key ] ) && is_array( $data['contributors'][ $class_key ] ) ) {
					$contributor->importData( $data['contributors'][ $class_key ] );
				}
			}
		}

		return true;
	}

	/**
	 * Generate parity manifest for environment verification.
	 *
	 * @return array<string, mixed>
	 */
	public function generate_parity_manifest(): array {
		/** @var \wpdb|null $wpdb */
		global $wpdb;

		$manifest = array(
			'generated_at'   => current_time( 'mysql' ) ?: gmdate( 'Y-m-d H:i:s' ),
			'tables'         => array(),
			'cpts'           => array(),
			'options_count'  => 0,
			'checksum'       => '',
		);

		// Tables count
		if ( isset( $wpdb ) ) {
			foreach ( $this->get_all_tables() as $table_name ) {
				$full_table = $wpdb->prefix . $table_name;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full_table}" );
				$manifest['tables'][ $table_name ] = array(
					'count' => $count,
				);
			}
		}

		// CPTs count
		foreach ( $this->get_all_cpts() as $cpt ) {
			$counts = wp_count_posts( $cpt );
			$total  = 0;
			if ( is_object( $counts ) ) {
				foreach ( (array) $counts as $status => $c ) {
					$total += (int) $c;
				}
			}
			$manifest['cpts'][ $cpt ] = array(
				'count' => $total,
			);
		}

		// Options count
		if ( isset( $wpdb ) && ! empty( $wpdb->options ) ) {
			$like = $wpdb->esc_like( 'ems_' ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			);
			$manifest['options_count'] = (int) $wpdb->get_var( $sql );
		}

		$manifest['checksum'] = md5( (string) wp_json_encode( array(
			'tables'  => $manifest['tables'],
			'cpts'    => $manifest['cpts'],
			'options' => $manifest['options_count'],
		) ) );

		return $manifest;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'ems/v1',
			'/backups/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_export_backup' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/backups/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_import_backup' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			'ems/v1',
			'/system/parity-manifest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_parity_manifest' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * REST permission callback.
	 *
	 * @return bool
	 */
	public function check_admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST handler for export.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_export_backup(): \WP_REST_Response {
		return rest_ensure_response( $this->export_backup() );
	}

	/**
	 * REST handler for import.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_import_backup( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid backup data' ), 400 );
		}
		$success = $this->import_backup( $data );
		return rest_ensure_response( array( 'success' => $success ) );
	}

	/**
	 * REST handler for parity manifest.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_parity_manifest(): \WP_REST_Response {
		return rest_ensure_response( $this->generate_parity_manifest() );
	}

	/**
	 * Register WP-CLI commands when WP_CLI is available.
	 */
	public function register_cli_commands(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			call_user_func(
				array( '\WP_CLI', 'add_command' ),
				'ems backup',
				new Backup_CLI_Command( $this )
			);
		}
	}
}

/**
 * CLI Command handler for EMS backups.
 */
class Backup_CLI_Command {

	private Backup_Manager $manager;

	public function __construct( Backup_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Export EMS backup to stdout or file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Output file path.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$data = $this->manager->export_backup();
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT );
		$file = $assoc_args['file'] ?? null;
		if ( $file ) {
			file_put_contents( (string) $file, $json );
			call_user_func( array( '\WP_CLI', 'success' ), "Backup exported to {$file}" );
		} else {
			call_user_func( array( '\WP_CLI', 'line' ), $json );
		}
	}

	/**
	 * Import EMS backup from a file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file path.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = $args[0] ?? null;
		if ( ! $file || ! file_exists( (string) $file ) ) {
			call_user_func( array( '\WP_CLI', 'error' ), "File not found: {$file}" );
			return;
		}
		$contents = file_get_contents( (string) $file );
		$data     = json_decode( (string) $contents, true );
		if ( ! is_array( $data ) ) {
			call_user_func( array( '\WP_CLI', 'error' ), 'Invalid JSON in backup file.' );
			return;
		}
		$success = $this->manager->import_backup( $data );
		if ( $success ) {
			call_user_func( array( '\WP_CLI', 'success' ), 'Backup imported successfully.' );
		} else {
			call_user_func( array( '\WP_CLI', 'error' ), 'Backup import failed.' );
		}
	}

	/**
	 * Generate parity manifest.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function parity( array $args, array $assoc_args ): void {
		$manifest = $this->manager->generate_parity_manifest();
		call_user_func( array( '\WP_CLI', 'line' ), (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
	}
}
