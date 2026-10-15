<?php
/**
 * Plugin Name:       Tiny Backup
 * Description:       Download your database and wp-content folder (with or without uploads) straight from the dashboard.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Murillo Torres
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tiny-backup
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'TINY_BACKUP_VERSION', '1.0.0' );
define( 'TINY_BACKUP_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-tiny-backup-output.php';
require_once __DIR__ . '/includes/class-tiny-backup-zip.php';
require_once __DIR__ . '/includes/class-tiny-backup-files.php';
require_once __DIR__ . '/includes/class-tiny-backup-database.php';
require_once __DIR__ . '/includes/class-tiny-backup-exporter.php';

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'tiny-backup', false, dirname( plugin_basename( TINY_BACKUP_FILE ) ) . '/languages' );
	}
);

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-tiny-backup-admin.php';
	Tiny_Backup_Admin::init();
}
