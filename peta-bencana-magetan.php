<?php
/**
 * Plugin Name:       Peta Bencana Magetan
 * Description:       Peta bencana per desa Kabupaten Magetan. Seluruh kolom tabel mengikuti berkas Excel register bencana (tanpa kolom statistik BNPB nasional).
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kominfo
 * License:           GPL-2.0-or-later
 * Text Domain:       peta-bencana-magetan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PBM_VERSION', '2.0.0' );
define( 'PBM_FILE', __FILE__ );
define( 'PBM_PATH', plugin_dir_path( __FILE__ ) );
define( 'PBM_URL', plugin_dir_url( __FILE__ ) );

require_once PBM_PATH . 'includes/class-pbm-skema.php';
require_once PBM_PATH . 'includes/class-pbm-activator.php';
require_once PBM_PATH . 'includes/class-pbm-desa.php';
require_once PBM_PATH . 'includes/class-pbm-laporan.php';
require_once PBM_PATH . 'includes/class-pbm-xlsx.php';
require_once PBM_PATH . 'includes/class-pbm-import.php';
require_once PBM_PATH . 'includes/class-pbm-admin.php';
require_once PBM_PATH . 'includes/class-pbm-rest.php';
require_once PBM_PATH . 'includes/class-pbm-frontend.php';

register_activation_hook( __FILE__, array( 'PBM_Activator', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		PBM_Import::init();
		PBM_Admin::init();
		PBM_Rest::init();
		PBM_Frontend::init();
	}
);