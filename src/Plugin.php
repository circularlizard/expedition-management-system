<?php
namespace EMS;

use EMS\Admin\Training_Report_Page;
use EMS\Core\CPT_Registry;
use EMS\Core\Table_Installer;
use EMS\Integrations\TutorLMS_Client;

class Plugin {
    private CPT_Registry $cpt_registry;

    public function __construct() {
        $this->cpt_registry = new CPT_Registry();
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this->cpt_registry, 'register' ] );

        $report_page = new Training_Report_Page( new TutorLMS_Client() );
        add_action( 'admin_menu', [ $report_page, 'register' ] );
    }

    public static function activate(): void {
        ( new Table_Installer() )->install();
    }
}
