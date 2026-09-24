<?php
/**
 * Plugin Name:       Wordpress events calendar
 * Plugin URI:        https://github.com/IPardelo/wp-events-calendar
 * Description:       Mostra os teus eventos dunha forma sinxela.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            IPardelo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-events-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPEC_VERSION', '1.0.0' );
define( 'WPEC_DB_VERSION', '1.1.0' );
define( 'WPEC_FILE', __FILE__ );
define( 'WPEC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPEC_URL', plugin_dir_url( __FILE__ ) );

require_once WPEC_PATH . 'includes/class-wpec-db.php';
require_once WPEC_PATH . 'includes/class-wpec-admin.php';
require_once WPEC_PATH . 'includes/class-wpec-importer.php';
require_once WPEC_PATH . 'includes/class-wpec-exporter.php';
require_once WPEC_PATH . 'includes/class-wpec-shortcode.php';

register_activation_hook( __FILE__, array( 'WPEC_DB', 'install' ) );

// Traducciones: se cargan desde /languages según el idioma de la web (o del usuario en el admin).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'wp-events-calendar', false, dirname( plugin_basename( WPEC_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Actualiza el esquema si cambió la versión de la base de datos.
		if ( get_option( 'wpec_db_version' ) !== WPEC_DB_VERSION ) {
			WPEC_DB::install();
		}

		if ( is_admin() ) {
			new WPEC_Admin();
		}
		new WPEC_Shortcode();
	}
);
