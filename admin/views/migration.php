<?php
/**
 * Migration page — import tracking data from AST / TrackShip.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

$last_migration = get_option( 'fst_last_migration', array() );
$ast_count      = FST_Migrator::get_ast_count();
?>

<div class="wrap fst-migration-wrap">
    <h1><?php esc_html_e( 'TrackShip / AST Data Migration', 'fishotel-shiptracker' ); ?></h1>

    <div class="fst-migration-intro" style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #007cba; padding: 16px 20px; margin: 20px 0; max-width: 800px;">
        <p style="font-size: 14px; margin: 0 0 8px;">
            <?php esc_html_e( 'This tool imports shipment tracking data from the Advanced Shipment Tracking (AST) plugin and TrackShip status data into ShipTracker.', 'fishotel-shiptracker' ); ?>
        </p>
        <p style="color: #666; margin: 0;">
            <?php printf(
                esc_html__( 'Found %d orders with AST tracking data in your database.', 'fishotel-shiptracker' ),
                $ast_count
            ); ?>
        </p>
    </div>

    <?php if ( ! empty( $last_migration ) ) : ?>
        <div class="notice notice-info" style="max-width: 780px;">
            <p>
                <?php printf(
                    esc_html__( 'Last migration: %s — Imported: %d, Skipped: %d, Errors: %d', 'fishotel-shiptracker' ),
                    esc_html( $last_migration['timestamp'] ),
                    (int) $last_migration['imported'],
                    (int) $last_migration['skipped'],
                    (int) $last_migration['errors']
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Step 1: Scan -->
    <div class="fst-migration-step" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; max-width: 800px;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Step 1: Scan & Preview', 'fishotel-shiptracker' ); ?></h2>
        <p><?php esc_html_e( 'Scan your database to see what tracking data is available for import. This does not modify anything.', 'fishotel-shiptracker' ); ?></p>
        <button type="button" class="button button-primary" id="fst-scan-btn">
            <?php esc_html_e( 'Scan for Tracking Data', 'fishotel-shiptracker' ); ?>
        </button>
        <span id="fst-scan-spinner" class="spinner" style="float: none; margin-left: 8px;"></span>

        <div id="fst-scan-results" style="display: none; margin-top: 20px;">
            <!-- Populated by JS -->
        </div>
    </div>

    <!-- Step 2: Import -->
    <div class="fst-migration-step" id="fst-import-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; max-width: 800px; display: none;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Step 2: Import', 'fishotel-shiptracker' ); ?></h2>
        <p><?php esc_html_e( 'Import the tracking data into ShipTracker. Existing tracking numbers will be skipped automatically.', 'fishotel-shiptracker' ); ?></p>
        <p style="color: #d63638; font-weight: 600;">
            <?php esc_html_e( 'This action creates new shipment records. It is safe to run multiple times — duplicates are skipped.', 'fishotel-shiptracker' ); ?>
        </p>
        <button type="button" class="button button-primary" id="fst-import-btn">
            <?php esc_html_e( 'Start Import', 'fishotel-shiptracker' ); ?>
        </button>
        <span id="fst-import-spinner" class="spinner" style="float: none; margin-left: 8px;"></span>

        <div id="fst-import-results" style="display: none; margin-top: 20px;">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var restNonce = '<?php echo esc_js( wp_create_nonce( 'fst_nonce' ) ); ?>';
    var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

    // Scan button.
    $('#fst-scan-btn').on('click', function() {
        var $btn     = $(this);
        var $spinner = $('#fst-scan-spinner');
        var $results = $('#fst-scan-results');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.hide();

        $.post(ajaxUrl, {
            action: 'fst_migration_scan',
            nonce: restNonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if ( ! response.success ) {
                $results.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Scan failed.') + '</p></div>').show();
                return;
            }

            var d = response.data;
            var html = '';

            // Summary cards.
            html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">';
            html += scanCard('Orders with Tracking', d.total_orders, '#007cba');
            html += scanCard('Total Shipments', d.total_shipments, '#007cba');
            html += scanCard('Already Imported', d.already_imported, '#00a32a');
            html += scanCard('Ready to Import', d.to_import, d.to_import > 0 ? '#d63638' : '#999');
            html += '</div>';

            // Date range.
            if ( d.date_range && d.date_range.earliest ) {
                html += '<p style="color: #666;"><strong>Date range:</strong> ' + escHtml(d.date_range.earliest) + ' to ' + escHtml(d.date_range.latest) + '</p>';
            }

            // Carrier breakdown.
            if ( d.carrier_counts && Object.keys(d.carrier_counts).length > 0 ) {
                html += '<h3>By Carrier</h3><table class="widefat striped" style="max-width: 400px;"><thead><tr><th>Carrier</th><th>Count</th></tr></thead><tbody>';
                for ( var c in d.carrier_counts ) {
                    html += '<tr><td>' + escHtml(c.toUpperCase()) + '</td><td>' + d.carrier_counts[c] + '</td></tr>';
                }
                html += '</tbody></table>';
            }

            // Status breakdown.
            if ( d.status_counts && Object.keys(d.status_counts).length > 0 ) {
                html += '<h3>By Status</h3><table class="widefat striped" style="max-width: 400px;"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
                for ( var s in d.status_counts ) {
                    html += '<tr><td>' + escHtml(s.replace(/_/g, ' ')) + '</td><td>' + d.status_counts[s] + '</td></tr>';
                }
                html += '</tbody></table>';
            }

            // Sample preview.
            if ( d.sample && d.sample.length > 0 ) {
                html += '<h3>Sample Preview (first ' + d.sample.length + ')</h3>';
                html += '<table class="widefat striped"><thead><tr><th>Order</th><th>Tracking #</th><th>Carrier</th><th>Status</th><th>Ship Date</th></tr></thead><tbody>';
                for ( var i = 0; i < d.sample.length; i++ ) {
                    var row = d.sample[i];
                    html += '<tr>';
                    html += '<td>#' + row.order_id + '</td>';
                    html += '<td><code>' + escHtml(row.tracking_number) + '</code></td>';
                    html += '<td>' + escHtml(row.carrier.toUpperCase()) + '</td>';
                    html += '<td>' + escHtml(row.status.replace(/_/g, ' ')) + '</td>';
                    html += '<td>' + escHtml(row.date_shipped || '—') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
            }

            $results.html(html).show();

            // Show import section if there's data to import.
            if ( d.to_import > 0 ) {
                $('#fst-import-section').slideDown();
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $results.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>').show();
        });
    });

    // Import button.
    $('#fst-import-btn').on('click', function() {
        if ( ! confirm('Are you sure you want to import the tracking data? This will create new shipment records in ShipTracker.') ) {
            return;
        }

        var $btn     = $(this);
        var $spinner = $('#fst-import-spinner');
        var $results = $('#fst-import-results');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.hide();

        $.post(ajaxUrl, {
            action: 'fst_migration_import',
            nonce: restNonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if ( ! response.success ) {
                $results.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Import failed.') + '</p></div>').show();
                return;
            }

            var d = response.data;
            var html = '';

            // Result summary.
            var noticeClass = d.errors > 0 ? 'notice-warning' : 'notice-success';
            html += '<div class="notice ' + noticeClass + '" style="margin: 0;"><p>';
            html += '<strong>Import Complete!</strong><br>';
            html += 'Imported: <strong>' + d.imported + '</strong> shipments<br>';
            html += 'Skipped (already exist): <strong>' + d.skipped + '</strong><br>';
            if ( d.errors > 0 ) {
                html += 'Errors: <strong>' + d.errors + '</strong>';
            }
            html += '</p></div>';

            // Error details.
            if ( d.error_details && d.error_details.length > 0 ) {
                html += '<div style="margin-top: 12px;"><strong>Error Details:</strong><ul style="margin-left: 20px;">';
                for ( var i = 0; i < d.error_details.length; i++ ) {
                    html += '<li>' + escHtml(d.error_details[i]) + '</li>';
                }
                html += '</ul></div>';
            }

            // Link to shipments.
            if ( d.imported > 0 ) {
                html += '<p style="margin-top: 12px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=fst-dashboard' ) ); ?>" class="button">View Shipments Dashboard</a></p>';
            }

            $results.html(html).show();
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $results.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>').show();
        });
    });

    // Helper: scan summary card.
    function scanCard(label, value, color) {
        return '<div style="background: #f9f9f9; border: 1px solid #ddd; border-top: 3px solid ' + color + '; padding: 12px; text-align: center;">' +
            '<div style="font-size: 28px; font-weight: 700; color: ' + color + ';">' + value + '</div>' +
            '<div style="font-size: 12px; color: #666; margin-top: 4px;">' + label + '</div>' +
            '</div>';
    }

    // Helper: escape HTML.
    function escHtml(str) {
        if ( ! str ) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
</script>
