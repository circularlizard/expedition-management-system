<?php
namespace EMS;

class Plugin {
    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this, 'register_cpts' ] );
        // Further initialization logic (API, Admin, etc.) will go here
    }

    public function register_cpts(): void {
        // Registration logic for 'expedition' and 'team'
        register_post_type( 'expedition', [
            'labels'      => [ 'name' => 'Expeditions', 'singular_name' => 'Expedition' ],
            'public'      => true,
            'has_archive' => false,
            'show_in_rest' => true,
            'menu_icon'   => 'dashicons-location-alt',
            'supports'    => [ 'title', 'editor', 'custom-fields' ],
        ]);

        register_post_type( 'team', [
            'labels'      => [ 'name' => 'Teams', 'singular_name' => 'Team' ],
            'public'      => true,
            'show_in_rest' => true,
            'menu_icon'   => 'dashicons-groups',
            'supports'    => [ 'title', 'custom-fields' ],
        ]);
    }
}
