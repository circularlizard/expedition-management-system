<?php
namespace EMS\Integrations\Drivers;

interface Driver_Interface {
	public function get_data_payload(): array;
	public function get_section_members( int $section_id, int $term_id, string $section_type = 'explorers' ): array;
	public function get_section_events( int $section_id, int $term_id ): array;
	public function get_member_detail( int $section_id, int $scout_id, int $term_id ): array;
	public function get_patrols( int $section_id ): array;
	public function get_flexi_records( int $section_id ): array;
	public function get_flexi_record_structure( int $section_id, int $flexi_id ): array;
	public function get_flexi_record_data( int $section_id, int $flexi_id, int $term_id = 0 ): array;
	public function get_individual( int $section_id, int $member_id, int $term_id = 0 ): array;
	public function get_contact_details( int $section_id, int $scout_id, int $term_id ): array;
	public function get_event_attendance( int $event_id, int $term_id ): array;

	public function update_event_attendance( int $section_id, int $event_id, array $member_updates ): array;
	public function create_flexi_record( int $section_id, string $name ): array;
	public function add_flexi_record_column( int $section_id, int $flexi_id, string $column_name ): array;
	public function update_flexi_record_data( int $section_id, int $flexi_id, array $values ): array;


	/**
	 * Sets the access token for the driver to use in subsequent requests.
	 *
	 * @param string $token
	 */
	public function set_access_token( string $token ): void;

	/**
	 * Returns the HTTP headers from the last request.
	 *
	 * @return array
	 */
	public function get_last_response_headers(): array;
}

