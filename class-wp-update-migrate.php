<?php
/**
 * WP Update Migrate
 * WordPress plugins and themes update path library.
 *
 * @author Alexandre Froger
 * @version 1.0.0
 * @see https://github.com/froger-me/wp-update-migrate
 * @copyright Alexandre Froger - https://www.froger.me
 */

/*================================================================================================ */
/*                                        WP Update Migrate                                        */
/*================================================================================================ */

/**
 * Copy/paste this section to your main plugin file and uncomment the code below to enable migrations
 *
 * WARNING - READ FIRST:
 * Before enabling migrations, setup your plugin properly by making sure:
 * - the wp-update-migrate folder library is present in a lib directory at the root of the plugin
 * - to change $example_update_migrate variable name
 * - to change 'example_prefix' with a unique prefix identifier for your plugin, snake_case format
 * - to change 'example_function' with the name of the function to remove from or add to the action queue
 * - to adjust the action priority in the remove_action call
 * - to have an 'updates' directory at the root of the plugin
 * - to name each file in the 'updates' folder with a version number as file name (example: 1.5.3.php)
 * - each update file in the 'updates' directory have a single update function, and do not include any logic outside of that function
 * - the update function name in each update file follows the pattern: [example_prefix]_update_to_[version]
 *     - example: in 1.5.3.php, the function is my_plugin_update_to_1_5_3 with [example_prefix] = my_plugin
 * - each update function returns (bool) true in case of success, a WP_Error object otherwise
 **/

// 	require_once plugin_dir_path( __FILE__ ) . 'lib/wp-update-migrate/class-wp-update-migrate.php';

// $example_update_migrate = WP_Update_Migrate::get_instance( __FILE__, 'example_prefix' );

// if ( false === $example_update_migrate->get_result() ) {
	///**
	// * @todo
	// * Execute your own logic here in case the update failed.
	// *
	// * if ( false !== has_action( 'example_action', 'example_function' ) ) {
	// *     remove_action( 'example_action', 'example_function', 10 );
	// * }
	// **/
// }

// if ( true === $example_update_migrate->get_result() ) {
	///**
	// * @todo
	// * Execute your own logic here in case an update was applied succeessfully.
	// *
	// * if ( false === has_action( 'example_action', 'example_function' ) ) {
	// *     add_action( 'example_action', 'example_function', 10 );
	// * }
	// **/
// }

/*================================================================================================ */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WP_Update_Migrate' ) ) {

	class WP_Update_Migrate {

		const VERSION = '1.0.0';

		protected $failed_update_info;
		protected $success_update_info;
		protected $plugin_name;
		protected $plugin_prefix;
		protected $plugin_dir;
		protected $to_version;
		protected $from_version;
		protected $update_result;

		private function __construct( $plugin_file, $plugin_prefix ) {

			if ( ! wp_doing_ajax() ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';

				$plugin_data              = get_plugin_data( $plugin_file );
				$current_recorded_version = get_option( $plugin_prefix . '_plugin_version' );
				$latest_version           = $plugin_data['Version'];
				$is_version_current       = version_compare( $current_recorded_version, $latest_version, '>=' );

				if ( ! $is_version_current ) {
					$this->plugin_name   = $plugin_data['Name'];
					$this->plugin_dir    = plugin_dir_path( $plugin_file );
					$this->plugin_prefix = $plugin_prefix;
					$this->from_version  = $current_recorded_version;
					$this->to_version    = $latest_version;

					add_action( 'init', array( $this, 'init' ), -10, 0 );
					add_action( 'plugins_loaded', array( $this, 'update' ), -10, 0 );
				}
			}

			wp_cache_set( $plugin_prefix, $this, 'wp-update-migrate' );
		}

		public static function get_instance( $plugin_file, $plugin_prefix ) {
			$cache = wp_cache_get( $plugin_prefix, 'wp-update-migrate' );

			if ( ! $cache ) {

				return new self( $plugin_file, $plugin_prefix );
			}

			return $cache;
		}

		public function get_result() {

			return $this->update_result;
		}

		public function update_failed_notice() {
			$class   = 'notice notice-error is-dismissible';
			$message = '<p>' . $this->failed_update_info . '</p>';

			$message .= '<p>' . __( 'The plugin will not have any effect until the issues are resolved.', 'wp-update-migrate' ) . '</p>';

			printf( '<div class="%1$s">%2$s</div>', $class, $message ); // @codingStandardsIgnoreLine
		}

		public function update_success_notice() {
			$class = 'notice notice-success is-dismissible';
			// translators: %1$s is the plugin version to update to
			$title   = $this->plugin_name . ' - ' . sprintf( __( 'Success updating to version %1$s', 'wp-update-migrate' ), $this->to_version );
			$message = '<h3>' . $title . '</h3><p>' . $this->success_update_info . '</p>';

			printf( '<div class="%1$s">%2$s</div>', $class, $message ); // @codingStandardsIgnoreLine
		}

		public function init() {
			load_plugin_textdomain(
				'wp-update-migrate',
				false,
				trailingslashit( basename( $this->plugin_dir ) ) . 'lib/wp-update-migrate/languages'
			);
			wp_cache_add_non_persistent_groups( 'wp-update-migrate' );
		}

		public function update() {
			$update_path = $this->build_update_path();

			if ( ! empty( $update_path ) ) {
				usort( $update_path, 'version_compare' );

				foreach ( $update_path as $version ) {
					$update_success = $this->do_update( $version );

					if ( true !== $update_success ) {

						return false;
					}
				}
			}

			$result = true;

			if ( ! $this->update_file_exists_for_version( $this->to_version ) ) {
				$result = $this->update_plugin_version( $this->to_version );
			}

			if ( true !== $result ) {
				$this->update_result = false;
				$result              = $this->handle_error( $result );

				add_action( 'admin_notices', array( $this, 'update_failed_notice' ), 10, 0 );
			} else {
				$this->update_result = true;

				if ( ! empty( $update_path ) ) {
					$this->handle_success( sprintf( __( '<br/>All updates have been applied successfully.', 'wp-update-migrate' ), $this->to_version ) );
				}

				add_action( 'admin_notices', array( $this, 'update_success_notice' ), 10, 0 );
			}

			return $result;
		}

		protected static function get_content_dir() {
			WP_Filesystem();

			global $wp_filesystem;

			if ( ! $wp_filesystem ) {

				wp_die( 'File system not available.', __METHOD__ );
			}

			return $wp_filesystem->wp_content_dir();
		}

		protected function build_update_path() {
			$file_list   = glob( $this->plugin_dir . 'updates' . DIRECTORY_SEPARATOR . '*.php' );
			$update_path = array();

			foreach ( $file_list as $file_path ) {
				$file_version = str_replace( '.php', '', basename( $file_path ) );

				if ( version_compare( $file_version, $this->from_version, '>' ) &&
					version_compare( $file_version, $this->to_version, '<=' ) ) {
					$update_path[] = $file_version;
				}
			}

			return $update_path;
		}

		protected function do_update( $version ) {
			$error       = false;
			$result      = false;
			$update_file = $this->get_update_file_path_for_version( $version );

			require_once $update_file;

			$function_name = $this->plugin_prefix . '_update_to_' . str_replace( '.', '_', $version );

			if ( function_exists( $function_name ) ) {
				$update_executed = call_user_func( $function_name );

				if ( true !== $update_executed ) {
					$error = $update_executed;
				}
			} else {
				$error = new WP_Error(
					__METHOD__,
					sprintf(
						// translators: %1$s is the missing function name, %2$s is the path to WP_CONTENT_DIR
						__( '<br/>The update failed: function <code>%1$s</code> not found.<br/>Please restore the previously used version of the plugin, or delete the plugin and its files in the <code>%2$s</code> directory if any and install the latest version.', 'wp-update-migrate' ),
						$function_name,
						self::get_content_dir()
					)
				);
			}

			if ( $error ) {

				return $this->handle_error( $error );
			} else {
				$result = $this->update_plugin_version( $version );

				if ( true === $result ) {
					// translators: %1$s is the version we just updated to
					$this->handle_success( sprintf( __( 'Updates for version %1$s applied.', 'wp-update-migrate' ), $version ) );

					return true;
				} else {
					$error = $result;
				}
			}

			return $this->handle_error( $error );
		}

		protected function update_file_exists_for_version( $version ) {

			return file_exists( $this->plugin_dir . 'updates' . DIRECTORY_SEPARATOR . $version . '.php' );
		}

		protected function get_update_file_path_for_version( $version ) {

			return $this->plugin_dir . 'updates' . DIRECTORY_SEPARATOR . $version . '.php';
		}

		protected function update_plugin_version( $version ) {

			if ( ! version_compare( $this->from_version, $version, '=' ) ) {
				$result = update_option( $this->plugin_prefix . '_plugin_version', $version, false );
			} else {
				$result = true;
			}

			if ( ! $result ) {
				$result = new WP_Error(
					__METHOD__,
					// translators: %1$s is the plugin prefix, %2$s is the version number we're trying to update to
					sprintf( __( 'Failed to update the <code>%1$s_plugin_version</code> to %1$s in the options table.', 'wp-update-migrate' ), $this->plugin_prefix, $version )
				);
			}

			return $result;
		}

		protected function handle_error( $error = null ) {
			// translators: %1$s is the plugin version to update to
			$error_title = $this->plugin_name . ' - ' . sprintf( __( 'Error updating to version %1$s', 'wp-update-migrate' ), $this->to_version );
			// translators: %1$s is the path to WP_CONTENT_DIR
			$error_message = sprintf( __( '<br/>An unexpected error has occured during the update.<br/>Please restore the previously used version of the plugin, or delete the plugin and its files in the <code>%1$s</code> directory if any and install the latest version.', 'wp-update-migrate' ), self::get_content_dir() );

			if ( $error instanceof WP_Error ) {
				$error_message = implode( '<br/><br/>', $error->get_error_messages() );
			}

			$this->failed_update_info = '<h3>' . $error_title . '</h3>' . $error_message;

			return false;
		}

		protected function handle_success( $message = null ) {
			$this->success_update_info .= $message . '<br/>';
		}

	}
}
