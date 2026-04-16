<?php
/**
 * GitHub-based plugin auto-updater (raw-branch pattern).
 *
 * Reads the plugin header version directly from the main branch on GitHub.
 * No releases or tags required — just bump the version and push to main.
 *
 * Workflow:
 *   1. Bump the version in fishotel-shiptracker.php (header + FST_VERSION)
 *   2. Commit and push to main
 *   3. WordPress detects the new version and shows "Update Available"
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Updater {

    /** @var string Plugin basename */
    private $slug;

    /** @var string GitHub owner/repo */
    private $repo;

    /** @var string Current plugin version */
    private $version;

    /** @var string Absolute path to main plugin file */
    private $plugin_file;

    /** @var string Branch to track */
    private $branch = 'main';

    /** @var string Transient key for caching */
    private $cache_key = 'fst_github_update';

    /** @var int Cache lifetime in seconds (6 hours) */
    private $cache_ttl = 21600;

    /**
     * Constructor.
     */
    public function __construct( $args ) {
        $this->slug        = $args['slug'];
        $this->repo        = $args['repo'];
        $this->version     = $args['version'];
        $this->plugin_file = $args['plugin_file'];

        // Core update hooks.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // "Check for Updates" link on the Plugins page.
        add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

        // Handle the manual check request.
        add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
    }

    /**
     * Add "Check for Updates" link to the plugin row meta on the Plugins page.
     */
    public function plugin_row_meta( $links, $file ) {
        if ( $file !== $this->slug ) {
            return $links;
        }

        $check_url = wp_nonce_url(
            admin_url( 'plugins.php?fst_check_update=1' ),
            'fst_check_update'
        );

        $links[] = '<a href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check for Updates', 'fishotel-shiptracker' ) . '</a>';

        return $links;
    }

    /**
     * Handle the manual "Check for Updates" click.
     */
    public function handle_manual_check() {
        if ( ! isset( $_GET['fst_check_update'] ) ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'fst_check_update' ) ) {
            return;
        }

        delete_transient( $this->cache_key );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        wp_safe_redirect( admin_url( 'plugins.php?fst_checked=1' ) );
        exit;
    }

    /**
     * Check GitHub for a newer version and inject it into the update transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_info();

        if ( ! $remote ) {
            return $transient;
        }

        // Read the on-disk version so that after an in-request update
        // we compare against the newly installed version.
        $current_version = $this->version;
        if ( function_exists( 'get_plugin_data' ) && file_exists( $this->plugin_file ) ) {
            $plugin_data = get_plugin_data( $this->plugin_file, false, false );
            if ( ! empty( $plugin_data['Version'] ) ) {
                $current_version = $plugin_data['Version'];
            }
        }

        if ( version_compare( $remote['version'], $current_version, '>' ) ) {
            $transient->response[ $this->slug ] = (object) array(
                'slug'         => dirname( $this->slug ),
                'plugin'       => $this->slug,
                'new_version'  => $remote['version'],
                'url'          => 'https://github.com/' . $this->repo,
                'package'      => $remote['package'],
                'icons'        => array(),
                'banners'      => array(),
                'tested'       => '',
                'requires'     => '5.8',
                'requires_php' => '7.4',
            );
        } else {
            unset( $transient->response[ $this->slug ] );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || dirname( $this->slug ) !== $args->slug ) {
            return $result;
        }

        $remote = $this->get_remote_info();

        if ( ! $remote ) {
            return $result;
        }

        $plugin_data = get_plugin_data( $this->plugin_file );

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname( $this->slug ),
            'version'       => $remote['version'],
            'author'        => $plugin_data['Author'],
            'homepage'      => $plugin_data['PluginURI'],
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '',
            'download_link' => $remote['package'],
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => '<p>See <a href="https://github.com/' . esc_attr( $this->repo ) . '/commits/' . esc_attr( $this->branch ) . '">GitHub commits</a> for details.</p>',
            ),
        );
    }

    /**
     * After install, rename the extracted folder to match the plugin directory.
     *
     * GitHub archive ZIPs extract to "repo-branch/" — WordPress won't
     * find the plugin there. We move it to the correct folder name and
     * re-activate.
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
            return $result;
        }

        $proper_dest = WP_PLUGIN_DIR . '/' . dirname( $this->slug );

        if ( ! $wp_filesystem->move( $result['destination'], $proper_dest ) ) {
            return new WP_Error( 'fst_move_failed', 'Could not move plugin to the correct directory.' );
        }
        $result['destination'] = $proper_dest;

        activate_plugin( $this->slug );

        delete_transient( $this->cache_key );
        delete_site_transient( 'update_plugins' );

        return $result;
    }

    /**
     * Get remote version info from GitHub (cached).
     *
     * Reads the raw plugin header from the main branch to get the version,
     * and uses the branch archive ZIP as the download package.
     *
     * @return array|false { 'version' => '1.6.5', 'package' => 'https://...' }
     */
    private function get_remote_info() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            return ! empty( $cached ) ? $cached : false;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/fishotel-shiptracker.php',
            $this->repo,
            $this->branch
        );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'User-Agent' => 'FisHotel-ShipTracker/' . $this->version,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );

        // Extract "Version: x.y.z" from the plugin header.
        if ( ! preg_match( '/^[\s*]*Version:\s*(.+)$/mi', $body, $matches ) ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        $version = trim( $matches[1] );

        if ( ! preg_match( '/^\d+\.\d+/', $version ) ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        $package = sprintf(
            'https://github.com/%s/archive/refs/heads/%s.zip',
            $this->repo,
            $this->branch
        );

        $info = array(
            'version' => $version,
            'package' => $package,
        );

        set_transient( $this->cache_key, $info, $this->cache_ttl );
        return $info;
    }

    /**
     * Clear the cached data.
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
}
