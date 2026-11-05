<?php
/**
 * Plugin Name: Digicyfr Service Quote Desk PL
 * Description: Private inquiry and quote follow-up for Polish service businesses.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Digicyfr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-service-quote-desk-pl
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-service-quote-desk-pl/
 *
 * @package Digicyfr_Service_Quote_Desk_PL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGICYFR_QUOTE_DESK_PATH', plugin_dir_path( __FILE__ ) );
require_once DIGICYFR_QUOTE_DESK_PATH . 'includes/class-digicyfr-service-quote-desk-pl.php';

register_activation_hook( __FILE__, array( 'Digicyfr_Service_Quote_Desk_PL', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Digicyfr_Service_Quote_Desk_PL', 'deactivate' ) );
Digicyfr_Service_Quote_Desk_PL::boot();
