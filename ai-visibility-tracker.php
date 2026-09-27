<?php
/**
 * Plugin Name:       AI Visibility Tracker
 * Description:       See if ChatGPT, Claude, Gemini, Perplexity and Grok mention your brand. Generate prompts from your site, see the sources AI cites, and discover competitors. Bring your own API keys.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Israr Minhas
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-visibility-tracker
 *
 * @package AIVT
 */

defined( 'ABSPATH' ) || exit;

define( 'AIVT_VERSION', '0.3.0' );
define( 'AIVT_DB_VERSION', '3' );
define( 'AIVT_FILE', __FILE__ );
define( 'AIVT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIVT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader: AIVT\Providers\OpenAI_Provider => includes/providers/class-openai-provider.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'AIVT\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, 5 ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = AIVT_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'AIVT\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIVT\\Installer', 'deactivate' ) );

// Boot on init: translations may only load from init on (WordPress 6.7+), and every hook the plugin uses fires later.
add_action( 'init', array( 'AIVT\\Plugin', 'instance' ), 0 );
