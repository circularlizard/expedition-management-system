<?php
namespace EMS\Core;

class Table_Installer {
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = $this->generate_sql( $wpdb->prefix, $wpdb->get_charset_collate() );

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$this->run_migrations( $wpdb );
	}

	/**
	 * Idempotent column/index migrations for tables that already existed before
	 * a schema change. dbDelta is unreliable at ALTERing existing tables, so we
	 * apply additive changes explicitly here.
	 */
	private function run_migrations( object $wpdb ): void {
		$table = $wpdb->prefix . 'ems_team_members';

		if ( ! $this->column_exists( $wpdb, $table, 'scout_id' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN scout_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER team_post_id" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_scout_id (scout_id)" );
		}

		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'first_aid_level' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN first_aid_level VARCHAR(30) NOT NULL DEFAULT 'none' AFTER patrol" );
		}

		if ( ! $this->column_exists( $wpdb, $explorers_table, 'last_local_update_at' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN last_local_update_at DATETIME DEFAULT NULL AFTER first_aid_level" );
		}

		if ( ! $this->column_exists( $wpdb, $explorers_table, 'last_ems_push_at' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN last_ems_push_at DATETIME DEFAULT NULL AFTER last_local_update_at" );
		}

		if ( ! $this->column_exists( $wpdb, $explorers_table, 'dofe_number' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN dofe_number VARCHAR(50) DEFAULT NULL AFTER first_aid_level" );
		}

		if ( ! $this->column_exists( $wpdb, $explorers_table, 'additional_support_needs' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN additional_support_needs TEXT DEFAULT NULL AFTER dofe_number" );
		}

		$participant_table = $wpdb->prefix . 'ems_participant_signups';
		if ( ! $this->column_exists( $wpdb, $participant_table, 'leader_email' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$participant_table} ADD COLUMN leader_email VARCHAR(100) DEFAULT NULL AFTER parent_email" );
		}

		$avail_table = $wpdb->prefix . 'ems_volunteer_availability';
		if ( ! $this->column_exists( $wpdb, $avail_table, 'volunteer_id' ) ) {
			// Add column
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$avail_table} ADD COLUMN volunteer_id BIGINT UNSIGNED NOT NULL AFTER id" );
			// Add key
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$avail_table} ADD KEY idx_volunteer_expedition (volunteer_id, expedition_post_id)" );
			// Allow user_id to be NULL as it is deprecated for guests
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$avail_table} MODIFY COLUMN user_id BIGINT UNSIGNED DEFAULT NULL" );
		}

		if ( ! $this->column_exists( $wpdb, $avail_table, 'updated_at' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$avail_table} ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER confirmed_by" );
		}

		if ( ! $this->column_exists( $wpdb, $avail_table, 'signup_type' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$avail_table} ADD COLUMN signup_type VARCHAR(20) NOT NULL DEFAULT 'part' AFTER updated_at" );
		}

		$vol_table = $wpdb->prefix . 'ems_volunteers';
		if ( ! $this->column_exists( $wpdb, $vol_table, 'constraints' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$vol_table} ADD COLUMN constraints TEXT DEFAULT NULL AFTER preferred_roles" );
		}

		$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'email1' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN email1 VARCHAR(100) NOT NULL DEFAULT '' AFTER last_name" );
		}
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'email2' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN email2 VARCHAR(100) NOT NULL DEFAULT '' AFTER email1" );
		}
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'p1_email1' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN p1_email1 VARCHAR(100) NOT NULL DEFAULT '' AFTER email2" );
		}
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'p1_email2' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN p1_email2 VARCHAR(100) NOT NULL DEFAULT '' AFTER p1_email1" );
		}
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'p2_email1' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN p2_email1 VARCHAR(100) NOT NULL DEFAULT '' AFTER p1_email2" );
		}
		if ( ! $this->column_exists( $wpdb, $explorers_table, 'p2_email2' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN p2_email2 VARCHAR(100) NOT NULL DEFAULT '' AFTER p2_email1" );
		}

		// Backfill existing emails if columns were just added
		if ( $this->column_exists( $wpdb, $explorers_table, 'email1' ) && $this->column_exists( $wpdb, $explorers_table, 'email' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$explorers_table} SET email1 = email WHERE email1 = '' AND email != ''" );
		}
		if ( $this->column_exists( $wpdb, $explorers_table, 'p1_email1' ) && $this->column_exists( $wpdb, $explorers_table, 'parent_email' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$explorers_table} SET p1_email1 = parent_email WHERE p1_email1 = '' AND parent_email != ''" );
		}

		// Migrate legacy statuses to 'submitted'
		$wpdb->query( "UPDATE {$wpdb->prefix}ems_participant_signups SET signup_status = 'submitted' WHERE signup_status IN ('received', 'allocated')" );
		$wpdb->query( "UPDATE {$wpdb->prefix}ems_expedition_signups SET signup_status = 'submitted' WHERE signup_status = 'pending'" );

		$units_table = $wpdb->prefix . 'ems_units';
		if ( ! $this->column_exists( $wpdb, $units_table, 'created_at' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$units_table} ADD COLUMN created_at DATETIME NOT NULL AFTER leader_email" );
		}

		$this->migrate_season_deprecation( $wpdb );
		$this->migrate_units_table( $wpdb );
	}

	private function column_exists( object $wpdb, string $table, string $column ): bool {
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		return ! empty( $found ) && strtolower( (string) $found ) === strtolower( $column );
	}

	private function migrate_season_deprecation( object $wpdb ): void {
		if ( get_option( 'ems_season_migration_done' ) ) {
			return;
		}

		// Detach all expedition posts from any season parent.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$wpdb->posts} SET post_parent = 0 WHERE post_type = 'expedition'"
		);

		// Collect all season post IDs before deleting.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$season_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'season'"
		);

		if ( ! empty( $season_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $season_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})",
					...$season_ids
				)
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
					...$season_ids
				)
			);
		}

		if ( ! get_option( 'ems_unallocated_migration_done' ) ) {
			$expedition_ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'expedition' AND post_status = 'publish'"
			);
			foreach ( $expedition_ids as $event_id ) {
				$event_id        = (int) $event_id;
				$has_unallocated = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = 'team' AND p.post_parent = %d
                     AND pm.meta_key = 'ems_team_code' AND pm.meta_value = 'UNALLOCATED'
                     LIMIT 1",
						$event_id
					)
				);

				if ( ! $has_unallocated ) {
					$post_id = wp_insert_post(
						array(
							'post_type'   => 'team',
							'post_title'  => 'Unallocated',
							'post_status' => 'publish',
							'post_parent' => $event_id,
						),
						true
					);

					if ( ! is_wp_error( $post_id ) ) {
						update_post_meta( $post_id, 'ems_team_code', 'UNALLOCATED' );
						update_post_meta( $post_id, 'ems_team_number', 0 );
					}
				}
			}
			update_option( 'ems_unallocated_migration_done', 1 );
		}

		update_option( 'ems_season_migration_done', 1 );
	}

	private function migrate_units_table( object $wpdb ): void {
		$units_table = $wpdb->prefix . 'ems_units';
		$patrols_table = $wpdb->prefix . 'ems_unit_patrols';

		if ( ! $this->column_exists( $wpdb, $units_table, 'patrol_id' ) ) {
			// Already migrated
			return;
		}

		// 1. Fetch old rows
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old_rows = $wpdb->get_results( "SELECT * FROM {$units_table}", ARRAY_A );

		// 2. Separate into master units and patrol mappings
		$master_units = array();
		$patrol_mappings = array();
		$next_generated_unit_id = 900000;

		if ( ! empty( $old_rows ) && is_array( $old_rows ) ) {
			foreach ( $old_rows as $row ) {
				$patrol_id = isset( $row['patrol_id'] ) ? (int) $row['patrol_id'] : 0;
				$section_id = isset( $row['section_id'] ) ? (int) $row['section_id'] : 0;
				$unit_id = ! empty( $row['unit_id'] ) ? (int) $row['unit_id'] : null;

				// Determine if this row contains unit information
				$has_unit_info = ! empty( $row['unit_id'] ) || ! empty( $row['short_code'] ) || ! empty( $row['leader_email'] );

				if ( $patrol_id < 0 ) {
					// Custom manual unit
					if ( ! $unit_id ) {
						$unit_id = $next_generated_unit_id++;
					}
					$master_key = (string) $unit_id;
					$master_units[ $master_key ] = array(
						'unit_id'      => $unit_id,
						'district'     => '',
						'name'         => $row['name'] ?? '',
						'short_code'   => $row['short_code'] ?: ( $row['name'] ?? '' ),
						'leader_email' => $row['leader_email'] ?? '',
						'created_at'   => $row['synced_at'] ?? current_time( 'mysql' ),
						'updated_at'   => $row['updated_at'] ?? null,
					);
				} else {
					// Synced patrol
					if ( $has_unit_info ) {
						if ( ! $unit_id ) {
							$unit_id = $next_generated_unit_id++;
						}
						$master_key = (string) $unit_id;
						if ( ! isset( $master_units[ $master_key ] ) ) {
							$master_units[ $master_key ] = array(
								'unit_id'      => $unit_id,
								'district'     => '',
								'name'         => $row['name'] ?? '',
								'short_code'   => $row['short_code'] ?: ( $row['name'] ?? '' ),
								'leader_email' => $row['leader_email'] ?? '',
								'created_at'   => current_time( 'mysql' ),
								'updated_at'   => $row['updated_at'] ?? null,
							);
						}
					}

					$patrol_mappings[] = array(
						'unit_id'    => $unit_id,
						'section_id' => $section_id,
						'patrol_id'  => $patrol_id,
						'name'       => $row['name'] ?? '',
						'active'     => isset( $row['active'] ) ? (int) $row['active'] : 1,
						'synced_at'  => $row['synced_at'] ?? current_time( 'mysql' ),
					);
				}
			}
		}

		// 3. Truncate units table
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$units_table}" );

		// 4. Temporarily add the district column and make columns nullable/clean so schema alterations can be run safely
		if ( ! $this->column_exists( $wpdb, $units_table, 'district' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$units_table} ADD COLUMN district VARCHAR(100) NOT NULL DEFAULT '' AFTER unit_id" );
		}

		// 5. Insert migrated master units
		foreach ( $master_units as $unit ) {
			$wpdb->insert(
				$units_table,
				$unit,
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		// 6. Insert patrol mappings
		foreach ( $patrol_mappings as $mapping ) {
			$wpdb->insert(
				$patrols_table,
				$mapping,
				array( '%d', '%d', '%d', '%s', '%d', '%s' )
			);
		}

		// 7. Drop obsolete columns from ems_units
		// Drop unique key index first if it exists to allow column drops
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$index_exists = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$units_table} WHERE Key_name = %s", 'idx_patrol_section' ) );
		if ( ! empty( $index_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$units_table} DROP INDEX idx_patrol_section" );
		}

		$cols_to_drop = array( 'patrol_id', 'section_id', 'active', 'synced_at', 'leader_first_name', 'leader_last_name' );
		foreach ( $cols_to_drop as $col ) {
			if ( $this->column_exists( $wpdb, $units_table, $col ) ) {
				try {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE {$units_table} DROP COLUMN {$col}" );
				} catch ( \Exception $e ) {
					error_log( "[EMS Migration] Failed to drop column {$col}: " . $e->getMessage() );
				}
			}
		}

		// Drop legacy table ems_unit_leaders if it exists
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ems_unit_leaders" );
	}

	public function generate_sql( string $prefix = '', string $charset = '' ): array {
		$sql = array();

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_team_members (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id BIGINT UNSIGNED NOT NULL,
            scout_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            added_by    BIGINT UNSIGNED NOT NULL,
            added_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id),
            KEY idx_scout_id (scout_id),
            KEY idx_user_id (user_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_volunteers (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            osm_user_id     BIGINT UNSIGNED          DEFAULT NULL,
            user_id         BIGINT UNSIGNED          DEFAULT NULL,
            first_name      VARCHAR(255)    NOT NULL DEFAULT '',
            last_name       VARCHAR(255)    NOT NULL DEFAULT '',
            email           VARCHAR(255)    NOT NULL DEFAULT '',
            phone           VARCHAR(50)              DEFAULT NULL,
            dbs_number      VARCHAR(100)             DEFAULT NULL,
            qualifications  LONGTEXT                 DEFAULT NULL,
            preferred_roles LONGTEXT                 DEFAULT NULL,
            constraints     TEXT                     DEFAULT NULL,
            created_at      DATETIME        NOT NULL,
            updated_at      DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_osm_user_id (osm_user_id),
            KEY idx_user_id (user_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_volunteer_availability (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            volunteer_id        BIGINT UNSIGNED NOT NULL,
            user_id             BIGINT UNSIGNED          DEFAULT NULL,
            expedition_post_id  BIGINT UNSIGNED NOT NULL,
            date                DATE            NOT NULL,
            overnight           TINYINT(1)      NOT NULL DEFAULT 0,
            confirmed           TINYINT(1)      NOT NULL DEFAULT 0,
            confirmed_by        BIGINT UNSIGNED          DEFAULT NULL,
            updated_at          DATETIME                 DEFAULT NULL,
            signup_type         VARCHAR(20)     NOT NULL DEFAULT 'part',
            PRIMARY KEY (id),
            KEY idx_volunteer_expedition (volunteer_id, expedition_post_id),
            KEY idx_user_expedition (user_id, expedition_post_id),
            KEY idx_date (date)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_route_submissions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_post_id    BIGINT UNSIGNED NOT NULL,
            version         INT             NOT NULL DEFAULT 1,
            file_type       VARCHAR(20)     NOT NULL,
            wp_media_id     BIGINT UNSIGNED NOT NULL,
            submitted_by    BIGINT UNSIGNED NOT NULL,
            submitted_at    DATETIME        NOT NULL,
            feedback        TEXT                     DEFAULT NULL,
            status          VARCHAR(30)     NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY idx_team_post_id (team_post_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_explorers (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id             BIGINT UNSIGNED NOT NULL,
            wp_user_id           BIGINT UNSIGNED          DEFAULT NULL,
            section_id           BIGINT UNSIGNED NOT NULL,
            first_name           VARCHAR(100)    NOT NULL DEFAULT '',
            last_name            VARCHAR(100)    NOT NULL DEFAULT '',
            email                VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email         VARCHAR(100)    NOT NULL DEFAULT '',
            email1               VARCHAR(100)    NOT NULL DEFAULT '',
            email2               VARCHAR(100)    NOT NULL DEFAULT '',
            p1_email1            VARCHAR(100)    NOT NULL DEFAULT '',
            p1_email2            VARCHAR(100)    NOT NULL DEFAULT '',
            p2_email1            VARCHAR(100)    NOT NULL DEFAULT '',
            p2_email2            VARCHAR(100)    NOT NULL DEFAULT '',
            patrol               VARCHAR(100)    NOT NULL DEFAULT '',
            first_aid_level      VARCHAR(30)     NOT NULL DEFAULT 'none',
            dofe_number          VARCHAR(50)              DEFAULT NULL,
            additional_support_needs TEXT                 DEFAULT NULL,
            last_local_update_at DATETIME                 DEFAULT NULL,
            last_ems_push_at     DATETIME                 DEFAULT NULL,
            synced_at            DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_scout_id (scout_id),
            KEY idx_section_id (section_id),
            KEY idx_wp_user_id (wp_user_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_audit_logs (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id                BIGINT UNSIGNED NOT NULL,
            action                 VARCHAR(100)    NOT NULL,
            target_scout_id        BIGINT UNSIGNED DEFAULT NULL,
            ip_address             VARCHAR(45)     NOT NULL,
            user_agent             VARCHAR(255)    NOT NULL,
            timestamp              DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_target_scout_id (target_scout_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_events (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id     BIGINT UNSIGNED NOT NULL,
            section_id   BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(255)    NOT NULL DEFAULT '',
            start_date   DATETIME                 DEFAULT NULL,
            end_date     DATETIME                 DEFAULT NULL,
            location     VARCHAR(255)    NOT NULL DEFAULT '',
            yes_members  INT             NOT NULL DEFAULT 0,
            yes_leaders  INT             NOT NULL DEFAULT 0,
            no           INT             NOT NULL DEFAULT 0,
            synced_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_event_section (event_id, section_id),
            KEY idx_section_id (section_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_osm_event_attendance (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id   BIGINT UNSIGNED NOT NULL,
            scout_id   BIGINT UNSIGNED NOT NULL,
            status     VARCHAR(50)     NOT NULL DEFAULT '',
            synced_at  DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_event_scout (event_id, scout_id),
            KEY idx_event_id (event_id),
            KEY idx_scout_id (scout_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_units (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id           BIGINT UNSIGNED          DEFAULT NULL,
            district          VARCHAR(100)    NOT NULL DEFAULT '',
            name              VARCHAR(100)    NOT NULL DEFAULT '',
            short_code        VARCHAR(100)             DEFAULT NULL,
            leader_email      VARCHAR(100)    NOT NULL DEFAULT '',
            created_at        DATETIME        NOT NULL,
            updated_at        DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_unit_id (unit_id),
            UNIQUE KEY idx_short_code (short_code)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_unit_patrols (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id           BIGINT UNSIGNED          DEFAULT NULL,
            section_id        BIGINT UNSIGNED NOT NULL,
            patrol_id         BIGINT          NOT NULL,
            name              VARCHAR(100)    NOT NULL DEFAULT '',
            active            TINYINT(1)      NOT NULL DEFAULT 1,
            synced_at         DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_patrol_section (patrol_id, section_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";


		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_participant_signups (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id               BIGINT UNSIGNED NOT NULL,
            parent_user_id         BIGINT UNSIGNED NOT NULL,
            unit_id                BIGINT UNSIGNED          DEFAULT NULL,
            unit_name              VARCHAR(100)             DEFAULT NULL,
            explorer_first_name    VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_last_name     VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_email         VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email           VARCHAR(100)             DEFAULT NULL,
            leader_email           VARCHAR(100)             DEFAULT NULL,
            dofe_level             VARCHAR(20)     NOT NULL,
            dob                    DATE                     DEFAULT NULL,
            dofe_registered        VARCHAR(30)     NOT NULL DEFAULT 'n',
            dofe_number            VARCHAR(20)              DEFAULT NULL,
            dofe_org               VARCHAR(100)             DEFAULT NULL,
            bronze_completion      TEXT                     DEFAULT NULL,
            silver_completion      TEXT                     DEFAULT NULL,
            signup_status          VARCHAR(30)     NOT NULL DEFAULT 'submitted',
            payment_status         VARCHAR(30)     NOT NULL DEFAULT 'pending',
            processed_by           BIGINT UNSIGNED          DEFAULT NULL,
            processed_at           DATETIME                 DEFAULT NULL,
            form_submission_id     BIGINT UNSIGNED NOT NULL,
            created_at             DATETIME        NOT NULL,
            updated_at             DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_scout_id (scout_id),
            KEY idx_parent_user_id (parent_user_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$prefix}ems_expedition_signups (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scout_id               BIGINT UNSIGNED NOT NULL,
            parent_user_id         BIGINT UNSIGNED NOT NULL,
            unit_id                BIGINT UNSIGNED          DEFAULT NULL,
            unit_name              VARCHAR(100)             DEFAULT NULL,
            explorer_first_name    VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_last_name     VARCHAR(100)    NOT NULL DEFAULT '',
            explorer_email         VARCHAR(100)    NOT NULL DEFAULT '',
            parent_email           VARCHAR(100)             DEFAULT NULL,
            leader_email           VARCHAR(100)             DEFAULT NULL,
            dofe_level             VARCHAR(20)     NOT NULL,
            expedition_preferences TEXT                     DEFAULT NULL,
            additional_support_needs TEXT                   DEFAULT NULL,
            first_aid_status       VARCHAR(30)     NOT NULL DEFAULT 'none',
            first_aid_expiry       DATE                     DEFAULT NULL,
            signup_status          VARCHAR(30)     NOT NULL DEFAULT 'submitted',
            form_submission_id     BIGINT UNSIGNED NOT NULL,
            created_at             DATETIME        NOT NULL,
            updated_at             DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_scout_id (scout_id),
            KEY idx_parent_user_id (parent_user_id),
            KEY idx_unit_id (unit_id)
        ) {$charset};";

		return $sql;
	}

	public function get_table_names(): array {
		global $wpdb;
		return array(
			'volunteers'             => $wpdb->prefix . 'ems_volunteers',
			'team_members'           => $wpdb->prefix . 'ems_team_members',
			'volunteer_availability' => $wpdb->prefix . 'ems_volunteer_availability',
			'route_submissions'      => $wpdb->prefix . 'ems_route_submissions',
			'osm_explorers'          => $wpdb->prefix . 'ems_osm_explorers',
			'osm_events'             => $wpdb->prefix . 'ems_osm_events',
			'osm_event_attendance'   => $wpdb->prefix . 'ems_osm_event_attendance',
			'units'                  => $wpdb->prefix . 'ems_units',
			'unit_patrols'           => $wpdb->prefix . 'ems_unit_patrols',
			'participant_signups'    => $wpdb->prefix . 'ems_participant_signups',
			'expedition_signups'     => $wpdb->prefix . 'ems_expedition_signups',
			'audit_logs'             => $wpdb->prefix . 'ems_audit_logs',
		);
	}
}
