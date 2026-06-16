<?php
namespace EMS\Tests\Unit\Integrations;

use EMS\Integrations\OSM_Parser;
use EMS\Tests\EMSTestCase;

class OSM_ParserTest extends EMSTestCase {
    private OSM_Parser $parser;
    private array $explorer_payload;
    private array $parent_payload;
    private array $events_raw;
    private array $members_raw;

    protected function setUp(): void {
        parent::setUp();
        $this->parser           = new OSM_Parser();
        $this->explorer_payload = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-get-data-payload-explorer.json' ),
            true
        );
        $this->parent_payload   = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-get-data-payload-parent.json' ),
            true
        );
        $this->events_raw       = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-events.json' ),
            true
        );
        $this->members_raw      = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-list-of-members.json' ),
            true
        );
    }

    public function test_parse_user_id_from_explorer_payload(): void {
        $this->assertSame( 20001, $this->parser->parse_user_id( $this->explorer_payload ) );
    }

    public function test_parse_user_id_from_parent_payload(): void {
        $this->assertSame( 20002, $this->parser->parse_user_id( $this->parent_payload ) );
    }

    public function test_parse_access_type_returns_member_for_explorer(): void {
        $this->assertSame( 'member', $this->parser->parse_access_type( $this->explorer_payload ) );
    }

    public function test_parse_access_type_returns_parent_for_parent(): void {
        $this->assertSame( 'parent', $this->parser->parse_access_type( $this->parent_payload ) );
    }

    public function test_parse_scout_ids_returns_unique_ids_for_explorer(): void {
        $ids = $this->parser->parse_scout_ids( $this->explorer_payload );
        $this->assertContains( 30001, $ids );
        $this->assertCount( 1, $ids );
    }

    public function test_parse_scout_ids_returns_unique_ids_for_parent(): void {
        $ids = $this->parser->parse_scout_ids( $this->parent_payload );
        $this->assertContains( 30001, $ids );
        $this->assertContains( 30002, $ids );
        $this->assertCount( 2, $ids );
    }

    public function test_parse_section_ids_returns_all_section_ids(): void {
        $ids = $this->parser->parse_section_ids( $this->explorer_payload );
        $this->assertContains( 99001, $ids );
        $this->assertContains( 99002, $ids );
    }

    public function test_parse_children_returns_empty_for_member(): void {
        $children = $this->parser->parse_children( $this->explorer_payload );
        $this->assertSame( [], $children );
    }

    public function test_parse_children_returns_unique_children_for_parent(): void {
        $children = $this->parser->parse_children( $this->parent_payload );
        $this->assertCount( 2, $children );
        $scout_ids = array_column( $children, 'scout_id' );
        $this->assertContains( 30001, $scout_ids );
        $this->assertContains( 30002, $scout_ids );
    }

    public function test_parse_children_entry_has_required_keys(): void {
        $children = $this->parser->parse_children( $this->parent_payload );
        $child    = $children[0];
        $this->assertArrayHasKey( 'scout_id',    $child );
        $this->assertArrayHasKey( 'first_name',  $child );
        $this->assertArrayHasKey( 'last_name',   $child );
        $this->assertArrayHasKey( 'section_ids', $child );
    }

    public function test_parse_children_aggregates_section_ids_for_same_child(): void {
        $children  = $this->parser->parse_children( $this->parent_payload );
        $alex      = array_values( array_filter( $children, fn( $c ) => $c['scout_id'] === 30001 ) )[0];
        $this->assertContains( 99001, $alex['section_ids'] );
        $this->assertContains( 99002, $alex['section_ids'] );
    }

    public function test_parse_events_returns_event_list(): void {
        $events = $this->parser->parse_events( $this->events_raw );
        $this->assertCount( 2, $events );
    }

    public function test_parse_events_normalises_fields(): void {
        $events = $this->parser->parse_events( $this->events_raw );
        $event  = $events[0];
        $this->assertArrayHasKey( 'event_id',   $event );
        $this->assertArrayHasKey( 'name',        $event );
        $this->assertArrayHasKey( 'start_date',  $event );
        $this->assertArrayHasKey( 'end_date',    $event );
        $this->assertArrayHasKey( 'location',    $event );
    }

    public function test_parse_events_maps_ids_and_names_correctly(): void {
        $events = $this->parser->parse_events( $this->events_raw );
        $this->assertSame( 40001, $events[0]['event_id'] );
        $this->assertIsString( $events[0]['name'] );
        $this->assertIsString( $events[0]['start_date'] );
        $this->assertIsString( $events[0]['end_date'] );
    }

    public function test_parse_members_returns_member_list(): void {
        $members = $this->parser->parse_members( $this->members_raw );
        $this->assertGreaterThan( 80, count( $members ) );
    }

    public function test_parse_members_normalises_fields(): void {
        $members = $this->parser->parse_members( $this->members_raw );
        $member  = $members[0];
        $this->assertArrayHasKey( 'member_id',  $member );
        $this->assertArrayHasKey( 'first_name', $member );
        $this->assertArrayHasKey( 'last_name',  $member );
        $this->assertArrayHasKey( 'patrol',     $member );
        $this->assertArrayHasKey( 'patrol_id',  $member );
        $this->assertArrayNotHasKey( 'email', $member );
    }

    public function test_parse_members_casts_member_id_to_int(): void {
        $members = $this->parser->parse_members( $this->members_raw );
        $this->assertSame( 3417257, $members[0]['member_id'] );
        $this->assertIsInt( $members[0]['member_id'] );
    }

    public function test_parse_terms_extracts_terms_by_section(): void {
        $payload = [
            'data' => [
                'globals' => [
                    'terms' => [
                        '99001' => [
                            [ 'termid' => '5001', 'sectionid' => '99001', 'name' => 'Spring 2026', 'startdate' => '2026-01-01', 'enddate' => '2026-07-31' ],
                        ],
                    ],
                ],
            ],
        ];
        $terms = $this->parser->parse_terms( $payload );
        $this->assertArrayHasKey( 99001, $terms );
        $this->assertSame( 5001, $terms[99001][0]['term_id'] );
        $this->assertSame( '2026-01-01', $terms[99001][0]['start'] );
    }

    public function test_find_current_term_returns_active_term(): void {
        $terms = [
            99001 => [
                [ 'term_id' => 4000, 'name' => 'Autumn 2025', 'start' => '2025-09-01', 'end' => '2025-12-31' ],
                [ 'term_id' => 5001, 'name' => 'Spring 2026', 'start' => '2026-01-01', 'end' => '2026-07-31' ],
            ],
        ];
        $term = $this->parser->find_current_term( $terms, 99001, '2026-03-15' );
        $this->assertNotNull( $term );
        $this->assertSame( 5001, $term['term_id'] );
    }

    public function test_find_current_term_falls_back_to_most_recent_past_term(): void {
        $terms = [
            99001 => [
                [ 'term_id' => 4000, 'name' => 'Autumn 2025', 'start' => '2025-09-01', 'end' => '2025-12-31' ],
            ],
        ];
        $term = $this->parser->find_current_term( $terms, 99001, '2026-06-01' );
        $this->assertNotNull( $term );
        $this->assertSame( 4000, $term['term_id'] );
    }

    public function test_find_current_term_returns_null_for_unknown_section(): void {
        $term = $this->parser->find_current_term( [], 99001, '2026-06-01' );
        $this->assertNull( $term );
    }

    public function test_parse_member_detail_extracts_emails_from_group6(): void {
        $map    = json_decode(
            file_get_contents( __DIR__ . '/../../mocks/osm-member-detail.json' ),
            true
        );
        $scout_id = array_key_first( $map );
        $entry    = $map[ $scout_id ];
        $this->assertSame( "scout.{$scout_id}@example-ems.test", $entry['email'] );
        $this->assertSame( "parent.{$scout_id}@example-ems.test", $entry['parent_email'] );
    }

    public function test_parse_member_detail_returns_empty_strings_when_group6_absent(): void {
        $detail = $this->parser->parse_member_detail( [ 'data' => [] ] );
        $this->assertSame( '', $detail['email'] );
        $this->assertSame( '', $detail['parent_email'] );
    }

    public function test_parse_section_names_uses_sectionname_not_section_type(): void {
        $payload = [
            'data' => [
                'globals' => [
                    'roles' => [
                        [
                            'sectionid'   => '37458',
                            'sectionname' => 'Bore Stane ESU',
                            'section'     => 'explorers',
                        ],
                    ],
                    'member_access' => [],
                    'terms'         => [],
                ],
            ],
        ];

        $names = $this->parser->parse_section_names( $payload );

        $this->assertSame( 'Bore Stane ESU', $names[37458]['name'] );
        $this->assertNotSame( 'explorers', $names[37458]['name'] );
        $this->assertSame( 'explorers', $names[37458]['type'] );
    }
}
