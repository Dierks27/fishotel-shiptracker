<?php
/**
 * GitHub-based plugin auto-updater.
 *
 * Checks a public GitHub repository's releases for new versions and hooks
 * into the WordPress plugin update system so that updates appear on the
 * Plugins page and can be applied with one click.
 *
 * Usage:
 *   new FST_Updater( array(
 *       'slug'       => 'fishotel-shiptracker/fishotel-shiptracker.php',
 *       'repo'       => 'YourGitHubUser/fishotel-shiptracker',
 *       'version'    => FST_VERSION,
 *       'plugin_file'=> FST_PLUGIN_FILE,
 *   ) );
 *
 * Release workflow:
 *   1. Bump the version in fishotel-shiptracker.php
 *   2. Commit, tag (e.g. v1.1.0), push tag
 *   3. On GitHub, create a Release from that tag and upload the plugin zip
 *      as a release asset (name it fishotel-shiptracker.zip)
 *   4. WordPress will detect the new version and offer the update
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
     *     @type string $repo        GitHub owner/repo (e.g. 'fishotel/fishotel-shiptracker').
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
     * Check GitHub for a newer release and inject it into the update transient.
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release['tag_name'], 'vV' );

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $transient->response[ $this->slug ] = (object) array(
                'slug'        => dirname( $this->slug ),
                'plugin'      => $this->slug,
                'new_version' => $remote_version,
                'url'         => 'https://github.com/' . $this->repo,
                'package'     => $release['zipball_url'],
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires'    => '5.8',
                'requires_php'=> '7.4',
            );

            // Prefer the uploaded zip asset over GitHub's zipball if available.
            if ( ! empty( $release['asset_url'] ) ) {
                $transient->response[ $this->slug ]->package = $release['asset_url'];
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup in Plugins > Updates.
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

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $remote_version = ltrim( $release['tag_name'], 'vV' );

        $plugin_data = get_plugin_data( $this->plugin_file );

        $info = (object) array(
            'name'            => $plugin_data['Name'],
            'slug'            => dirname( $this->slug ),
            'version'         => $remote_version,
            'author'          => $plugin_data['Author'],
            'homepage'        => $plugin_data['PluginURI'],
            'requires'        => '5.8',
            'requires_php'    => '7.4',
            'tested'          => '',
            'download_link'   => ! empty( $release['asset_url'] ) ? $release['asset_url'] : $release['zipball_url'],
            'sections'        => array(
                'description'  => $plugin_data['Description'],
                'changelog'    => nl2br( esc_html( $release['body'] ) ),
            ),
            'last_updated'    => $release['published_at'],
        );

        return $info;
    }

    /**
     * After install, rename the extracted folder to match the expected plugin directory name.
     *
     * GitHub zipballs extract to `owner-repo-hash/` which WordPress won't recognize.
     * We rename it to the correct plugin folder name.
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
     * Fetch the latest release from GitHub (cached).
     *
     * @return array|false {
     *     @type string $tag_name      e.g. 'v1.1.0'
     *     @type string $body          Release description / changelog.
     *     @type string $zipball_url   GitHub-generated source zip.
     *     @type string $asset_url     Uploaded zip asset URL (preferred).
     *     @type string $published_at  ISO 8601 date.
     * }
     */
    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );

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

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['tag_name'] ) ) {
            set_transient( $this->cache_key, array(), 1800 );
            return false;
        }

        // Look for an uploaded .zip asset (preferred over zipball).
        $asset_url = '';
        if ( ! empty( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( '.zip' === substr( $asset['name'], -4 ) ) {
                    $asset_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = array(
            'tag_name'     => $body['tag_name'],
            'body'         => isset( $body['body'] ) ? $body['body'] : '',
            'zipball_url'  => $body['zipball_url'],
            'asset_url'    => $asset_url,
            'published_at' => isset( $body['published_at'] ) ? $body['published_at'] : '',
        );

        set_transient( $this->cache_key, $release, $this->cache_ttl );

        return $release;
    }

    /**
     * Clear the cached release data.
     */
    public function clear_cache() {
        delete_transient( $this->cache_key );
    }
}
