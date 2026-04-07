<?php
/**
 * GitHub-based plugin auto-updater.
 *
 * Checks a GitHub repository for new versions (releases first, then tags)
 * and hooks into WordPress's native update system. Also adds a
 * "Check for Updates" link on the Plugins page.
 *
 * Workflow:
 *   1. Bump the version in fishotel-shiptracker.php
 *   2. Commit, tag (e.g. v1.2.0), push with --tags
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
     * Shows next to "Version x.x.x | By FisHotel | Visit plugin site"
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
     * Clears the cache and forces WordPress to re-check.
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

        // Clear our cache.
        delete_transient( $this->cache_key );

        // Force WordPress to re-check plugin updates.
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        // Redirect back to plugins page with a notice.
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

        if ( version_compare( $remote['version'], $this->version, '>' ) ) {
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
                'changelog'   => ! empty( $remote['changelog'] )
                    ? nl2br( esc_html( $remote['changelog'] ) )
                    : '<p>See <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases">GitHub</a> for details.</p>',
            ),
        );
    }

    /**
     * After install, rename the extracted folder to match the plugin directory.
     *
     * GitHub zipballs extract to "owner-repo-commithash/" — WordPress won't
     * find the plugin there. We move it to the correct folder name and
     * re-activate.
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
            return $result;
        }

        $proper_dest = WP_PLUGIN_DIR . '/' . dirname( $this->slug );

        // Move from the extracted folder to the correct plugin directory.
        $wp_filesystem->move( $result['destination'], $proper_dest );
        $result['destination'] = $proper_dest;

        // Re-activate the plugin.
        activate_plugin( $this->slug );

        // Clear both our cache and the WordPress update transient so the
        // update button doesn't persist after a successful install.
        delete_transient( $this->cache_key );
        delete_site_transient( 'update_plugins' );

        return $result;
    }

    /**
     * Get remote version info from GitHub (cached).
     *
     * Strategy: check releases first (gives us changelog + optional asset zip),
     * then fall back to tags.
     *
     * @return array|false { 'version' => '1.2.0', 'package' => 'https://...', 'changelog' => '...' }
     */
    private function get_remote_info() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            return ! empty( $cached ) ? $cached : false;
        }

        // Try releases first.
        $info = $this->check_releases();

        // Fall back to tags.
        if ( ! $info ) {
            $info = $this->check_tags();
        }

        if ( ! $info ) {
            // Cache the failure for 30 min.
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        set_transient( $this->cache_key, $info, $this->cache_ttl );
        return $info;
    }

    /**
     * Check GitHub releases for the latest version.
     *
     * @return array|false
     */
    private function check_releases() {
        $url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'FisHotel-ShipTracker/' . $this->version,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['tag_name'] ) ) {
            return false;
        }

        $version = ltrim( $body['tag_name'], 'vV' );

        // Prefer an uploaded .zip asset (correct folder name inside).
        $package = isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';

        if ( ! empty( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( '.zip' === substr( $asset['name'], -4 ) ) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }

        return array(
            'version'   => $version,
            'package'   => $package,
            'changelog' => isset( $body['body'] ) ? $body['body'] : '',
        );
    }

    /**
     * Check GitHub tags for the latest version (fallback).
     *
     * @return array|false
     */
    private function check_tags() {
        $url = sprintf( 'https://api.github.com/repos/%s/tags?per_page=10', $this->repo );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'FisHotel-ShipTracker/' . $this->version,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $tags = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $tags ) || ! is_array( $tags ) ) {
            return false;
        }

        // Find the highest semver tag.
        $best_version = '0.0.0';
        $best_url     = '';

        foreach ( $tags as $tag ) {
            if ( empty( $tag['name'] ) ) {
                continue;
            }

            $ver = ltrim( $tag['name'], 'vV' );

            if ( ! preg_match( '/^\d+\.\d+/', $ver ) ) {
                continue;
            }

            if ( version_compare( $ver, $best_version, '>' ) ) {
                $best_version = $ver;
                $best_url     = $tag['zipball_url'];
            }
        }

        if ( '0.0.0' === $best_version ) {
            return false;
        }

        return array(
            'version'   => $best_version,
            'package'   => $best_url,
            'changelog' => '',
        );
    }

    /**
     * Clear the cached data.
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
}
