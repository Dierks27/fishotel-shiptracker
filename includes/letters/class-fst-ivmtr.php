<?php
/**
 * Informed Visibility Mail Tracking & Reporting (IV-MTR) API client.
 *
 * Source: USPS "IV-MTR API Developer Toolkit" v2.6.4 (PostalPro, May 2023):
 *   - Auth:  POST https://services.usps.com/oauth/authenticate (JSON) with the
 *            Business Customer Gateway username/password, the fixed public
 *            client_id below, scope "user.info.ereg,iv1.apis". Token lives
 *            900 s; refresh via POST /oauth/token with refresh_token.
 *   - Piece: GET https://iv.usps.com/ivws_api/informedvisapi/api/mt/get/piece/imb/{imb}
 *            → { message, data: { imb, ..., scans: [ { scan_date_time,
 *              scan_event_code, scan_facility_*, mail_phase, ... } ] } }
 *   - Limit: 500 requests/min per CRID (HTTP 429). Data kept 30 days after scan.
 *
 * These credentials are SEPARATE from the developers.usps.com OAuth2 client
 * credentials used for package tracking and address validation.
 *
 * USPS changed IV-MTR API login in Aug–Sep 2026 (release notes, no new
 * endpoints published). So automatic polling stays OFF until "Test IV-MTR
 * connection" has succeeded once from the live server (ivmtr_verified_at),
 * and the first polls log the raw response so the format can be checked.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_IVMTR {

    const AUTH_URL        = 'https://services.usps.com/oauth/authenticate';
    const REFRESH_URL     = 'https://services.usps.com/oauth/token';
    const API_BASE        = 'https://iv.usps.com/ivws_api/informedvisapi';
    const CLIENT_ID       = '687b8a36-db61-42f7-83f7-11c79bf7785e'; // Published in the toolkit; same for every IV-MTR API user.
    const SCOPE           = 'user.info.ereg,iv1.apis';
    const TOKEN_TRANSIENT = 'fst_ivmtr_token';
    const REFRESH_OPTION  = 'fst_ivmtr_refresh';

    /** How many raw responses to log in full after setup (format check). */
    const RAW_LOG_COUNT = 5;

    /* ------------------------------------------------------------------
     * Settings
     * ---------------------------------------------------------------- */

    public static function is_configured() {
        $s = FST_Letters::get_settings();
        return 'api' === ( $s['ivmtr_method'] ?? 'none' )
            && ! empty( $s['ivmtr_username'] )
            && ! empty( $s['ivmtr_password'] );
    }

    /** Configured AND a live connection test has passed. */
    public static function is_ready() {
        return self::is_configured() && ! empty( FST_Letters::setting( 'ivmtr_verified_at' ) );
    }

    private static function password() {
        return self::decrypt( (string) FST_Letters::setting( 'ivmtr_password' ) );
    }

    /**
     * Sanitize the IV-MTR part of the Letters settings form.
     *
     * @param array $in  Raw (unslashed) fst_letter[] input.
     * @param array $old Current settings.
     * @return array
     */
    public static function sanitize_settings( array $in, array $old ) {
        $method = sanitize_key( $in['ivmtr_method'] ?? 'none' );
        $user   = sanitize_text_field( $in['ivmtr_username'] ?? '' );

        $out = array(
            'ivmtr_method'   => in_array( $method, array( 'none', 'api' ), true ) ? $method : 'none',
            'ivmtr_username' => $user,
            'ivmtr_password' => $old['ivmtr_password'] ?? '',
        );

        // Blank password field = keep the stored one.
        $pw = isset( $in['ivmtr_password'] ) ? (string) $in['ivmtr_password'] : '';
        if ( '' !== $pw ) {
            $out['ivmtr_password'] = self::encrypt( $pw );
        }

        // Any credential change invalidates the token and the verified flag.
        if ( $user !== ( $old['ivmtr_username'] ?? '' ) || '' !== $pw || $out['ivmtr_method'] !== ( $old['ivmtr_method'] ?? 'none' ) ) {
            $out['ivmtr_verified_at'] = '';
            delete_transient( self::TOKEN_TRANSIENT );
            delete_option( self::REFRESH_OPTION );
        }

        return $out;
    }

    public static function render_settings_section( $s ) {
        $verified = ! empty( $s['ivmtr_verified_at'] );
        ?>
        <h3><?php esc_html_e( 'IV-MTR scan data', 'fishotel-shiptracker' ); ?></h3>
        <div class="notice notice-info inline" style="margin:8px 0;padding:10px 14px;">
            <p style="margin-top:0;"><strong><?php esc_html_e( 'How ShipTracker gets letter scans', 'fishotel-shiptracker' ); ?></strong></p>
            <p><?php esc_html_e( 'ShipTracker uses the IV-MTR REST API: the cron job looks up each open letter by its IMb. You don\'t need a server, SFTP site, or IP allow-list. USPS also offers data feeds (online download, SFTP push, HTTPS push), but each one needs a server or has an undocumented push format, so they aren\'t used.', 'fishotel-shiptracker' ); ?></p>
            <ol style="margin-bottom:0;">
                <li><?php esc_html_e( 'In the Business Customer Gateway, add the "Informed Visibility" service for your MID. You need to be the BSA or a BSA Delegate. A BSA gets access right away. It\'s free.', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'Enter that BCG username and password below. These are NOT the developers.usps.com Client ID/Secret from the Carriers tab. IV-MTR logs in with BCG credentials.', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'Save, then click "Test IV-MTR connection". Letter polling stays off until this test passes from this server. (USPS changed IV-MTR API login in Aug–Sep 2026. If the test fails, call the IV Solutions Center at 1-800-238-3150, option 2, or email InformedVisibility@usps.gov and ask for the current IV-MTR API authentication method.)', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'Without IV-MTR, letters still work. Use "Mark mailed" to send the "on its way" email. Only scan-driven statuses are skipped.', 'fishotel-shiptracker' ); ?></li>
            </ol>
        </div>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fst_ivmtr_method"><?php esc_html_e( 'Access method', 'fishotel-shiptracker' ); ?></label></th>
                <td>
                    <select id="fst_ivmtr_method" name="fst_letter[ivmtr_method]">
                        <option value="none" <?php selected( $s['ivmtr_method'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'Off (manual "Mark mailed" only)', 'fishotel-shiptracker' ); ?></option>
                        <option value="api" <?php selected( $s['ivmtr_method'] ?? 'none', 'api' ); ?>><?php esc_html_e( 'IV-MTR API (BCG login)', 'fishotel-shiptracker' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_ivmtr_username"><?php esc_html_e( 'BCG username', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="text" id="fst_ivmtr_username" name="fst_letter[ivmtr_username]" value="<?php echo esc_attr( $s['ivmtr_username'] ?? '' ); ?>" class="regular-text" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_ivmtr_password"><?php esc_html_e( 'BCG password', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="password" id="fst_ivmtr_password" name="fst_letter[ivmtr_password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $s['ivmtr_password'] ) ? esc_attr__( '(saved, leave blank to keep)', 'fishotel-shiptracker' ) : ''; ?>">
                    <p class="description"><?php esc_html_e( 'Stored encrypted with this site\'s secret keys.', 'fishotel-shiptracker' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="fst_test_ivmtr"><?php esc_html_e( 'Test IV-MTR connection', 'fishotel-shiptracker' ); ?></button>
                    <span id="fst_test_ivmtr_result" style="margin-left:8px;">
                        <?php if ( $verified ) : ?>
                            <span style="color:#1e7e34;">&#10003; <?php printf( esc_html__( 'Verified %s. Polling is on.', 'fishotel-shiptracker' ), esc_html( $s['ivmtr_verified_at'] ) ); ?></span>
                        <?php elseif ( self::is_configured() ) : ?>
                            <span style="color:#b32d2e;"><?php esc_html_e( 'Not verified yet. Polling is off.', 'fishotel-shiptracker' ); ?></span>
                        <?php endif; ?>
                    </span>
                    <script>
                    jQuery(function ($) {
                        $('#fst_test_ivmtr').on('click', function () {
                            var $r = $('#fst_test_ivmtr_result').text('Testing…').css('color', '');
                            $.post(fst_admin.ajax_url, { action: 'fst_test_ivmtr', nonce: fst_admin.nonce })
                                .done(function (res) {
                                    $r.text(res && res.data ? res.data.message : 'Failed').css('color', res && res.success ? '#1e7e34' : '#b32d2e');
                                })
                                .fail(function () { $r.text('Request failed.').css('color', '#b32d2e'); });
                        });
                    });
                    </script>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_opcode_map"><?php esc_html_e( 'Opcode overrides', 'fishotel-shiptracker' ); ?></label></th>
                <td>
                    <textarea id="fst_letter_opcode_map" name="fst_letter[opcode_map]" rows="3" class="large-text code" placeholder="896=final&#10;893=destination"><?php echo esc_textarea( $s['opcode_map'] ?? '' ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Optional. One "opcode=stage" per line (stages: origin, destination, final, ignore). Scans are mapped by their IV-MTR mail phase first, then by the built-in opcode list (IV-MTR Operation Codes List v11.2). Use this only if a real letter shows an unmapped opcode in the log.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ------------------------------------------------------------------
     * Auth
     * ---------------------------------------------------------------- */

    /**
     * @param bool $force Ignore the cached token.
     * @return string|WP_Error
     */
    public static function get_token( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TOKEN_TRANSIENT );
            if ( $cached ) {
                return $cached;
            }

            // Try the refresh token before sending the password again.
            $refresh = get_option( self::REFRESH_OPTION );
            if ( $refresh ) {
                $token = self::auth_request( self::REFRESH_URL, array(
                    'refresh_token' => $refresh,
                    'grant_type'    => 'authorization',
                    'response_type' => 'token',
                    'scope'         => self::SCOPE,
                ) );
                if ( ! is_wp_error( $token ) ) {
                    return $token;
                }
                delete_option( self::REFRESH_OPTION );
            }
        }

        $s = FST_Letters::get_settings();
        if ( empty( $s['ivmtr_username'] ) || '' === self::password() ) {
            return new WP_Error( 'fst_ivmtr_no_credentials', 'IV-MTR BCG username/password not configured.' );
        }

        return self::auth_request( self::AUTH_URL, array(
            'username'      => $s['ivmtr_username'],
            'password'      => self::password(),
            'grant_type'    => 'authorization',
            'response_type' => 'token',
            'scope'         => self::SCOPE,
            'client_id'     => self::CLIENT_ID,
        ) );
    }

    private static function auth_request( $url, array $payload ) {
        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            FST_Letters::log( 'IV-MTR auth transport error: ' . $response->get_error_message(), 'error' );
            return new WP_Error( 'fst_ivmtr_unreachable', 'Could not reach USPS IV-MTR login (' . $response->get_error_message() . ').' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( 200 !== $code || empty( $body['access_token'] ) ) {
            // Never log the request (it has the password); the response body is safe to log.
            FST_Letters::log( sprintf( 'IV-MTR auth failed at %s: HTTP %d %s', $url, $code, substr( $raw, 0, 500 ) ), 'error' );
            $hint = ( 0 === strpos( ltrim( $raw ), '<' ) )
                ? ' USPS returned an HTML page instead of JSON, so the login flow has probably changed (USPS updated IV-MTR API login in Aug–Sep 2026). Ask the IV Solutions Center for the current API authentication method.'
                : '';
            return new WP_Error( 'fst_ivmtr_auth', sprintf( 'IV-MTR login failed (HTTP %d).%s', $code, $hint ) );
        }

        $ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 840;
        set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );
        if ( ! empty( $body['refresh_token'] ) ) {
            update_option( self::REFRESH_OPTION, $body['refresh_token'], 'no' );
        }

        return $body['access_token'];
    }

    /**
     * Admin "Test IV-MTR connection": fresh login, then a lookup of a
     * nonexistent IMb under our MID to confirm the API answers JSON.
     * On success, sets ivmtr_verified_at, which turns polling on.
     *
     * @return string|WP_Error Success message.
     */
    public static function test_connection() {
        if ( ! self::is_configured() ) {
            return new WP_Error( 'fst_ivmtr_not_configured', 'Choose "IV-MTR API", enter the BCG username and password, and Save first.' );
        }

        delete_transient( self::TOKEN_TRANSIENT );
        delete_option( self::REFRESH_OPTION );

        $token = self::get_token( true );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Probe with an IMb that can't exist (serial 0) under our own MID.
        $s     = FST_Letters::get_settings();
        $len   = FST_Letters::serial_length();
        $probe = ( $s['barcode_id'] ?: '00' ) . ( $s['stid'] ?: '310' ) . ( $s['mid'] ?: '900000000' ) . str_repeat( '0', $len );
        $probe = substr( str_pad( $probe, 20, '0' ), 0, 20 );

        $result = self::get_piece( $probe, true );

        if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'fst_ivmtr_auth', 'fst_ivmtr_forbidden', 'fst_ivmtr_unreachable', 'fst_ivmtr_bad_response' ), true ) ) {
            return $result;
        }

        $settings                      = get_option( 'fst_letter_settings', array() );
        $settings['ivmtr_verified_at'] = current_time( 'mysql' );
        update_option( 'fst_letter_settings', $settings );
        update_option( 'fst_ivmtr_raw_log_remaining', self::RAW_LOG_COUNT, 'no' );

        FST_Letters::log( 'IV-MTR connection verified. Letter polling enabled.', 'notice' );

        return 'Connected: logged in and the piece API answered. Letter polling is now on.';
    }

    /* ------------------------------------------------------------------
     * Piece lookup
     * ---------------------------------------------------------------- */

    /**
     * Fetch a piece by IMb.
     *
     * @param string $imb   20–31 digit IMb.
     * @param bool   $probe True for the connection test (404/empty is fine).
     * @return array|WP_Error Normalized { found: bool, scans: [...], raw: array }
     */
    public static function get_piece( $imb, $probe = false ) {
        $imb   = preg_replace( '/\D/', '', (string) $imb );
        $token = self::get_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url      = self::API_BASE . '/api/mt/get/piece/imb/' . rawurlencode( $imb );
        $response = self::api_get( $url, $token );

        // Token expired early → one retry with a fresh login.
        if ( ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
            delete_transient( self::TOKEN_TRANSIENT );
            $token = self::get_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
            $response = self::api_get( $url, $token );
        }

        if ( is_wp_error( $response ) ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: transport error %s', $imb, $response->get_error_message() ), 'error' );
            return new WP_Error( 'fst_ivmtr_unreachable', 'Could not reach IV-MTR: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        // Log full raw responses for the first few polls after setup.
        $remaining = (int) get_option( 'fst_ivmtr_raw_log_remaining', 0 );
        if ( $remaining > 0 && ! $probe ) {
            FST_Letters::log( sprintf( 'IV-MTR raw response for %s (HTTP %d): %s', $imb, $code, substr( $raw, 0, 4000 ) ), 'debug' );
            update_option( 'fst_ivmtr_raw_log_remaining', $remaining - 1, 'no' );
        }

        if ( 429 === $code ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: rate limited (429)', $imb ), 'warning' );
            return new WP_Error( 'fst_ivmtr_rate_limited', 'IV-MTR rate limit reached. Will retry next cycle.' );
        }
        if ( 401 === $code ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: HTTP 401 %s', $imb, substr( $raw, 0, 300 ) ), 'error' );
            return new WP_Error( 'fst_ivmtr_auth', 'IV-MTR rejected the login token (HTTP 401).' );
        }
        if ( 403 === $code ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: HTTP 403 %s', $imb, substr( $raw, 0, 300 ) ), 'error' );
            return new WP_Error( 'fst_ivmtr_forbidden', 'IV-MTR says this BCG user is not authorized (HTTP 403). The user needs BSA or BSA Delegate access to the IV-MTR service for this MID.' );
        }

        // No data yet for this IMb: 404, empty data, or a "not found" message.
        $data = is_array( $body ) && isset( $body['data'] ) ? $body['data'] : null;
        if ( 404 === $code || ( 200 === $code && empty( $data ) ) ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: no piece data yet (HTTP %d)', $imb, $code ), 'debug' );
            return array( 'found' => false, 'scans' => array(), 'raw' => is_array( $body ) ? $body : array() );
        }

        if ( 200 !== $code || ! is_array( $data ) ) {
            FST_Letters::log( sprintf( 'IV-MTR poll %s: unexpected HTTP %d %s', $imb, $code, substr( $raw, 0, 500 ) ), 'error' );
            return new WP_Error( 'fst_ivmtr_bad_response', sprintf( 'Unexpected IV-MTR response (HTTP %d).', $code ) );
        }

        $scans = self::normalize_scans( $data );
        FST_Letters::log( sprintf( 'IV-MTR poll %s: %d scan(s)', $imb, count( $scans ) ) );

        return array( 'found' => true, 'scans' => $scans, 'raw' => $data );
    }

    private static function api_get( $url, $token ) {
        return wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
            'timeout' => 20,
        ) );
    }

    /**
     * Normalize the piece's scans[] to our event shape, oldest first.
     * Public for unit tests.
     *
     * @param array $data Response "data" object.
     * @return array[] { opcode, event_time (Y-m-d H:i:s), mail_phase, location, facility_zip, raw }
     */
    public static function normalize_scans( array $data ) {
        $scans = isset( $data['scans'] ) && is_array( $data['scans'] ) ? $data['scans'] : array();
        $out   = array();

        foreach ( $scans as $scan ) {
            if ( ! is_array( $scan ) || empty( $scan['scan_date_time'] ) ) {
                continue;
            }
            $ts = strtotime( str_replace( 'T', ' ', (string) $scan['scan_date_time'] ) );
            if ( ! $ts ) {
                continue;
            }

            $city  = $scan['scan_facility_city'] ?? ( $scan['scan_facility_name'] ?? '' );
            $state = $scan['scan_facility_state'] ?? '';

            $out[] = array(
                'opcode'       => str_pad( preg_replace( '/\D/', '', (string) ( $scan['scan_event_code'] ?? '' ) ), 3, '0', STR_PAD_LEFT ),
                'event_time'   => gmdate( 'Y-m-d H:i:s', $ts ),
                'timestamp'    => $ts,
                'mail_phase'   => (string) ( $scan['mail_phase'] ?? '' ),
                'location'     => trim( $city . ( $state ? ', ' . $state : '' ), ', ' ),
                'facility_zip' => (string) ( $scan['scan_facility_zip'] ?? '' ),
                'raw'          => $scan,
            );
        }

        usort( $out, static function ( $a, $b ) {
            return $a['timestamp'] <=> $b['timestamp'];
        } );

        return $out;
    }

    /* ------------------------------------------------------------------
     * Credential encryption (site-key based; protects against DB dumps)
     * ---------------------------------------------------------------- */

    private static function key() {
        $salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
        return hash( 'sha256', 'fst-ivmtr|' . $salt, true );
    }

    public static function encrypt( $plain ) {
        if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
            return $plain;
        }
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
        if ( false === $cipher ) {
            return $plain;
        }
        $mac = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
        return 'enc1:' . base64_encode( $iv . $mac . $cipher );
    }

    public static function decrypt( $stored ) {
        if ( 0 !== strpos( (string) $stored, 'enc1:' ) ) {
            return (string) $stored;
        }
        $bin = base64_decode( substr( $stored, 5 ), true );
        if ( false === $bin || strlen( $bin ) < 49 || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $iv     = substr( $bin, 0, 16 );
        $mac    = substr( $bin, 16, 32 );
        $cipher = substr( $bin, 48 );
        if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key(), true ), $mac ) ) {
            return ''; // Salts changed → re-enter the password.
        }
        $plain = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }
}
