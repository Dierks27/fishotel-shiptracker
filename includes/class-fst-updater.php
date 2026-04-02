<?php
/**
 * GitHub-based plugin auto-updater.
 *
 * Checks a public GitHub repository's tags for new versions and hooks
 * into the WordPress plugin update system so that updates appear on the
 * Plugins page and can be applied with one click.
 *
 * Workflow:
 *   1. Bump the version in fishotel-shiptracker.php
 *   2. Commit, tag (e.g. v1.1.1), push with --tags
 *   3. WordPress detects the new tag and offers the update — done.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Updater {

    /** @var string Plugin basename (e.g. fishotel-shiptracker/fishotel-shiptracker.php) */
    private $slug;

    /** @var string GitHub owner/repo */
    private $repo;

    /** @var string Current plugin version */
    private $version;

    /** @var string Absolute path to main plugin file */
    private $plugin_file;

    /** @var string Transient key for caching the GitHub response */
    private $cache_key = 'fst_github_update_check';

    /** @var int Cache lifetime in seconds (6 hours) */
    private $cache_ttl = 21600;

    /**
     * Constructor.
     *
     * @param array $args {
     *     @type string $slug        Plugin basename.
     *     @type string $repo        GitHub owner/repo.
     *     @type string $version     Current version string.
     *     @type string $plugin_file Absolute path to main plugin file.
     * }
     */
    public function __construct( $args ) {
        $this->slug        = $args['slug'];
        $this->repo        = $args['repo'];
        $this->version     = $args['version'];
        $this->plugin_file = $args['plugin_file'];

        // Hook into the WordPress update system.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // Clear cache when manually checking for updates.
        add_action( 'load-update-core.php', array( $this, 'clear_cache' ) );
    }

    /**
     * Check GitHub for a newer tag and inject it into the update transient.
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $latest = $this->get_latest_tag();

        if ( ! $latest ) {
            return $transient;
        }

        $remote_version = ltrim( $latest['tag'], 'vV' );

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $transient->response[ $this->slug ] = (object) array(
                'slug'         => dirname( $this->slug ),
                'plugin'       => $this->slug,
                'new_version'  => $remote_version,
                'url'          => 'https://github.com/' . $this->repo,
                'package'      => $latest['zipball_url'],
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
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || dirname( $this->slug ) !== $args->slug ) {
            return $result;
        }

        $latest = $this->get_latest_tag();

        if ( ! $latest ) {
            return $result;
        }

        $remote_version = ltrim( $latest['tag'], 'vV' );
        $plugin_data    = get_plugin_data( $this->plugin_file );

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname( $this->slug ),
            'version'       => $remote_version,
            'author'        => $plugin_data['Author'],
            'homepage'      => $plugin_data['PluginURI'],
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '',
            'download_link' => $latest['zipball_url'],
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => '<p>See <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases">GitHub releases</a> for details.</p>',
            ),
        );
    }

    /**
     * After install, rename the extracted folder to match the expected plugin directory name.
     *
     * GitHub zipballs extract to `owner-repo-hash/` which WordPress won't recognize.
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
            return $result;
        }

        $proper_dest = WP_PLUGIN_DIR . '/' . dirname( $this->slug );
        $wp_filesystem->move( $result['destination'], $proper_dest );
        $result['destination'] = $proper_dest;

        // Re-activate the plugin.
        activate_plugin( $this->slug );

        return $result;
    }

    /**
     * Fetch the latest version tag from GitHub (cached).
     *
     * Uses the /repos/{owner}/{repo}/tags endpoint which returns tags
     * sorted by most recent first. Finds the highest semver tag.
     *
     * @return array|false { 'tag' => 'v1.1.0', 'zipball_url' => '...' }
     */
    private function get_latest_tag() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            // Empty array = cached failure.
            return ! empty( $cached ) ? $cached : false;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/tags?per_page=10', $this->repo );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'FisHotel-ShipTracker/' . $this->version,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure briefly (30 min) to avoid hammering the API.
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        $tags = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $tags ) || ! is_array( $tags ) ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        // Find the highest semver tag.
        $best         = null;
        $best_version = '0.0.0';

        foreach ( $tags as $tag ) {
            if ( empty( $tag['name'] ) ) {
                continue;
            }

            $ver = ltrim( $tag['name'], 'vV' );

            // Only consider tags that look like version numbers.
            if ( ! preg_match( '/^\d+\.\d+/', $ver ) ) {
                continue;
            }

            if ( version_compare( $ver, $best_version, '>' ) ) {
                $best_version = $ver;
                $best = array(
                    'tag'         => $tag['name'],
                    'zipball_url' => $tag['zipball_url'],
                );
            }
        }

        if ( ! $best ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        set_transient( $this->cache_key, $best, $this->cache_ttl );

        return $best;
    }

    /**
     * Clear the cached tag data.
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
}
