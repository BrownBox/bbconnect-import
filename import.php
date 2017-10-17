<?php
/**
 * Plugin Name: Connexions Import
 * Plugin URI: http://connexionscrm.com/
 * Description: Import your existing contacts to Connexions
 * Version: 0.2.2
 * Author: Brown Box
 * Author URI: http://brownbox.net.au
 * License: Proprietary Brown Box
 */
define('BBCONNECT_IMPORT_DIR', plugin_dir_path(__FILE__));
define('BBCONNECT_IMPORT_URL', plugin_dir_url(__FILE__));

require_once(BBCONNECT_IMPORT_DIR.'classes/import.class.php');

function bbconnect_import_init() {
    if (!defined('BBCONNECT_VER')) {
        add_action('admin_init', 'bbconnect_import_deactivate');
        add_action('admin_notices', 'bbconnect_import_deactivate_notice');
        return;
    }
    if (is_admin()) {
        new BbConnectUpdates(__FILE__, 'BrownBox', 'bbconnect-import');
    }
}
add_action('plugins_loaded', 'bbconnect_import_init');

function bbconnect_import_deactivate() {
    deactivate_plugins(plugin_basename(__FILE__));
}

function bbconnect_import_deactivate_notice() {
    echo '<div class="updated"><p><strong>Connexions Import</strong> has been <strong>deactivated</strong> as it requires Connexions.</p></div>';
    if (isset($_GET['activate'])) {
        unset($_GET['activate']);
    }
}
