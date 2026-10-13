<?php
/**
 * Plugin Name: ActivityWatch WordPress Tracker
 * Plugin URI:  https://github.com/activitytrack/activitywatch-wp-tracker
 * Description: Enterprise usage tracking system. Aggregates desktop activity across LAN PCs, floating users, departments, and applications.
 * Version:     1.6.1
 * Author:      Amit Pailwan
 * Text Domain: aw-wp-tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AW_TRACKER_VERSION', '1.6.1');
define('AW_TRACKER_DB_VERSION', '1.6.0');
define('AW_TRACKER_NO_DEPARTMENT', '__no_department_assigned__');
define('AW_TRACKER_TABLE_LOGS', 'aw_activity_logs');
define('AW_TRACKER_TABLE_DEVICES', 'aw_devices');
define('AW_TRACKER_TABLE_SUMMARY', 'aw_daily_summary');

register_activation_hook(__FILE__, 'aw_tracker_activate');
register_deactivation_hook(__FILE__, 'aw_tracker_deactivate');

// Overwriting the plugin file does not fire the activation hook, so schema
// upgrades run on the first request after the file is replaced.
add_action('plugins_loaded', 'aw_tracker_maybe_upgrade');
function aw_tracker_maybe_upgrade() {
    if (get_option('aw_tracker_db_version') !== AW_TRACKER_DB_VERSION) {
        aw_tracker_activate();
    }
}

function aw_tracker_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table 1: Raw Activity Logs
    $table_logs = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        event_uid varchar(64) DEFAULT NULL,
        machine_identifier varchar(100) NOT NULL,
        os_user varchar(150) DEFAULT 'Unknown',
        os_platform varchar(50) DEFAULT 'Windows',
        application_name varchar(255) NOT NULL,
        window_title text DEFAULT NULL,
        duration_seconds int(11) NOT NULL DEFAULT 0,
        is_idle tinyint(1) NOT NULL DEFAULT 0,
        rolled_seconds int(11) NOT NULL DEFAULT 0,
        logged_at datetime NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_event_uid (event_uid),
        KEY idx_logged (logged_at),
        KEY idx_machine_logged (machine_identifier, logged_at),
        KEY idx_user (os_user),
        KEY idx_app (application_name(191))
    ) $charset_collate;";

    // Table 2: Device Registry
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    $sql_devices = "CREATE TABLE $table_devices (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        machine_identifier varchar(100) NOT NULL,
        machine_uuid varchar(64) DEFAULT NULL,
        ashram varchar(100) DEFAULT 'Main Ashram',
        building varchar(100) DEFAULT 'Main Building',
        room varchar(50) DEFAULT 'General Room',
        department varchar(100) DEFAULT 'General Department',
        last_seen datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY machine_identifier (machine_identifier)
    ) $charset_collate;";

    // Table 3: Compact Daily Summary Table
    $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;
    $sql_summary = "CREATE TABLE $table_summary (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        log_date date NOT NULL,
        machine_identifier varchar(100) NOT NULL,
        os_user varchar(150) NOT NULL,
        application_name varchar(255) NOT NULL,
        active_seconds int(11) NOT NULL DEFAULT 0,
        idle_seconds int(11) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_daily_metric (log_date, machine_identifier, os_user, application_name(191)),
        KEY idx_date_machine (log_date, machine_identifier),
        KEY idx_date_user (log_date, os_user)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_logs);
    dbDelta($sql_devices);
    dbDelta($sql_summary);

    $previous_db_version = get_option('aw_tracker_db_version');
    if ($previous_db_version !== AW_TRACKER_DB_VERSION) {
        aw_tracker_migrate_rollup_state();
        update_option('aw_tracker_db_version', AW_TRACKER_DB_VERSION);
    }

    if (!get_option('aw_tracker_api_secret')) {
        update_option('aw_tracker_api_secret', wp_generate_password(32, false));
    }

    if (!get_option('aw_raw_retention_setting')) {
        update_option('aw_raw_retention_setting', '6_months');
    }

    if (!get_option('aw_summary_retention_setting')) {
        update_option('aw_summary_retention_setting', '36_months');
    }

    if (!get_option('aw_idle_threshold_seconds')) {
        update_option('aw_idle_threshold_seconds', 300);
    }

    // Register Role Capabilities (RBAC)
    aw_tracker_register_roles_and_caps();

    // Schedule 02:00 AM Cron
    if (!wp_next_scheduled('aw_tracker_daily_maintenance_event')) {
        $two_am = strtotime('tomorrow 02:00:00');
        wp_schedule_event($two_am, 'daily', 'aw_tracker_daily_maintenance_event');
    }
}

/**
 * One-time move to incremental rollups (rolled_seconds). Days still fully held
 * in raw logs are rebuilt from raw; the oldest raw day may already be partly
 * purged, so its existing summary is kept and its raw rows are marked rolled.
 */
function aw_tracker_migrate_rollup_state() {
    global $wpdb;
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;

    $oldest = $wpdb->get_var("SELECT DATE(MIN(logged_at)) FROM {$table_logs}");
    if (!$oldest) {
        return;
    }
    $next_day = wp_date('Y-m-d', strtotime($oldest . ' 12:00:00 +1 day'));
    $oldest_has_summary = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_summary} WHERE log_date = %s", $oldest));

    $wpdb->query($wpdb->prepare("DELETE FROM {$table_summary} WHERE log_date > %s", $oldest));
    if ($oldest_has_summary) {
        $wpdb->query($wpdb->prepare("UPDATE {$table_logs} SET rolled_seconds = duration_seconds WHERE logged_at < %s", $next_day . ' 00:00:00'));
        update_option('aw_raw_complete_from', $next_day);
    } elseif ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_summary} WHERE log_date < %s", $oldest))) {
        update_option('aw_raw_complete_from', $oldest);
    }
}

function aw_tracker_deactivate() {
    wp_clear_scheduled_hook('aw_tracker_daily_maintenance_event');
}

// ==================== RBAC & USER ROLES ====================
function aw_tracker_register_roles_and_caps() {
    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->add_cap('view_aw_reports');
        $admin_role->add_cap('manage_aw_tracker');
    }

    // Department Administrator Role
    if (!get_role('aw_dept_admin')) {
        add_role('aw_dept_admin', 'AW Department Admin', array(
            'read'            => true,
            'view_aw_reports' => true,
        ));
    }

    // Read-only Report Viewer Role
    if (!get_role('aw_report_viewer')) {
        add_role('aw_report_viewer', 'AW Report Viewer', array(
            'read'            => true,
            'view_aw_reports' => true,
        ));
    }
}

// Helper: Check if current user is restricted to a department
function aw_tracker_get_user_department_restriction() {
    if (current_user_can('manage_options') || current_user_can('manage_aw_tracker')) {
        return null; // Full Access
    }
    $user     = wp_get_current_user();
    $assigned = sanitize_text_field((string) get_user_meta($user->ID, 'aw_assigned_department', true));
    if ($assigned !== '') {
        return $assigned;
    }
    // A department admin without a department must never fall through to fleet-wide access.
    if (in_array('aw_dept_admin', (array) $user->roles, true)) {
        return AW_TRACKER_NO_DEPARTMENT;
    }
    return null;
}

// Add user profile department field for Dept Admins
add_action('show_user_profile', 'aw_tracker_user_department_field');
add_action('edit_user_profile', 'aw_tracker_user_department_field');
function aw_tracker_user_department_field($user) {
    if (!current_user_can('manage_options')) return;
    $dept = get_the_author_meta('aw_assigned_department', $user->ID);
    ?>
    <h3>ActivityWatch Access Control</h3>
    <table class="form-table">
        <tr>
            <th><label for="aw_assigned_department">Assigned Department</label></th>
            <td>
                <input type="text" name="aw_assigned_department" id="aw_assigned_department" value="<?php echo esc_attr($dept); ?>" class="regular-text" />
                <p class="description">If this user is an <strong>AW Department Admin</strong>, their dashboard and exports will be strictly filtered to computers in this department.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'aw_tracker_save_user_department_field');
add_action('edit_user_profile_update', 'aw_tracker_save_user_department_field');
function aw_tracker_save_user_department_field($user_id) {
    if (!current_user_can('manage_options')) return;
    update_user_meta($user_id, 'aw_assigned_department', sanitize_text_field($_POST['aw_assigned_department'] ?? ''));
}

// ==================== REST API ENDPOINT ====================
add_action('rest_api_init', 'aw_tracker_register_rest_routes');

function aw_tracker_register_rest_routes() {
    register_rest_route('activitywatch/v1', '/sync', array(
        'methods'             => 'POST',
        'callback'            => 'aw_tracker_handle_sync_payload',
        'permission_callback' => 'aw_tracker_verify_api_auth',
    ));

    // Config endpoint — agents poll this to get server-side settings (idle threshold, sync interval, etc.)
    register_rest_route('activitywatch/v1', '/config', array(
        'methods'             => 'GET',
        'callback'            => 'aw_tracker_handle_config_request',
        'permission_callback' => 'aw_tracker_verify_api_auth',
    ));
}

function aw_tracker_handle_config_request($request) {
    return new WP_REST_Response(array(
        'idle_threshold_seconds' => (int) get_option('aw_idle_threshold_seconds', 300),
        'sync_interval_seconds'  => (int) apply_filters('aw_tracker_sync_interval_seconds', 300),
        'server_version'         => AW_TRACKER_VERSION,
    ), 200);
}

function aw_tracker_verify_api_auth($request) {
    $auth_header = $request->get_header('authorization');
    $secret_token = get_option('aw_tracker_api_secret');

    if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
        return new WP_Error('unauthorized', 'Missing or invalid Authorization header.', array('status' => 401));
    }

    $provided_token = trim(substr($auth_header, 7));
    if (!hash_equals($secret_token, $provided_token)) {
        return new WP_Error('forbidden', 'Invalid API secret token.', array('status' => 403));
    }

    return true;
}

/**
 * Use the registered device row unless its hardware id differs: two machines
 * with the same hostname then get distinct identifiers instead of merging.
 */
function aw_tracker_resolve_machine_identifier($hostname, $machine_uuid) {
    global $wpdb;
    if ($machine_uuid === '') {
        return $hostname;
    }
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    $known = $wpdb->get_var($wpdb->prepare(
        "SELECT machine_identifier FROM {$table_devices} WHERE machine_uuid = %s AND machine_identifier LIKE %s LIMIT 1",
        $machine_uuid, $wpdb->esc_like($hostname) . '%'
    ));
    if ($known) {
        return $known;
    }
    $existing_uuid = $wpdb->get_var($wpdb->prepare("SELECT machine_uuid FROM {$table_devices} WHERE machine_identifier = %s", $hostname));
    if ($existing_uuid === null || $existing_uuid === '' || $existing_uuid === $machine_uuid) {
        return $hostname;
    }
    return substr($hostname, 0, 92) . '-' . substr(preg_replace('/[^a-z0-9]/', '', $machine_uuid), 0, 6);
}

/** Split a wall-clock span (seconds, local time read as UTC) at local midnights. */
function aw_tracker_split_at_midnight($start, $duration) {
    $end    = $start + $duration;
    $cursor = $start;
    $pieces = array();
    do {
        $next_midnight = (intdiv($cursor, 86400) + 1) * 86400;
        $piece_end = min($end, $next_midnight);
        $pieces[]  = array($cursor, $piece_end - $cursor);
        $cursor    = $piece_end;
    } while ($cursor < $end && count($pieces) < 62);
    return $pieces;
}

function aw_tracker_handle_sync_payload($request) {
    global $wpdb;
    $params = $request->get_json_params();

    if (empty($params) || !isset($params['machine_identifier']) || !isset($params['events']) || !is_array($params['events'])) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid payload structure.'), 400);
    }

    $machine_uuid = isset($params['machine_uuid']) ? strtolower(sanitize_text_field($params['machine_uuid'])) : '';
    $machine      = aw_tracker_resolve_machine_identifier(sanitize_text_field($params['machine_identifier']), $machine_uuid);
    $os_platform  = isset($params['os_platform']) ? sanitize_text_field($params['os_platform']) : 'Windows';
    $events       = $params['events'];
    $current_local_time = current_time('mysql');
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    $tz            = wp_timezone();

    $accepted = 0;
    $skipped  = 0;
    $failed   = false;

    // All rows of a batch commit together, so a success response always means
    // every record is stored and a failure means none is.
    $wpdb->query('START TRANSACTION');

    foreach ($events as $event) {
        if (!is_array($event)) {
            $accepted++;
            $skipped++;
            continue;
        }
        $os_user    = isset($event['user']) ? sanitize_text_field($event['user']) : 'Unknown';
        $app_name   = isset($event['app']) ? sanitize_text_field($event['app']) : 'Unknown';
        $title      = isset($event['title']) ? sanitize_text_field($event['title']) : '';
        $duration   = isset($event['duration']) ? intval($event['duration']) : 0;
        $is_idle    = !empty($event['is_idle']) ? 1 : 0;
        $is_session = ($app_name === 'System Session');
        $uid        = isset($event['uid']) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $event['uid']), 0, 56) : '';

        if ($duration < 0 || ($duration === 0 && !$is_session)) {
            $accepted++;
            $skipped++;
            continue;
        }

        // Store the PC's own clock time (as agents up to v2 did), so new rows line
        // up with older rows and with the time users see on screen. Without a
        // local time, fall back to the epoch in the WordPress timezone.
        $wall = false;
        if (isset($event['logged_at'])) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $event['logged_at'], new DateTimeZone('UTC'));
            if ($parsed) {
                $wall = $parsed->getTimestamp();
            }
        }
        if ($wall === false && isset($event['ts']) && is_numeric($event['ts']) && $event['ts'] > 946684800) {
            $epoch = (int) $event['ts'];
            $wall  = $epoch + $tz->getOffset(new DateTimeImmutable('@' . $epoch));
        }
        if ($wall === false) {
            $accepted++;
            $skipped++;
            continue;
        }

        foreach (aw_tracker_split_at_midnight($wall, $duration) as $index => $piece) {
            $logged_at = gmdate('Y-m-d H:i:s', $piece[0]);
            $seconds   = (int) $piece[1];

            if ($uid !== '') {
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table_logs}
                        (event_uid, machine_identifier, os_user, os_platform, application_name, window_title, duration_seconds, is_idle, logged_at)
                     VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %s)
                     ON DUPLICATE KEY UPDATE
                        duration_seconds = VALUES(duration_seconds),
                        window_title     = VALUES(window_title)",
                    $index === 0 ? $uid : $uid . '#' . $index,
                    $machine, $os_user, $os_platform, $app_name, $title, $seconds, $is_idle, $logged_at
                ));
            } else {
                // Agents older than v3 send no uid: keep the previous match-and-update behaviour.
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_logs}
                     WHERE machine_identifier = %s AND os_user = %s AND application_name = %s AND logged_at = %s",
                    $machine, $os_user, $app_name, $logged_at
                ));
                if ($existing_id) {
                    $result = $wpdb->update(
                        $table_logs,
                        array('duration_seconds' => $seconds, 'window_title' => $title, 'is_idle' => $is_idle),
                        array('id' => $existing_id),
                        array('%d', '%s', '%d'),
                        array('%d')
                    );
                } else {
                    $result = $wpdb->insert(
                        $table_logs,
                        array(
                            'machine_identifier' => $machine,
                            'os_user'            => $os_user,
                            'os_platform'        => $os_platform,
                            'application_name'   => $app_name,
                            'window_title'       => $title,
                            'duration_seconds'   => $seconds,
                            'is_idle'            => $is_idle,
                            'logged_at'          => $logged_at,
                        ),
                        array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
                    );
                }
            }
            if ($result === false) {
                $failed = true;
                break 2;
            }
        }
        $accepted++;
    }

    if (!$failed) {
        $touched = $wpdb->query($wpdb->prepare("
            INSERT INTO {$table_devices} (machine_identifier, machine_uuid, last_seen)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen),
                                    machine_uuid = COALESCE(NULLIF(machine_uuid, ''), VALUES(machine_uuid))
        ", $machine, $machine_uuid !== '' ? $machine_uuid : null, $current_local_time));
        $failed = ($touched === false);
    }

    if ($failed || $wpdb->query('COMMIT') === false) {
        $error = $wpdb->last_error;
        $wpdb->query('ROLLBACK');
        error_log('[ActivityWatch Tracker] Sync batch from ' . $machine . ' rolled back: ' . $error);
        return new WP_REST_Response(array(
            'success'  => false,
            'accepted' => 0,
            'message'  => 'Database write failed; batch rolled back. Retry later.',
        ), 500);
    }

    return new WP_REST_Response(array(
        'success'        => true,
        'accepted'       => $accepted,
        'skipped'        => $skipped,
        'processed_rows' => $accepted - $skipped,
        'machine'        => $machine,
        'last_seen'      => $current_local_time
    ), 200);
}

// ==================== DAILY ROLLUP & PRUNING LOGIC ====================
add_action('aw_tracker_daily_maintenance_event', 'aw_tracker_run_daily_maintenance');

/**
 * Add every not-yet-summarised second (duration_seconds - rolled_seconds) to
 * the daily summary. Incremental and idempotent: late/offline records and
 * extended durations are picked up on any later run, and re-running adds 0.
 * Returns the number of raw rows rolled up, or false on failure.
 */
function aw_tracker_rollup_pending() {
    global $wpdb;
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;

    $max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table_logs}");
    if (!$max_id) {
        return 0;
    }

    $wpdb->query('START TRANSACTION');
    $locked = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_logs} WHERE id <= %d AND duration_seconds <> rolled_seconds FOR UPDATE", $max_id
    ));
    $inserted = $wpdb->query($wpdb->prepare("
        INSERT INTO {$table_summary} (log_date, machine_identifier, os_user, application_name, active_seconds, idle_seconds)
        SELECT DATE(logged_at), machine_identifier, os_user, application_name,
               SUM(CASE WHEN is_idle = 0 THEN duration_seconds - rolled_seconds ELSE 0 END),
               SUM(CASE WHEN is_idle = 1 THEN duration_seconds - rolled_seconds ELSE 0 END)
        FROM {$table_logs}
        WHERE id <= %d AND duration_seconds <> rolled_seconds AND application_name <> 'System Session'
        GROUP BY DATE(logged_at), machine_identifier, os_user, application_name
        ON DUPLICATE KEY UPDATE
            active_seconds = active_seconds + VALUES(active_seconds),
            idle_seconds   = idle_seconds + VALUES(idle_seconds)
    ", $max_id));
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table_logs} SET rolled_seconds = duration_seconds WHERE id <= %d AND duration_seconds <> rolled_seconds", $max_id
    ));

    if ($locked === null || $inserted === false || $updated === false || $wpdb->query('COMMIT') === false) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    return (int) $updated;
}

/** First day (Y-m-d, WordPress timezone) that must be kept for a retention setting, or null. */
function aw_tracker_retention_cutoff_day($setting) {
    $today = new DateTimeImmutable('today', wp_timezone());
    $map = array(
        '2_hours'   => 'today',
        '3_months'  => '-3 months',
        '6_months'  => '-6 months',
        '12_months' => '-12 months',
        '36_months' => '-36 months',
        '60_months' => '-60 months',
    );
    if (!isset($map[$setting])) {
        return null; // 'indefinite' or unknown: never purge
    }
    return $setting === '2_hours' ? $today->format('Y-m-d') : $today->modify($map[$setting])->format('Y-m-d');
}

function aw_tracker_run_daily_maintenance() {
    global $wpdb;
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;

    $rolled = aw_tracker_rollup_pending();
    update_option('aw_tracker_last_rollup_since', current_time('mysql') . ($rolled === false ? ' (FAILED)' : ' (' . (int) $rolled . ' rows)'));
    if ($rolled === false) {
        // Never delete raw data that could not be summarised.
        update_option('aw_tracker_last_prune_time', current_time('mysql') . ' (skipped: rollup failed)');
        return array('deleted_raw' => 0, 'deleted_summary' => 0, 'error' => 'rollup_failed');
    }

    // Raw purges are whole days, and only rows already in the summary are removed.
    $deleted_raw = 0;
    $raw_cutoff  = aw_tracker_retention_cutoff_day(get_option('aw_raw_retention_setting', '6_months'));
    if ($raw_cutoff) {
        $deleted_raw = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_logs} WHERE logged_at < %s AND rolled_seconds = duration_seconds", $raw_cutoff . ' 00:00:00'
        ));
        if ($deleted_raw !== false && $raw_cutoff > (string) get_option('aw_raw_complete_from', '')) {
            update_option('aw_raw_complete_from', $raw_cutoff);
        }
    }

    $deleted_summary = 0;
    $summary_cutoff  = aw_tracker_retention_cutoff_day(get_option('aw_summary_retention_setting', '36_months'));
    if ($summary_cutoff) {
        $deleted_summary = $wpdb->query($wpdb->prepare("DELETE FROM {$table_summary} WHERE log_date < %s", $summary_cutoff));
    }

    update_option('aw_tracker_last_prune_time', current_time('mysql'));
    update_option('aw_tracker_last_prune_deleted_raw', (int) $deleted_raw);
    update_option('aw_tracker_last_prune_deleted_summary', (int) $deleted_summary);

    return array('deleted_raw' => (int) $deleted_raw, 'deleted_summary' => (int) $deleted_summary);
}

/** Start/end (end exclusive) in WordPress local time for a time-range filter. */
function aw_tracker_time_bounds($time_range, $date_from = '', $date_to = '') {
    $tz    = wp_timezone();
    $now   = new DateTimeImmutable('now', $tz);
    $today = $now->setTime(0, 0);
    $week  = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $month = $today->modify('first day of this month');
    switch ($time_range) {
        case '1h':         return array($now->modify('-1 hour'), null);
        case 'today':      return array($today, null);
        case 'yesterday':  return array($today->modify('-1 day'), $today);
        case '24h':        return array($now->modify('-24 hours'), null);
        case '7d':         return array($now->modify('-7 days'), null);
        case 'this_week':  return array($week, null);
        case 'last_week':  return array($week->modify('-7 days'), $week);
        case 'this_month': return array($month, null);
        case 'last_month': return array($month->modify('-1 month'), $month);
        case 'custom':
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date_from, $tz);
            $to   = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date_to, $tz);
            if (!$from || !$to) {
                return array(null, null);
            }
            if ($from > $to) {
                list($from, $to) = array($to, $from);
            }
            return array($from, $to->modify('+1 day'));
    }
    return array(null, null);
}

function aw_tracker_time_sql($time_range, $date_from = '', $date_to = '', $column = 'l.logged_at') {
    global $wpdb;
    list($start, $end) = aw_tracker_time_bounds($time_range, $date_from, $date_to);
    $sql = '';
    if ($start) $sql .= $wpdb->prepare(" AND {$column} >= %s", $start->format('Y-m-d H:i:s'));
    if ($end)   $sql .= $wpdb->prepare(" AND {$column} < %s", $end->format('Y-m-d H:i:s'));
    return $sql;
}

/**
 * Derived table for usage totals: raw logs from the first fully retained day,
 * daily summaries before it, so reports keep working after raw logs are purged.
 * Use as "FROM {$source} l". Session markers are excluded.
 */
function aw_tracker_usage_source() {
    global $wpdb;
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;
    $boundary      = (string) get_option('aw_raw_complete_from', '');
    $raw = "SELECT machine_identifier, os_user, application_name, is_idle, duration_seconds, logged_at
            FROM {$table_logs} WHERE application_name <> 'System Session'";
    if ($boundary === '') {
        return "({$raw})";
    }
    return $wpdb->prepare("({$raw} AND logged_at >= %s
        UNION ALL SELECT machine_identifier, os_user, application_name, 0, active_seconds, CAST(log_date AS DATETIME)
            FROM {$table_summary} WHERE log_date < %s AND active_seconds > 0
        UNION ALL SELECT machine_identifier, os_user, application_name, 1, idle_seconds, CAST(log_date AS DATETIME)
            FROM {$table_summary} WHERE log_date < %s AND idle_seconds > 0)",
        $boundary . ' 00:00:00', $boundary, $boundary);
}

// Helper: render the standard time-range <select> + optional custom date inputs
// $suffix: unique string to namespace the custom-date span ID across multiple forms on the page
function aw_tracker_time_range_select($time_range, $suffix = 'main', $date_from = '', $date_to = '') {
    $options = [
        'today'      => 'Today (Since Midnight)',
        'yesterday'  => 'Yesterday',
        '1h'         => 'Previous 1 Hour',
        '24h'        => 'Past 24 Hours (Rolling)',
        '7d'         => 'Past 7 Days',
        'this_week'  => 'This Week',
        'last_week'  => 'Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'all'        => 'All Time Logs',
        'custom'     => 'Custom Range…',
    ];
    $span_id = 'aw_custom_dates_' . esc_attr($suffix);
    echo '<select name="time_range" onchange="document.getElementById(\'' . $span_id . '\').style.display=(this.value===\'custom\'?\'flex\':\'none\')">';
    foreach ($options as $val => $label) {
        $sel = selected($time_range, $val, false);
        echo '<option value="' . esc_attr($val) . '" ' . $sel . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    $display = $time_range === 'custom' ? 'flex' : 'none';
    echo '<span id="' . $span_id . '" style="display:' . $display . '; gap:6px; align-items:center;">';
    echo '<input type="date" name="date_from" value="' . esc_attr($date_from) . '" style="padding:3px 6px;">';
    echo '<span>to</span>';
    echo '<input type="date" name="date_to" value="' . esc_attr($date_to) . '" style="padding:3px 6px;">';
    echo '</span>';
}

add_action('admin_menu', 'aw_tracker_register_admin_menu');
add_action('admin_post_aw_export_csv', 'aw_tracker_handle_csv_export');
add_action('admin_post_aw_export_xlsx', 'aw_tracker_handle_xlsx_export');
add_action('admin_post_aw_bulk_import_devices', 'aw_tracker_handle_bulk_device_csv_import');
add_action('admin_post_aw_update_single_device', 'aw_tracker_handle_single_device_update');
add_action('admin_post_aw_save_retention_settings', 'aw_tracker_handle_save_retention_settings');
add_action('admin_post_aw_trigger_manual_maintenance', 'aw_tracker_handle_manual_maintenance');
add_action('admin_post_aw_reregister_cron', 'aw_tracker_handle_reregister_cron');
add_action('admin_post_aw_regenerate_api_token', 'aw_tracker_handle_regenerate_api_token');

function aw_tracker_register_admin_menu() {
    add_menu_page(
        'ActivityTracker Hub',
        'Activity Tracker',
        'view_aw_reports',
        'activitywatch-tracker',
        'aw_tracker_render_admin_dashboard',
        'dashicons-chart-area',
        30
    );
}

function aw_tracker_handle_save_retention_settings() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_retention_settings_nonce');

    $raw_setting     = sanitize_text_field($_POST['raw_retention_setting'] ?? '6_months');
    $summary_setting = sanitize_text_field($_POST['summary_retention_setting'] ?? '36_months');
    $idle_threshold  = (int) ($_POST['idle_threshold_seconds'] ?? 300);

    // Validate idle threshold is one of the allowed values
    $allowed_thresholds = [60, 120, 300, 600, 900];
    if (!in_array($idle_threshold, $allowed_thresholds)) {
        $idle_threshold = 300;
    }

    update_option('aw_raw_retention_setting', $raw_setting);
    update_option('aw_summary_retention_setting', $summary_setting);
    update_option('aw_idle_threshold_seconds', $idle_threshold);

    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=devices&settings_saved=1'));
    exit;
}

function aw_tracker_handle_manual_maintenance() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_manual_maintenance_nonce');

    $result = aw_tracker_run_daily_maintenance();

    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=devices&pruned=1&deleted_raw=' . (int)$result['deleted_raw']));
    exit;
}

// 1. Report exports (CSV + XLSX share one builder so filters never drift)
/**
 * Shared app/user/machine/department/time filter SQL for the export handlers,
 * built from the same query args the dashboard tabs use. A restricted user's
 * department always overrides the department query arg.
 */
function aw_tracker_export_filter_sql($default_range = 'today') {
    global $wpdb;

    $sql         = '';
    $app_filter  = isset($_GET['app_name'])   ? sanitize_text_field($_GET['app_name'])   : '';
    $user_filter = isset($_GET['os_user'])    ? sanitize_text_field($_GET['os_user'])    : '';
    $mach_filter = isset($_GET['machine'])    ? sanitize_text_field($_GET['machine'])    : '';
    $time_range  = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : $default_range;
    $date_from   = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
    $date_to     = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';

    if ($app_filter)  $sql .= $wpdb->prepare(" AND l.application_name = %s", $app_filter);
    if ($user_filter) $sql .= $wpdb->prepare(" AND l.os_user = %s", $user_filter);
    if ($mach_filter) $sql .= $wpdb->prepare(" AND l.machine_identifier = %s", $mach_filter);
    $dept = aw_tracker_export_department();
    if ($dept)        $sql .= $wpdb->prepare(" AND d.department = %s", $dept);

    return $sql . aw_tracker_time_sql($time_range, $date_from, $date_to);
}

function aw_tracker_export_department() {
    $restriction = aw_tracker_get_user_department_restriction();
    if ($restriction) {
        return $restriction;
    }
    return isset($_GET['department']) ? sanitize_text_field($_GET['department']) : '';
}

/** Returns array($title, $headers, $rows) for a report type using the current request filters. */
function aw_tracker_build_report($report_type) {
    global $wpdb;
    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    $usage         = aw_tracker_usage_source();
    $join          = "LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier";
    $default_range = $report_type === 'user_across_pcs' ? 'all' : 'today';
    $time_range    = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : $default_range;
    $date_from     = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
    $date_to       = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';
    $range_label   = $time_range === 'custom' ? $date_from . ' to ' . $date_to : $time_range;
    $filters       = aw_tracker_export_filter_sql($default_range);
    $rows          = array();

    if ($report_type === 'low_pc_usage') {
        $threshold = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 3.0;
        $results   = aw_tracker_low_pc_usage_rows($threshold, $time_range, $date_from, $date_to, aw_tracker_export_department(),
                                                  isset($_GET['machine']) ? sanitize_text_field($_GET['machine']) : '');
        foreach ($results as $r) {
            $rows[] = array($r->machine_identifier, $r->active_hrs, $r->idle_hrs, round($r->active_hrs + $r->idle_hrs, 2), $range_label);
        }
        return array('Low PC Usage', array('Machine Hostname', 'Active Hours', 'Idle Hours', 'Total Logged Hours', 'Time Range Filter'), $rows);
    }

    if ($report_type === 'low_app_usage') {
        $threshold = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 2.0;
        $results   = $wpdb->get_results($wpdb->prepare("
            SELECT l.application_name, l.machine_identifier, l.os_user, ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs
            FROM {$usage} l {$join}
            WHERE l.is_idle = 0 AND l.application_name <> 'System Idle' {$filters}
            GROUP BY l.application_name, l.machine_identifier, l.os_user
            HAVING active_hrs < %f
            ORDER BY active_hrs ASC
        ", $threshold));
        foreach ($results as $r) {
            $rows[] = array($r->application_name, $r->machine_identifier, $r->os_user, $r->active_hrs, $threshold, $range_label);
        }
        return array('Low App Usage', array('Application', 'Machine Hostname', 'OS User', 'Active Hours', 'Threshold (Hrs)', 'Time Range Filter'), $rows);
    }

    if ($report_type === 'user_across_pcs') {
        $top_time = aw_tracker_time_sql($time_range, $date_from, $date_to, 'top_app.logged_at');
        $results  = $wpdb->get_results("
            SELECT l.os_user, l.machine_identifier, ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs,
                   (SELECT top_app.application_name FROM {$usage} top_app
                     WHERE top_app.os_user = l.os_user AND top_app.machine_identifier = l.machine_identifier
                       AND top_app.is_idle = 0 AND top_app.application_name <> 'System Idle' {$top_time}
                     GROUP BY top_app.application_name ORDER BY SUM(top_app.duration_seconds) DESC LIMIT 1) as application_name
            FROM {$usage} l {$join}
            WHERE l.is_idle = 0 {$filters}
            GROUP BY l.os_user, l.machine_identifier
            ORDER BY l.os_user ASC, active_hrs DESC
        ");
        foreach ($results as $r) {
            $rows[] = array($r->os_user, $r->machine_identifier, $r->active_hrs, $r->application_name);
        }
        return array('Floating User Matrix', array('OS User', 'Machine Hostname', 'Active Hours', 'Top Application'), $rows);
    }

    if ($report_type === 'app_usage') {
        $where = "WHERE l.is_idle = 0 AND l.application_name <> 'System Idle' {$filters}";
        if (isset($_GET['group_by']) && $_GET['group_by'] === 'user') {
            $results = $wpdb->get_results("
                SELECT l.application_name, l.os_user, l.machine_identifier, ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs
                FROM {$usage} l {$join} {$where}
                GROUP BY l.application_name, l.os_user, l.machine_identifier
                ORDER BY active_hrs DESC
            ");
            foreach ($results as $r) {
                $rows[] = array($r->application_name, $r->os_user, $r->machine_identifier, $r->active_hrs, $range_label);
            }
            return array('App Usage by User', array('Application', 'OS User', 'Machine Hostname', 'Active Hours', 'Time Range Filter'), $rows);
        }
        $results = $wpdb->get_results("
            SELECT l.application_name, ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs,
                   COUNT(DISTINCT l.machine_identifier) as machine_count, COUNT(DISTINCT l.os_user) as user_count
            FROM {$usage} l {$join} {$where}
            GROUP BY l.application_name
            ORDER BY active_hrs DESC
        ");
        foreach ($results as $r) {
            $rows[] = array($r->application_name, $r->active_hrs, $r->machine_count, $r->user_count, $range_label);
        }
        return array('App Usage', array('Application', 'Active Hours', 'Machines Used On', 'Distinct Users', 'Time Range Filter'), $rows);
    }

    $results = $wpdb->get_results("
        SELECT l.* FROM {$table_logs} l {$join}
        WHERE 1=1 {$filters}
        ORDER BY l.logged_at DESC LIMIT 20000
    ");
    foreach ($results as $r) {
        $rows[] = array(
            $r->machine_identifier, $r->os_user, $r->os_platform, $r->application_name, $r->window_title,
            round($r->duration_seconds / 60, 1), $r->is_idle ? 'Idle' : 'Active', $r->logged_at
        );
    }
    return array('Raw Logs', array('Machine', 'OS User', 'OS Platform', 'Application', 'Window Title', 'Duration (Mins)', 'State', 'Logged At'), $rows);
}

/** Registered devices (including ones with no activity at all) below the active-hours threshold. */
function aw_tracker_low_pc_usage_rows($threshold, $time_range, $date_from, $date_to, $department = '', $machine = '') {
    global $wpdb;
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    $usage         = aw_tracker_usage_source();
    $time_sql      = aw_tracker_time_sql($time_range, $date_from, $date_to);
    $device_where  = 'WHERE 1=1';
    if ($department) $device_where .= $wpdb->prepare(' AND d.department = %s', $department);
    if ($machine)    $device_where .= $wpdb->prepare(' AND d.machine_identifier = %s', $machine);
    return $wpdb->get_results($wpdb->prepare("
        SELECT * FROM (
            SELECT d.machine_identifier,
                   ROUND(COALESCE(u.active_seconds, 0)/3600, 2) as active_hrs,
                   ROUND(COALESCE(u.idle_seconds, 0)/3600, 2)   as idle_hrs
            FROM {$table_devices} d
            LEFT JOIN (
                SELECT l.machine_identifier,
                       SUM(CASE WHEN l.is_idle = 0 THEN l.duration_seconds ELSE 0 END) as active_seconds,
                       SUM(CASE WHEN l.is_idle = 1 THEN l.duration_seconds ELSE 0 END) as idle_seconds
                FROM {$usage} l
                WHERE 1=1 {$time_sql}
                GROUP BY l.machine_identifier
            ) u ON u.machine_identifier = d.machine_identifier
            {$device_where}
        ) pcs
        WHERE active_hrs < %f
        ORDER BY active_hrs ASC
    ", $threshold));
}

/** Prevent spreadsheet formula injection from user-controlled text. */
function aw_tracker_spreadsheet_safe($value) {
    if (is_string($value) && $value !== '' && strpos("=+-@\t\r", $value[0]) !== false && !is_numeric($value)) {
        return "'" . $value;
    }
    return $value;
}

function aw_tracker_handle_csv_export() {
    if (!current_user_can('view_aw_reports')) wp_die('Unauthorized');

    $report_type = isset($_GET['report_type']) ? sanitize_key($_GET['report_type']) : 'raw_logs';
    list(, $headers, $rows) = aw_tracker_build_report($report_type);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=AW_Report_' . $report_type . '_' . wp_date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, array_map('aw_tracker_spreadsheet_safe', $row));
    }
    fclose($output);
    exit;
}

// ==================== XLSX EXPORT HANDLER (Pure PHP ZIP — real .xlsx, no library) ====================

/**
 * Build a minimal but valid .xlsx file (OOXML) from $headers + $rows.
 * Returns the raw binary ZIP string ready to send as a download.
 * No external library required — uses PHP's built-in ZipArchive.
 */
function aw_build_xlsx(array $headers, array $rows, string $sheet_title = 'Report'): string {
    // ── 1. Collect all unique string values into a shared-strings table ──────
    $strings      = [];
    $string_index = [];

    $add_string = function(string $val) use (&$strings, &$string_index): int {
        $val = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', wp_check_invalid_utf8($val, true)) ?? '';
        if (!isset($string_index[$val])) {
            $string_index[$val] = count($strings);
            $strings[]          = $val;
        }
        return $string_index[$val];
    };

    // Pre-populate with headers
    $header_indices = array_map(fn($h) => $add_string((string)$h), $headers);

    // Pre-populate with string cells
    $cell_data = [];
    foreach ($rows as $row) {
        $cell_row = [];
        foreach ($row as $cell) {
            if (is_int($cell) || is_float($cell) || (is_string($cell) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $cell))) {
                $cell_row[] = ['type' => 'n', 'value' => $cell];
            } else {
                $cell_row[] = ['type' => 's', 'value' => $add_string((string)$cell)];
            }
        }
        $cell_data[] = $cell_row;
    }

    // ── 2. Build sharedStrings.xml ───────────────────────────────────────────
    $ss  = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    $ss .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $ss .= ' count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $ss .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
    }
    $ss .= '</sst>';

    // ── 3. Build styles.xml (2 styles: normal + bold blue header) ───────────
    $styles  = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $styles .= '<fonts count="2">';
    $styles .= '<font><sz val="11"/><name val="Calibri"/></font>';                          // 0 normal
    $styles .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'; // 1 white bold
    $styles .= '</fonts>';
    $styles .= '<fills count="3">';
    $styles .= '<fill><patternFill patternType="none"/></fill>';
    $styles .= '<fill><patternFill patternType="gray125"/></fill>';
    $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FF2271B1"/></patternFill></fill>'; // 2 WP blue
    $styles .= '</fills>';
    $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $styles .= '<cellXfs count="2">';
    $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'; // 0 normal
    $styles .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'; // 1 header
    $styles .= '</cellXfs>';
    $styles .= '</styleSheet>';

    // ── 4. Build sheet1.xml ──────────────────────────────────────────────────
    $col_letters = array_map(function ($i) {
        $letters = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }
        return $letters;
    }, range(0, count($headers) - 1));
    $sheet_title = substr(preg_replace('/[\[\]:*?\/\\]/', ' ', $sheet_title), 0, 31) ?: 'Report';

    $sheet  = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheet .= '<sheetData>';

    // Header row (row 1, style 1 = bold blue)
    $sheet .= '<row r="1">';
    foreach ($header_indices as $ci => $si) {
        $col = $col_letters[$ci] . '1';
        $sheet .= '<c r="' . $col . '" t="s" s="1"><v>' . $si . '</v></c>';
    }
    $sheet .= '</row>';

    // Data rows
    foreach ($cell_data as $ri => $row) {
        $r = $ri + 2; // 1-indexed, row 1 is header
        $sheet .= '<row r="' . $r . '">';
        foreach ($row as $ci => $cell) {
            $col = $col_letters[$ci] . $r;
            if ($cell['type'] === 'n') {
                $sheet .= '<c r="' . $col . '"><v>' . htmlspecialchars((string)$cell['value'], ENT_XML1) . '</v></c>';
            } else {
                $sheet .= '<c r="' . $col . '" t="s"><v>' . $cell['value'] . '</v></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    // ── 5. Assemble ZIP in a temp file ───────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'aw_xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . htmlspecialchars($sheet_title, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/sharedStrings.xml', $ss);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();

    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

function aw_tracker_handle_xlsx_export() {
    if (!current_user_can('view_aw_reports')) wp_die('Unauthorized');
    if (!class_exists('ZipArchive')) wp_die('XLSX export needs the PHP zip extension. Use CSV export instead.');

    $report_type = isset($_GET['report_type']) ? sanitize_key($_GET['report_type']) : 'raw_logs';
    list($title, $headers, $rows) = aw_tracker_build_report($report_type);

    $xlsx_data = aw_build_xlsx($headers, $rows, $title);
    $filename  = 'AW_' . $report_type . '_' . wp_date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx_data));
    header('Cache-Control: max-age=0');
    echo $xlsx_data;
    exit;
}

// 2. Bulk CSV Device Onboarding Handler
function aw_tracker_handle_bulk_device_csv_import() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_bulk_import_devices_nonce');
    global $wpdb;

    if (isset($_FILES['device_csv']) && $_FILES['device_csv']['error'] == 0) {
        $file = $_FILES['device_csv']['tmp_name'];
        $handle = fopen($file, "r");
        
        $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
        $row = 0;

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row++;
            if ($row == 1) continue;

            $machine    = sanitize_text_field($data[0] ?? '');
            $ashram     = sanitize_text_field($data[1] ?? 'Main Ashram');
            $building   = sanitize_text_field($data[2] ?? 'Main Building');
            $room       = sanitize_text_field($data[3] ?? 'General Room');
            $department = sanitize_text_field($data[4] ?? 'General Department');

            if (!empty($machine)) {
                $wpdb->query($wpdb->prepare("
                    INSERT INTO $table_devices (machine_identifier, ashram, building, room, department, last_seen)
                    VALUES (%s, %s, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE 
                        ashram = VALUES(ashram),
                        building = VALUES(building),
                        room = VALUES(room),
                        department = VALUES(department)
                ", $machine, $ashram, $building, $room, $department));
            }
        }
        fclose($handle);
    }
    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=devices&imported=1'));
    exit;
}

// 3. Single Device Location Update Handler
function aw_tracker_handle_single_device_update() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_update_single_device_nonce');
    global $wpdb;

    $machine    = sanitize_text_field($_POST['machine_identifier'] ?? '');
    $ashram     = sanitize_text_field($_POST['ashram'] ?? '');
    $building   = sanitize_text_field($_POST['building'] ?? '');
    $room       = sanitize_text_field($_POST['room'] ?? '');
    $department = sanitize_text_field($_POST['department'] ?? '');

    if (!empty($machine)) {
        $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
        $wpdb->update(
            $table_devices,
            array('ashram' => $ashram, 'building' => $building, 'room' => $room, 'department' => $department),
            array('machine_identifier' => $machine)
        );
    }
    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=devices&updated=1'));
    exit;
}

function aw_tracker_handle_regenerate_api_token() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_regenerate_api_token_nonce');
    update_option('aw_tracker_api_secret', wp_generate_password(40, false));
    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=devices&token_regenerated=1'));
    exit;
}

function aw_tracker_scope_join($dept_filter) {
    global $wpdb;
    if (!$dept_filter) {
        return '';
    }
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;
    return $wpdb->prepare(" INNER JOIN {$table_devices} sd ON sd.machine_identifier = l.machine_identifier AND sd.department = %s", $dept_filter);
}

// ==================== DASHBOARD DISPLAY ====================
function aw_tracker_render_admin_dashboard() {
    global $wpdb;
    $active_tab       = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
    $selected_machine = isset($_GET['machine']) ? sanitize_text_field($_GET['machine']) : '';
    $time_range       = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : 'today';
    $date_from        = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to          = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    $paged            = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page         = 50;

    $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
    $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;

    $dept_filter = aw_tracker_get_user_department_restriction();
    $table_usage = aw_tracker_usage_source();

    // Query Connected Devices (Scoped by department if Dept Admin)
    $devices_query = "SELECT * FROM {$table_devices}";
    if ($dept_filter) {
        $devices_query .= $wpdb->prepare(" WHERE department = %s", $dept_filter);
    }
    $devices_query .= " ORDER BY last_seen DESC";
    $devices = $wpdb->get_results($devices_query);

    // ── Centralised WHERE-clause builder (Gap 2: full date range set) ──────────
    $where_clause = "WHERE 1=1";
    if (!empty($selected_machine)) {
        $where_clause .= $wpdb->prepare(" AND l.machine_identifier = %s", $selected_machine);
    }
    if ($dept_filter) {
        $where_clause .= $wpdb->prepare(" AND d.department = %s", $dept_filter);
    }

    $where_clause .= aw_tracker_time_sql($time_range, $date_from, $date_to);
    // 'all' → no date clause added
    ?>

    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:10px;">
            <span class="dashicons dashicons-chart-area" style="font-size:30px; width:30px; height:30px;"></span>
            ActivityWatch Central Enterprise Hub
        </h1>
        <p>Enterprise PC, Floating User & Application Usage Analytics <?php if ($dept_filter) echo $dept_filter === AW_TRACKER_NO_DEPARTMENT ? '— <strong style="color:#d63638;">No department is assigned to your account, so no data is shown. Ask an administrator to set your department.</strong>' : '— <strong>Scoped to Department: ' . esc_html($dept_filter) . '</strong>'; ?></p>

        <?php if (isset($_GET['pruned'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Daily summary rollup completed and <strong><?php echo intval($_GET['deleted_raw']); ?></strong> expired raw log records were purged from the database.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['settings_saved'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Retention policy settings updated successfully.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['cron_registered'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> The daily maintenance cron event has been re-registered and is scheduled for <strong>02:00 AM tomorrow</strong>.</p>
            </div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=activitywatch-tracker&tab=dashboard" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard Analytics</a>
            <a href="?page=activitywatch-tracker&tab=low_usage" class="nav-tab <?php echo $active_tab === 'low_usage' ? 'nav-tab-active' : ''; ?>">Low PC Usage</a>
            <a href="?page=activitywatch-tracker&tab=low_app_usage" class="nav-tab <?php echo $active_tab === 'low_app_usage' ? 'nav-tab-active' : ''; ?>">Low App Usage</a>
            <a href="?page=activitywatch-tracker&tab=app_usage" class="nav-tab <?php echo $active_tab === 'app_usage' ? 'nav-tab-active' : ''; ?>">App Usage Report</a>
            <a href="?page=activitywatch-tracker&tab=dept_summary" class="nav-tab <?php echo $active_tab === 'dept_summary' ? 'nav-tab-active' : ''; ?>">Department Summary</a>
            <a href="?page=activitywatch-tracker&tab=user_matrix" class="nav-tab <?php echo $active_tab === 'user_matrix' ? 'nav-tab-active' : ''; ?>">Floating User Matrix</a>
            <?php if (current_user_can('manage_options') || current_user_can('manage_aw_tracker')) : ?>
                <a href="?page=activitywatch-tracker&tab=devices" class="nav-tab <?php echo $active_tab === 'devices' ? 'nav-tab-active' : ''; ?>">Devices & Settings</a>
                <a href="?page=activitywatch-tracker&tab=diagnostics" class="nav-tab <?php echo $active_tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>" style="color:<?php echo $active_tab === 'diagnostics' ? '' : '#b32d2e'; ?>;">
                    <span class="dashicons dashicons-search" style="font-size:14px; vertical-align:middle; margin-top:-2px;"></span> Diagnostics
                </a>
            <?php endif; ?>
        </h2>

        <?php if ($active_tab === 'dashboard') : 
            $top_apps = $wpdb->get_results("
                SELECT l.application_name, SUM(l.duration_seconds) as total_seconds
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause AND l.is_idle = 0
                GROUP BY l.application_name ORDER BY total_seconds DESC LIMIT 7
            ");

            $usage_summary = $wpdb->get_row("
                SELECT 
                    SUM(CASE WHEN l.is_idle = 0 THEN l.duration_seconds ELSE 0 END) as active_seconds,
                    SUM(CASE WHEN l.is_idle = 1 THEN l.duration_seconds ELSE 0 END) as idle_seconds
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause
            ");

            $total_logs = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$table_logs} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause
            ");
            $total_pages = ceil($total_logs / $per_page);
            $offset = ($paged - 1) * $per_page;

            $recent_logs = $wpdb->get_results($wpdb->prepare("
                SELECT l.* 
                FROM {$table_logs} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause 
                ORDER BY l.logged_at DESC 
                LIMIT %d OFFSET %d
            ", $per_page, $offset));
        ?>
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px; background:#f0f0f1; padding:12px; border-radius:6px;">
                    <form method="GET" style="display:flex; gap:15px; align-items:center;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <label><strong>Device:</strong></label>
                        <select name="machine">
                            <option value="">-- All Connected Machines --</option>
                            <?php foreach ($devices as $dev) : ?>
                                <option value="<?php echo esc_attr($dev->machine_identifier); ?>" <?php selected($selected_machine, $dev->machine_identifier); ?>>
                                    <?php echo esc_html($dev->machine_identifier); ?> (<?php echo esc_html($dev->department); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label><strong>Time Range:</strong></label>
                        <?php aw_tracker_time_range_select($time_range, 'dashboard', $date_from, $date_to); ?>

                        <button type="submit" class="button button-primary">Filter Reports</button>
                    </form>

                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&report_type=raw_logs&machine=' . urlencode($selected_machine) . '&time_range=' . urlencode($time_range) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to)); ?>" class="button button-secondary" style="margin-left:auto;">
                        <span class="dashicons dashicons-download" style="margin-top:4px;"></span> Export CSV
                    </a>
                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_xlsx&report_type=raw_logs&time_range=' . urlencode($time_range) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . (!empty($selected_machine) ? '&machine=' . urlencode($selected_machine) : '')); ?>" class="button button-secondary" style="color:#217346;">
                        <span class="dashicons dashicons-media-spreadsheet" style="margin-top:4px;"></span> Export Excel
                    </a>
                </div>

                <?php
                // ── Gap 1: KPI Summary Cards ─────────────────────────────────────────
                $today_start  = wp_date('Y-m-d 00:00:00');

                // Total registered devices (scoped)
                $kpi_total_devices = count($devices);

                // Devices active today (last_seen >= today midnight)
                $kpi_active_today_q = "SELECT COUNT(*) FROM {$table_devices}";
                $kpi_active_today_q .= $wpdb->prepare(" WHERE last_seen >= %s", $today_start);
                if ($dept_filter) $kpi_active_today_q .= $wpdb->prepare(" AND department = %s", $dept_filter);
                $kpi_active_today = (int) $wpdb->get_var($kpi_active_today_q);

                // Total active + idle hours for selected filter window
                $kpi_active_hrs = round(($usage_summary->active_seconds ?? 0) / 3600, 1);
                $kpi_idle_hrs   = round(($usage_summary->idle_seconds   ?? 0) / 3600, 1);

                // Distinct users seen in filter window
                $kpi_users = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT l.os_user)
                    FROM {$table_usage} l
                    LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                    {$where_clause} AND l.os_user != 'Unknown'
                ");
                ?>
                <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:15px; margin-bottom:20px;">
                    <?php
                    $kpi_cards = [
                        ['dashicons-desktop',    '#2271b1', 'Registered Devices',  $kpi_total_devices, ''],
                        ['dashicons-visibility', '#46b450', 'Active Today',         $kpi_active_today,  'synced since midnight'],
                        ['dashicons-groups',     '#00a0d2', 'Distinct Users',       $kpi_users,         'in selected period'],
                        ['dashicons-chart-bar',  '#3d9970', 'Total Active Time',    $kpi_active_hrs . ' hrs', ''],
                        ['dashicons-clock',      '#d9534f', 'Total Idle Time',      $kpi_idle_hrs . ' hrs',   ''],
                    ];
                    foreach ($kpi_cards as [$icon, $color, $label, $value, $sub]) : ?>
                        <div style="background:#fff; border:1px solid #ddd; border-top:4px solid <?php echo $color; ?>; border-radius:6px; padding:16px 14px; text-align:center;">
                            <span class="dashicons <?php echo $icon; ?>" style="color:<?php echo $color; ?>; font-size:26px; width:26px; height:26px;"></span>
                            <div style="font-size:22px; font-weight:700; color:#1d2327; margin:6px 0 2px;"><?php echo esc_html($value); ?></div>
                            <div style="font-size:12px; font-weight:600; color:#50575e;"><?php echo esc_html($label); ?></div>
                            <?php if ($sub) : ?><div style="font-size:11px; color:#999; margin-top:2px;"><?php echo esc_html($sub); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:25px;">
                    <div style="background:#f9f9f9; padding:20px; border-radius:8px; border:1px solid #e5e5e5;">
                        <h3>Top Applications Used</h3>
                        <canvas id="topAppsChart" height="200"></canvas>
                    </div>
                    <div style="background:#f9f9f9; padding:20px; border-radius:8px; border:1px solid #e5e5e5;">
                        <h3>Active Work Time vs. Idle Time</h3>
                        <canvas id="activeIdleChart" height="200"></canvas>
                    </div>
                </div>

                <div style="margin-top:30px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0;">Recent Activity Logs (Total: <?php echo number_format($total_logs); ?> records)</h3>
                        
                        <?php if ($total_pages > 1) : 
                            $base_url = add_query_arg(array(
                                'page' => 'activitywatch-tracker',
                                'machine' => $selected_machine,
                                'time_range' => $time_range,
                                'date_from' => $date_from,
                                'date_to' => $date_to,
                            ), admin_url('admin.php'));
                        ?>
                            <div class="tablenav-pages" style="margin:0;">
                                <span class="displaying-num"><?php echo number_format($total_logs); ?> items</span>
                                <span class="pagination-links">
                                    <?php if ($paged > 1) : ?>
                                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)); ?>">&laquo;</a>
                                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1, $base_url)); ?>">&lsaquo;</a>
                                    <?php endif; ?>
                                    
                                    <span class="paging-input">
                                        <span class="current-page"><?php echo $paged; ?></span> of <span class="total-pages"><?php echo $total_pages; ?></span>
                                    </span>

                                    <?php if ($paged < $total_pages) : ?>
                                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1, $base_url)); ?>">&rsaquo;</a>
                                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_url)); ?>">&raquo;</a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Machine</th>
                                <th>Logged User</th>
                                <th>OS Platform</th>
                                <th>Application / Action</th>
                                <th>Window / Session Details</th>
                                <th>Duration</th>
                                <th>State</th>
                                <th>Interval Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_logs)) : ?>
                                <tr><td colspan="8">No activity logs found for the selected filter range.</td></tr>
                            <?php else :
                                foreach ($recent_logs as $log) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($log->machine_identifier); ?></strong></td>
                                        <td><code><?php echo esc_html($log->os_user); ?></code></td>
                                        <td><span class="dashicons dashicons-desktop"></span> <?php echo esc_html($log->os_platform); ?></td>
                                        <td><code><?php echo esc_html($log->application_name); ?></code></td>
                                        <td><em><?php echo esc_html($log->window_title ? $log->window_title : '—'); ?></em></td>
                                        <td><strong><?php echo round($log->duration_seconds / 60, 1); ?> mins</strong></td>
                                        <td>
                                            <span style="color:<?php echo $log->is_idle ? '#d9534f' : '#5cb85c'; ?>; font-weight:bold;">
                                                <?php echo $log->is_idle ? 'Idle' : 'Active'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($log->logged_at); ?></td>
                                    </tr>
                                <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    new Chart(document.getElementById('topAppsChart'), {
                        type: 'bar',
                        data: {
                            labels: [<?php foreach($top_apps as $a) echo '"' . esc_js($a->application_name) . '",'; ?>],
                            datasets: [{ label: 'Hours', data: [<?php foreach($top_apps as $a) echo round($a->total_seconds/3600, 2) . ','; ?>], backgroundColor: '#2271b1' }]
                        },
                        options: { responsive: true, plugins: { legend: { display: false } } }
                    });

                    new Chart(document.getElementById('activeIdleChart'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Active Work Time (Hrs)', 'Idle Time (Hrs)'],
                            datasets: [{
                                data: [<?php echo round(($usage_summary->active_seconds??0)/3600, 2); ?>, <?php echo round(($usage_summary->idle_seconds??0)/3600, 2); ?>],
                                backgroundColor: ['#46b450', '#dc3232']
                            }]
                        },
                        options: { responsive: true }
                    });
                });
            </script>

        <?php elseif ($active_tab === 'low_usage') : 
            $threshold  = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 3.0;
            $time_range = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : 'today';
            $date_from  = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
            $date_to    = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';

            $where_clause = "WHERE 1=1";
            if ($dept_filter) {
                $where_clause .= $wpdb->prepare(" AND d.department = %s", $dept_filter);
            }
            $where_clause .= aw_tracker_time_sql($time_range, $date_from, $date_to);

            $low_pcs = aw_tracker_low_pc_usage_rows($threshold, $time_range, $date_from, $date_to, $dept_filter);
        ?>
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:#f0f0f1; padding:12px; border-radius:6px;">
                    <form method="GET" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <input type="hidden" name="tab" value="low_usage">

                        <label><strong>Time Range:</strong></label>
                        <?php aw_tracker_time_range_select($time_range, 'low_usage', $date_from, $date_to); ?>

                        <label><strong>Active Time Less Than:</strong></label>
                        <select name="threshold">
                            <option value="1.0" <?php selected($threshold, 1.0); ?>>1 Hour</option>
                            <option value="2.0" <?php selected($threshold, 2.0); ?>>2 Hours</option>
                            <option value="3.0" <?php selected($threshold, 3.0); ?>>3 Hours</option>
                            <option value="5.0" <?php selected($threshold, 5.0); ?>>5 Hours</option>
                        </select>
                        <button type="submit" class="button button-primary">Filter Low Usage PCs</button>
                    </form>

                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&report_type=low_pc_usage&threshold='.$threshold.'&time_range='.$time_range.'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to)); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </a>
                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_xlsx&report_type=low_pc_usage&threshold='.$threshold.'&time_range='.$time_range.'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to)); ?>" class="button button-secondary" style="color:#217346;">
                        <span class="dashicons dashicons-media-spreadsheet"></span> Export Excel
                    </a>
                </div>

                <h3>Under-Utilized Systems (Active < <?php echo $threshold; ?> Hours | Filter: <em><?php echo esc_html(ucfirst(str_replace('_',' ',$time_range))); ?></em>)</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Machine Hostname</th>
                            <th>Active Hours</th>
                            <th>Idle Hours</th>
                            <th>Utilization Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($low_pcs)) : ?>
                            <tr><td colspan="4">All monitored computers exceed the selected active usage threshold for this period.</td></tr>
                        <?php else :
                            foreach ($low_pcs as $pc) : ?>
                                <tr>
                                    <td><strong><code><?php echo esc_html($pc->machine_identifier); ?></code></strong></td>
                                    <td><strong style="color:#dc3232;"><?php echo $pc->active_hrs; ?> hrs</strong></td>
                                    <td><?php echo $pc->idle_hrs; ?> hrs</td>
                                    <td><span class="dashicons dashicons-warning" style="color:#d9534f;"></span> Low Active Utilization</td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'low_app_usage') : 
            // #18: Low Application Usage Reporting View
            $app_filter = isset($_GET['app_name']) ? sanitize_text_field($_GET['app_name']) : '';
            $threshold  = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 2.0;
            $time_range = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : 'today';
            $date_from  = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
            $date_to    = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';

            // Fetch distinct applications for dropdown filter
            $available_apps = $wpdb->get_col("SELECT DISTINCT l.application_name FROM {$table_logs} l" . aw_tracker_scope_join($dept_filter) . " WHERE l.application_name != 'System Session' ORDER BY application_name ASC LIMIT 50");

            $where_clause = "WHERE l.is_idle = 0";
            if (!empty($app_filter)) {
                $where_clause .= $wpdb->prepare(" AND l.application_name = %s", $app_filter);
            }
            if ($dept_filter) {
                $where_clause .= $wpdb->prepare(" AND d.department = %s", $dept_filter);
            }
            $where_clause .= aw_tracker_time_sql($time_range, $date_from, $date_to);

            $low_apps = $wpdb->get_results($wpdb->prepare("
                SELECT l.application_name, l.machine_identifier, l.os_user,
                       ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause
                GROUP BY l.application_name, l.machine_identifier, l.os_user
                HAVING active_hrs < %f
                ORDER BY active_hrs ASC
            ", $threshold));
        ?>
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:#f0f0f1; padding:12px; border-radius:6px;">
                    <form method="GET" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <input type="hidden" name="tab" value="low_app_usage">

                        <label><strong>Application:</strong></label>
                        <select name="app_name">
                            <option value="">-- All Tracked Applications --</option>
                            <?php foreach ($available_apps as $app) : ?>
                                <option value="<?php echo esc_attr($app); ?>" <?php selected($app_filter, $app); ?>><?php echo esc_html($app); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label><strong>Active Usage Less Than:</strong></label>
                        <select name="threshold">
                            <option value="0.5" <?php selected($threshold, 0.5); ?>>30 Mins</option>
                            <option value="1.0" <?php selected($threshold, 1.0); ?>>1 Hour</option>
                            <option value="2.0" <?php selected($threshold, 2.0); ?>>2 Hours</option>
                            <option value="3.0" <?php selected($threshold, 3.0); ?>>3 Hours</option>
                            <option value="5.0" <?php selected($threshold, 5.0); ?>>5 Hours</option>
                        </select>

                        <label><strong>Time Range:</strong></label>
                        <?php aw_tracker_time_range_select($time_range, 'low_app', $date_from, $date_to); ?>

                        <button type="submit" class="button button-primary">Filter Low Application Usage</button>
                    </form>

                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&report_type=low_app_usage&app_name='.urlencode($app_filter).'&threshold='.$threshold.'&time_range='.$time_range.'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to)); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </a>
                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_xlsx&report_type=low_app_usage&app_name='.urlencode($app_filter).'&threshold='.$threshold.'&time_range='.$time_range.'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to)); ?>" class="button button-secondary" style="color:#217346;">
                        <span class="dashicons dashicons-media-spreadsheet"></span> Export Excel
                    </a>
                </div>

                <h3>Under-Utilized Applications (Usage < <?php echo $threshold; ?> Hours | Filter: <em><?php echo esc_html($app_filter ?: 'All Applications'); ?></em>)</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Application Name</th>
                            <th>Machine Hostname</th>
                            <th>OS User</th>
                            <th>Active Usage</th>
                            <th>Threshold Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($low_apps)) : ?>
                            <tr><td colspan="5">No under-utilized application sessions matched the selected filter criteria.</td></tr>
                        <?php else :
                            foreach ($low_apps as $row) : ?>
                                <tr>
                                    <td><strong><code><?php echo esc_html($row->application_name); ?></code></strong></td>
                                    <td><code><?php echo esc_html($row->machine_identifier); ?></code></td>
                                    <td><code><?php echo esc_html($row->os_user); ?></code></td>
                                    <td><strong style="color:#d9534f;"><?php echo $row->active_hrs; ?> hrs</strong></td>
                                    <td><span class="dashicons dashicons-warning" style="color:#d9534f;"></span> Below Target Utilization</td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'app_usage') :
            // ── Gap 3: Application Usage Report ────────────────────────────────────
            $au_app     = isset($_GET['app_name'])   ? sanitize_text_field($_GET['app_name'])   : '';
            $au_user    = isset($_GET['os_user'])    ? sanitize_text_field($_GET['os_user'])    : '';
            $au_machine = isset($_GET['machine'])    ? sanitize_text_field($_GET['machine'])    : '';
            $au_dept    = isset($_GET['department']) ? sanitize_text_field($_GET['department']) : '';
            $time_range = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : 'today';
            $date_from  = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
            $date_to    = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';

            $now_ts_au    = current_time('timestamp');
            $au_where     = "WHERE l.is_idle = 0 AND l.application_name NOT IN ('System Session','System Idle')";

            if (!empty($au_app))     $au_where .= $wpdb->prepare(" AND l.application_name = %s", $au_app);
            if (!empty($au_user))    $au_where .= $wpdb->prepare(" AND l.os_user = %s", $au_user);
            if (!empty($au_machine)) $au_where .= $wpdb->prepare(" AND l.machine_identifier = %s", $au_machine);
            $d = $dept_filter ? $dept_filter : $au_dept;
            if (!empty($d)) {
                $au_where .= $wpdb->prepare(" AND d.department = %s", $d);
            }
            $au_where .= aw_tracker_time_sql($time_range, $date_from, $date_to);

            // App usage ranked
            $app_usage_rows = $wpdb->get_results("
                SELECT l.application_name,
                       ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs,
                       COUNT(DISTINCT l.machine_identifier)    as machine_count,
                       COUNT(DISTINCT l.os_user)               as user_count
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                {$au_where}
                GROUP BY l.application_name
                ORDER BY active_hrs DESC
            ");

            // Per user+machine breakdown across all apps in the current filter
            $app_user_rows = $wpdb->get_results("
                SELECT l.application_name, l.os_user, l.machine_identifier,
                       ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                {$au_where}
                GROUP BY l.application_name, l.os_user, l.machine_identifier
                ORDER BY active_hrs DESC
                LIMIT 500
            ");

            // Drill-down: per machine+user breakdown for selected app
            $app_drill = [];
            if (!empty($au_app)) {
                $app_drill = $wpdb->get_results("
                    SELECT l.machine_identifier, l.os_user,
                           ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs
                    FROM {$table_usage} l
                    LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                    {$au_where}
                    GROUP BY l.machine_identifier, l.os_user
                    ORDER BY active_hrs DESC
                ");
            }

            // Filter options
            $au_apps  = $wpdb->get_col("SELECT DISTINCT l.application_name FROM {$table_logs} l" . aw_tracker_scope_join($dept_filter) . " WHERE l.application_name NOT IN ('System Session','System Idle') ORDER BY application_name ASC LIMIT 100");
            $au_users = $wpdb->get_col("SELECT DISTINCT l.os_user FROM {$table_logs} l" . aw_tracker_scope_join($dept_filter) . " WHERE l.os_user != 'Unknown' ORDER BY os_user ASC LIMIT 100");
            $au_depts = $dept_filter ? array($dept_filter) : $wpdb->get_col("SELECT DISTINCT department FROM {$table_devices} ORDER BY department ASC");
        ?>
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <div style="background:#f0f0f1; padding:12px; border-radius:6px; margin-bottom:20px;">
                    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <input type="hidden" name="tab" value="app_usage">

                        <label><strong>Application:</strong></label>
                        <select name="app_name">
                            <option value="">-- All Apps --</option>
                            <?php foreach ($au_apps as $a) : ?>
                                <option value="<?php echo esc_attr($a); ?>" <?php selected($au_app, $a); ?>><?php echo esc_html($a); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label><strong>User:</strong></label>
                        <select name="os_user">
                            <option value="">-- All Users --</option>
                            <?php foreach ($au_users as $u) : ?>
                                <option value="<?php echo esc_attr($u); ?>" <?php selected($au_user, $u); ?>><?php echo esc_html($u); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label><strong>Machine:</strong></label>
                        <select name="machine">
                            <option value="">-- All Machines --</option>
                            <?php foreach ($devices as $dev) : ?>
                                <option value="<?php echo esc_attr($dev->machine_identifier); ?>" <?php selected($au_machine, $dev->machine_identifier); ?>><?php echo esc_html($dev->machine_identifier); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$dept_filter) : ?>
                        <label><strong>Department:</strong></label>
                        <select name="department">
                            <option value="">-- All Departments --</option>
                            <?php foreach ($au_depts as $dep) : ?>
                                <option value="<?php echo esc_attr($dep); ?>" <?php selected($au_dept, $dep); ?>><?php echo esc_html($dep); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <label><strong>Time Range:</strong></label>
                        <?php aw_tracker_time_range_select($time_range, 'app_usage', $date_from, $date_to); ?>

                        <button type="submit" class="button button-primary">Filter</button>
                        <?php if ($au_app || $au_user || $au_machine || $au_dept || $time_range !== 'today') : ?>
                            <a href="?page=activitywatch-tracker&tab=app_usage" class="button button-secondary">Clear</a>
                        <?php endif; ?>
                        &nbsp;
                        <?php $au_export_args = 'report_type=app_usage&app_name='.urlencode($au_app).'&os_user='.urlencode($au_user).'&machine='.urlencode($au_machine).'&department='.urlencode($au_dept).'&time_range='.urlencode($time_range).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>
                        <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&'.$au_export_args); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-download"></span> Export CSV
                        </a>
                        <a href="<?php echo admin_url('admin-post.php?action=aw_export_xlsx&'.$au_export_args); ?>" class="button button-secondary" style="color:#217346;">
                            <span class="dashicons dashicons-media-spreadsheet"></span> Export Excel
                        </a>
                        <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&group_by=user&'.$au_export_args); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-download"></span> Export CSV (per user)
                        </a>
                    </form>
                </div>

                <?php if (!empty($au_app)) : ?>
                <!-- Drill-down view for a specific app -->
                <h3>
                    <span class="dashicons dashicons-arrow-right-alt" style="color:#2271b1;"></span>
                    <?php echo esc_html($au_app); ?> — Per Machine &amp; User Breakdown
                </h3>
                <table class="widefat fixed striped" style="margin-bottom:30px;">
                    <thead>
                        <tr>
                            <th>Machine</th>
                            <th>OS User</th>
                            <th>Active Hours</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $drill_total = array_sum(array_column($app_drill, 'active_hrs'));
                        if (empty($app_drill)) : ?>
                            <tr><td colspan="4">No data found for selected filters.</td></tr>
                        <?php else :
                            foreach ($app_drill as $row) :
                                $pct = $drill_total > 0 ? round(($row->active_hrs / $drill_total) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html($row->machine_identifier); ?></code></td>
                                <td><code><?php echo esc_html($row->os_user); ?></code></td>
                                <td><strong><?php echo $row->active_hrs; ?> hrs</strong></td>
                                <td>
                                    <div style="background:#e5e5e5; border-radius:3px; overflow:hidden; height:16px; position:relative;">
                                        <div style="background:#2271b1; width:<?php echo $pct; ?>%; height:100%;"></div>
                                        <span style="position:absolute; left:6px; top:0; font-size:11px; line-height:16px; color:#fff; mix-blend-mode:difference;"><?php echo $pct; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- Per user + machine usage: keeps two users of one PC visible as separate rows -->
                <h3>Application Usage — By User &amp; Machine</h3>
                <table class="widefat fixed striped" style="margin-bottom:30px;">
                    <thead>
                        <tr>
                            <th>Application</th>
                            <th>OS User</th>
                            <th>Machine</th>
                            <th>Active Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($app_user_rows)) : ?>
                            <tr><td colspan="4">No application usage data found for the selected filters.</td></tr>
                        <?php else : foreach ($app_user_rows as $row) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($row->application_name); ?></strong></td>
                                <td><code><?php echo esc_html($row->os_user); ?></code></td>
                                <td><code><?php echo esc_html($row->machine_identifier); ?></code></td>
                                <td><strong><?php echo $row->active_hrs; ?> hrs</strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <!-- Overall ranked app usage table -->
                <h3>Application Usage — Ranked by Active Hours <?php echo !empty($au_app) ? '(All Apps for Context)' : ''; ?></h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Application</th>
                            <th>Active Hours</th>
                            <th>Machines Used On</th>
                            <th>Distinct Users</th>
                            <th>Usage Bar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($app_usage_rows)) : ?>
                            <tr><td colspan="6">No application usage data found for the selected filters.</td></tr>
                        <?php else :
                            $max_hrs = $app_usage_rows[0]->active_hrs;
                            foreach ($app_usage_rows as $i => $row) :
                                $bar_w = $max_hrs > 0 ? round(($row->active_hrs / $max_hrs) * 100) : 0;
                        ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <a href="?page=activitywatch-tracker&tab=app_usage&app_name=<?php echo urlencode($row->application_name); ?>&time_range=<?php echo urlencode($time_range); ?>" style="font-weight:600;">
                                        <?php echo esc_html($row->application_name); ?>
                                    </a>
                                </td>
                                <td><strong><?php echo $row->active_hrs; ?> hrs</strong></td>
                                <td><?php echo $row->machine_count; ?></td>
                                <td><?php echo $row->user_count; ?></td>
                                <td>
                                    <div style="background:#e5e5e5; border-radius:3px; overflow:hidden; height:14px;">
                                        <div style="background:#3d9970; width:<?php echo $bar_w; ?>%; height:100%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'dept_summary') :
            // ── Gap 4: Department Summary Report ───────────────────────────────────
            $time_range = isset($_GET['time_range']) ? sanitize_text_field($_GET['time_range']) : 'today';
            $date_from  = isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : '';
            $date_to    = isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : '';

            $ds_where     = "WHERE 1=1";

            if ($dept_filter) $ds_where .= $wpdb->prepare(" AND d.department = %s", $dept_filter);

            $ds_where .= aw_tracker_time_sql($time_range, $date_from, $date_to);

            // Per-department aggregated stats
            $dept_stats = $wpdb->get_results("
                SELECT d.department,
                       COUNT(DISTINCT l.machine_identifier)    as total_pcs,
                       COUNT(DISTINCT l.os_user)               as total_users,
                       ROUND(SUM(CASE WHEN l.is_idle=0 THEN l.duration_seconds ELSE 0 END)/3600, 2) as active_hrs,
                       ROUND(SUM(CASE WHEN l.is_idle=1 THEN l.duration_seconds ELSE 0 END)/3600, 2) as idle_hrs
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                {$ds_where}
                GROUP BY d.department
                ORDER BY active_hrs DESC
            ");

            // Top 5 apps per department (subquery approach)
            $dept_top_apps = [];
            foreach ($dept_stats as $ds_row) {
                $dept_name = $ds_row->department;
                $top = $wpdb->get_results($wpdb->prepare("
                    SELECT l.application_name,
                           ROUND(SUM(l.duration_seconds)/3600,2) as hrs
                    FROM {$table_usage} l
                    LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                    {$ds_where} AND d.department = %s AND l.is_idle = 0
                      AND l.application_name NOT IN ('System Session','System Idle')
                    GROUP BY l.application_name
                    ORDER BY hrs DESC
                    LIMIT 5
                ", $dept_name));
                $dept_top_apps[$dept_name] = $top;
            }
        ?>
            <div style="margin-top:20px;">
                <div style="background:#fff; padding:15px 20px; border:1px solid #ccd0d4; border-radius:8px; margin-bottom:20px;">
                    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <input type="hidden" name="tab" value="dept_summary">
                        <label><strong>Time Range:</strong></label>
                        <?php aw_tracker_time_range_select($time_range, 'dept_summary', $date_from, $date_to); ?>
                        <button type="submit" class="button button-primary">Filter</button>
                    </form>
                </div>

                <?php if (empty($dept_stats)) : ?>
                    <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                        <p>No department data available for the selected time range. Ensure devices have been assigned to departments in Devices &amp; Settings.</p>
                    </div>
                <?php else : ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(420px,1fr)); gap:20px;">
                    <?php foreach ($dept_stats as $ds_row) :
                        $top_apps_for_dept = $dept_top_apps[$ds_row->department] ?? [];
                        $total_hrs = $ds_row->active_hrs + $ds_row->idle_hrs;
                        $active_pct = $total_hrs > 0 ? round(($ds_row->active_hrs / $total_hrs) * 100) : 0;
                    ?>
                        <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; border-top:4px solid #2271b1;">
                            <h3 style="margin:0 0 12px; font-size:16px; display:flex; justify-content:space-between; align-items:center;">
                                <span><?php echo esc_html($ds_row->department ?: 'Unassigned'); ?></span>
                                <span style="font-size:12px; font-weight:400; color:#666;"><?php echo $ds_row->total_pcs; ?> PCs · <?php echo $ds_row->total_users; ?> Users</span>
                            </h3>

                            <!-- Active vs Idle bar -->
                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                                <span style="color:#3d9970;">Active: <?php echo $ds_row->active_hrs; ?> hrs</span>
                                <span style="color:#d9534f;">Idle: <?php echo $ds_row->idle_hrs; ?> hrs</span>
                            </div>
                            <div style="background:#f8d7da; border-radius:4px; overflow:hidden; height:14px; margin-bottom:14px;">
                                <div style="background:#3d9970; width:<?php echo $active_pct; ?>%; height:100%;"></div>
                            </div>

                            <!-- Top 5 apps -->
                            <?php if (!empty($top_apps_for_dept)) : ?>
                                <div style="font-size:12px; font-weight:600; color:#50575e; margin-bottom:6px;">TOP APPLICATIONS</div>
                                <?php
                                $max_app_hrs = $top_apps_for_dept[0]->hrs;
                                foreach ($top_apps_for_dept as $ta) :
                                    $ta_pct = $max_app_hrs > 0 ? round(($ta->hrs / $max_app_hrs) * 100) : 0;
                                ?>
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:5px;">
                                    <span style="width:130px; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo esc_attr($ta->application_name); ?>"><?php echo esc_html($ta->application_name); ?></span>
                                    <div style="flex:1; background:#e5e5e5; border-radius:3px; overflow:hidden; height:10px;">
                                        <div style="background:#2271b1; width:<?php echo $ta_pct; ?>%; height:100%;"></div>
                                    </div>
                                    <span style="width:50px; font-size:11px; color:#666; text-align:right;"><?php echo $ta->hrs; ?>h</span>
                                </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <p style="font-size:12px; color:#999; margin:0;">No application data for this period.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($active_tab === 'user_matrix') : 
            $where_clause = "WHERE l.is_idle = 0";
            if (!empty($selected_machine)) {
                $where_clause .= $wpdb->prepare(" AND l.machine_identifier = %s", $selected_machine);
            }
            if ($dept_filter) {
                $where_clause .= $wpdb->prepare(" AND d.department = %s", $dept_filter);
            }

            $user_matrix = $wpdb->get_results("
                SELECT l.os_user, l.machine_identifier,
                       ROUND(SUM(l.duration_seconds)/3600, 2) as active_hrs,
                       (
                           SELECT top_app.application_name 
                           FROM {$table_usage} top_app 
                           WHERE top_app.os_user = l.os_user 
                             AND top_app.machine_identifier = l.machine_identifier 
                             AND top_app.is_idle = 0 
                             AND top_app.application_name NOT IN ('System Session','System Idle')
                           GROUP BY top_app.application_name 
                           ORDER BY SUM(top_app.duration_seconds) DESC 
                           LIMIT 1
                       ) as application_name
                FROM {$table_usage} l
                LEFT JOIN {$table_devices} d ON l.machine_identifier = d.machine_identifier
                $where_clause
                GROUP BY l.os_user, l.machine_identifier
                ORDER BY l.os_user ASC, active_hrs DESC
            ");
        ?>
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:#f0f0f1; padding:12px; border-radius:6px; flex-wrap:wrap; gap:10px;">
                    <form method="GET" style="display:flex; gap:15px; align-items:center;">
                        <input type="hidden" name="page" value="activitywatch-tracker">
                        <input type="hidden" name="tab" value="user_matrix">
                        <label><strong>Filter by Machine:</strong></label>
                        <select name="machine">
                            <option value="">-- All Machines (Full Matrix) --</option>
                            <?php foreach ($devices as $dev) : ?>
                                <option value="<?php echo esc_attr($dev->machine_identifier); ?>" <?php selected($selected_machine, $dev->machine_identifier); ?>>
                                    <?php echo esc_html($dev->machine_identifier); ?> (<?php echo esc_html($dev->department); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary">Filter</button>
                        <?php if (!empty($selected_machine)) : ?>
                            <a href="?page=activitywatch-tracker&tab=user_matrix" class="button button-secondary">Clear Filter</a>
                        <?php endif; ?>
                    </form>
                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_csv&report_type=user_across_pcs' . (!empty($selected_machine) ? '&machine=' . urlencode($selected_machine) : '')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </a>
                    <a href="<?php echo admin_url('admin-post.php?action=aw_export_xlsx&report_type=user_across_pcs' . (!empty($selected_machine) ? '&machine=' . urlencode($selected_machine) : '')); ?>" class="button button-secondary" style="color:#217346;">
                        <span class="dashicons dashicons-media-spreadsheet"></span> Export Excel
                    </a>
                </div>

                <h3 style="margin-top:0;">
                    Floating User Activity
                    <?php if (!empty($selected_machine)) : ?>
                        — Scoped to <code><?php echo esc_html($selected_machine); ?></code>
                    <?php else : ?>
                        Across All PCs
                    <?php endif; ?>
                </h3>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>OS / AD User Account</th>
                            <th>PC Used</th>
                            <th>Active Work Time</th>
                            <th>Primary Application</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($user_matrix)) : ?>
                            <tr>
                                <td colspan="4" style="color:#888;">
                                    <?php if (!empty($selected_machine)) : ?>
                                        No activity data found for <strong><?php echo esc_html($selected_machine); ?></strong>. The machine may not have synced yet or has no logs in the current retention window.
                                    <?php else : ?>
                                        No activity data found. Ensure at least one client machine has synced.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($user_matrix as $row) : ?>
                                <tr>
                                    <td><strong><code><?php echo esc_html($row->os_user); ?></code></strong></td>
                                    <td><code><?php echo esc_html($row->machine_identifier); ?></code></td>
                                    <td><strong><?php echo $row->active_hrs; ?> hrs</strong></td>
                                    <td><code><?php echo esc_html($row->application_name ?: 'Various Apps'); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($active_tab === 'devices' && (current_user_can('manage_options') || current_user_can('manage_aw_tracker'))) : 
            $secret_token     = get_option('aw_tracker_api_secret');
            $api_endpoint     = rest_url('activitywatch/v1/sync');
            $raw_setting      = get_option('aw_raw_retention_setting', '6_months');
            $summary_setting  = get_option('aw_summary_retention_setting', '36_months');
            $idle_threshold   = (int) get_option('aw_idle_threshold_seconds', 300);
            $last_prune_time  = get_option('aw_tracker_last_prune_time', 'Never');
            $last_deleted_raw = get_option('aw_tracker_last_prune_deleted_raw', 0);
        ?>
            <!-- 1. Retention Policy Configuration Card -->
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <h3 style="display:flex; align-items:center; gap:8px;">
                    <span class="dashicons dashicons-database-remove"></span> Database Retention & Automated Maintenance
                </h3>
                <p>Configure automated rollup to the compact summary table and automatic purging of raw second-by-second records.</p>
                
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="background:#f9f9f9; padding:15px; border-radius:6px; border:1px solid #e5e5e5;">
                    <?php wp_nonce_field('aw_retention_settings_nonce'); ?>
                    <input type="hidden" name="action" value="aw_save_retention_settings">
                    
                    <div style="display:flex; gap:25px; flex-wrap:wrap; align-items:center;">
                        <div>
                            <label><strong>Raw Activity Logs Retention (Table: <code>wp_aw_activity_logs</code>):</strong></label><br>
                            <select name="raw_retention_setting" style="margin-top:5px;">
                                <option value="2_hours" <?php selected($raw_setting, '2_hours'); ?>>Test Mode (purge raw logs before today)</option>
                                <option value="3_months" <?php selected($raw_setting, '3_months'); ?>>3 Months</option>
                                <option value="6_months" <?php selected($raw_setting, '6_months'); ?>>6 Months (Recommended)</option>
                                <option value="12_months" <?php selected($raw_setting, '12_months'); ?>>12 Months (1 Year)</option>
                                <option value="indefinite" <?php selected($raw_setting, 'indefinite'); ?>>Indefinite (No Auto-Purge)</option>
                            </select>
                        </div>

                        <div>
                            <label><strong>Daily Summaries Retention (Table: <code>wp_aw_daily_summary</code>):</strong></label><br>
                            <select name="summary_retention_setting" style="margin-top:5px;">
                                <option value="12_months" <?php selected($summary_setting, '12_months'); ?>>12 Months (1 Year)</option>
                                <option value="36_months" <?php selected($summary_setting, '36_months'); ?>>36 Months (3 Years - Recommended)</option>
                                <option value="60_months" <?php selected($summary_setting, '60_months'); ?>>60 Months (5 Years)</option>
                                <option value="indefinite" <?php selected($summary_setting, 'indefinite'); ?>>Indefinite (Keep Forever)</option>
                            </select>
                        </div>

                        <div style="margin-top:18px;">
                            <button type="submit" class="button button-primary">Save Retention Policy</button>
                        </div>
                    </div>

                    <hr style="margin:16px 0; border:none; border-top:1px solid #e5e5e5;">

                    <div style="display:flex; gap:25px; flex-wrap:wrap; align-items:flex-end;">
                        <div>
                            <label><strong>Idle Detection Threshold:</strong></label><br>
                            <small style="color:#666;">How long a user must be inactive before it is classified as <em>Idle</em>. Applied by the bridge agent on each client machine.</small><br>
                            <select name="idle_threshold_seconds" style="margin-top:6px;">
                                <option value="60"  <?php selected($idle_threshold, 60);  ?>>1 Minute</option>
                                <option value="120" <?php selected($idle_threshold, 120); ?>>2 Minutes</option>
                                <option value="300" <?php selected($idle_threshold, 300); ?>>5 Minutes (Recommended)</option>
                                <option value="600" <?php selected($idle_threshold, 600); ?>>10 Minutes</option>
                                <option value="900" <?php selected($idle_threshold, 900); ?>>15 Minutes</option>
                            </select>
                            <p class="description" style="margin-top:4px;">
                                Current value: <code><?php echo $idle_threshold; ?> seconds</code> — fetched automatically by agents on next sync via <code>/activitywatch/v1/config</code>.
                            </p>
                        </div>
                        <div style="margin-top:18px;">
                            <button type="submit" class="button button-primary">Save All Settings</button>
                        </div>
                    </div>
                </form>

                <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center; background:#f0f0f1; padding:12px; border-radius:6px;">
                    <div>
                        <strong>Last Maintenance Run:</strong> <code><?php echo esc_html($last_prune_time); ?></code>
                        <?php if ($last_prune_time !== 'Never') : ?>
                            &nbsp;|&nbsp; <strong>Last Purged Raw Rows:</strong> <code><?php echo number_format($last_deleted_raw); ?></code>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=activitywatch-tracker&tab=diagnostics')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-search" style="margin-top:3px;"></span> View Diagnostics &amp; Run Rollup
                    </a>
                </div>
            </div>

            <!-- 2. Desktop Client Setup Configuration Helper -->
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <h3>Desktop Client Setup Configuration</h3>
                <p>Use these credentials in your client agents (<code>aw_bridge_agent.py</code>) across LAN machines:</p>
                
                <table class="form-table">
                    <tr>
                        <th style="width:220px;">REST API Endpoint:</th>
                        <td><code><?php echo esc_html($api_endpoint); ?></code></td>
                    </tr>
                    <tr>
                        <th>API Bearer Secret Token:</th>
                        <td><code><?php echo esc_html($secret_token); ?></code>
                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline; margin-left:10px;" onsubmit="return confirm('Every client must be redeployed with the new token. Continue?');">
                                <input type="hidden" name="action" value="aw_regenerate_api_token">
                                <?php wp_nonce_field('aw_regenerate_api_token_nonce'); ?>
                                <button type="submit" class="button button-small">Regenerate token</button>
                            </form>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 3. Bulk CSV Onboarding -->
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <h3>Bulk Device Onboarding via CSV</h3>
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" style="background:#f0f0f1; padding:15px; border-radius:6px;">
                    <input type="hidden" name="action" value="aw_bulk_import_devices">
                    <?php wp_nonce_field('aw_bulk_import_devices_nonce'); ?>
                    <label><strong>Select Devices CSV File:</strong></label>
                    <input type="file" name="device_csv" accept=".csv" required>
                    <button type="submit" class="button button-primary">Upload & Bulk Update Locations</button>
                    <p class="description" style="margin-top:8px;">
                        Expected CSV Header: <code>Hostname, Ashram, Building, Room, Department</code><br>
                        Example Row: <code>DESKTOP-JKRECSV, Main Ashram, IT Building, Room 102, Publishing</code>
                    </p>
                </form>
            </div>

            <!-- 4. Device Registry with Last Heartbeat Sync -->
            <div style="margin-top:20px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
                <h3>Registered Network Devices & Locations</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Machine Hostname</th>
                            <th>Ashram</th>
                            <th>Building</th>
                            <th>Room</th>
                            <th>Department</th>
                            <th>Last Heartbeat Sync</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($devices)) : ?>
                            <tr><td colspan="7">No devices registered yet. Run the client script on a computer to register it automatically.</td></tr>
                        <?php else :
                            foreach ($devices as $dev) : ?>
                                <tr>
                                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                                        <input type="hidden" name="action" value="aw_update_single_device">
                                        <?php wp_nonce_field('aw_update_single_device_nonce'); ?>
                                        <input type="hidden" name="machine_identifier" value="<?php echo esc_attr($dev->machine_identifier); ?>">
                                        <td><strong><code><?php echo esc_html($dev->machine_identifier); ?></code></strong></td>
                                        <td><input type="text" name="ashram" value="<?php echo esc_attr($dev->ashram); ?>" style="width:100%;"></td>
                                        <td><input type="text" name="building" value="<?php echo esc_attr($dev->building); ?>" style="width:100%;"></td>
                                        <td><input type="text" name="room" value="<?php echo esc_attr($dev->room); ?>" style="width:100%;"></td>
                                        <td><input type="text" name="department" value="<?php echo esc_attr($dev->department); ?>" style="width:100%;"></td>
                                        <td>
                                            <?php if ($dev->last_seen) : ?>
                                                <span style="color:#2271b1; font-weight:600;"><?php echo esc_html($dev->last_seen); ?></span>
                                            <?php else : ?>
                                                <span style="color:#999;">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><button type="submit" class="button button-small button-secondary">Save Transfer</button></td>
                                    </form>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($active_tab === 'diagnostics' && (current_user_can('manage_options') || current_user_can('manage_aw_tracker'))) :

            global $wpdb;
            $table_logs    = $wpdb->prefix . AW_TRACKER_TABLE_LOGS;
            $table_summary = $wpdb->prefix . AW_TRACKER_TABLE_SUMMARY;
            $table_devices = $wpdb->prefix . AW_TRACKER_TABLE_DEVICES;

            // --- Table Row Counts ---
            $raw_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_logs}");
            $summary_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_summary}");
            $device_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_devices}");

            // --- Date range of raw logs ---
            $raw_oldest = $wpdb->get_var("SELECT MIN(logged_at) FROM {$table_logs}");
            $raw_newest = $wpdb->get_var("SELECT MAX(logged_at) FROM {$table_logs}");

            // --- Date range of summary ---
            $sum_oldest = $wpdb->get_var("SELECT MIN(log_date) FROM {$table_summary}");
            $sum_newest = $wpdb->get_var("SELECT MAX(log_date) FROM {$table_summary}");

            // --- Cron status (Task #4) ---
            $cron_next_ts   = wp_next_scheduled('aw_tracker_daily_maintenance_event');
            $cron_next_str  = $cron_next_ts ? wp_date('Y-m-d H:i:s', $cron_next_ts) : false;
            $cron_healthy   = (bool) $cron_next_ts;

            // --- Last maintenance run data ---
            $last_prune_time     = get_option('aw_tracker_last_prune_time', null);
            $last_deleted_raw    = get_option('aw_tracker_last_prune_deleted_raw', null);
            $last_deleted_sum    = get_option('aw_tracker_last_prune_deleted_summary', null);
            $last_rollup_since   = get_option('aw_tracker_last_rollup_since', null);

            // --- Retention settings ---
            $raw_setting     = get_option('aw_raw_retention_setting', '6_months');
            $summary_setting = get_option('aw_summary_retention_setting', '36_months');

            // --- Index health: check key indexes exist ---
            $indexes_raw = $wpdb->get_results("SHOW INDEX FROM {$table_logs}");
            $index_names_raw = array_column($indexes_raw, 'Key_name');
            $indexes_sum = $wpdb->get_results("SHOW INDEX FROM {$table_summary}");
            $index_names_sum = array_column($indexes_sum, 'Key_name');

            $check = function($arr, $key) {
                return in_array($key, $arr)
                    ? '<span style="color:#46b450; font-weight:bold;">✔ Present</span>'
                    : '<span style="color:#dc3232; font-weight:bold;">✘ Missing</span>';
            };

            // --- Sample rollup verification: check if yesterday's data is in summary ---
            $yesterday      = wp_date('Y-m-d', strtotime('-1 day'));
            $yesterday_raw  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_logs} WHERE DATE(logged_at) = %s", $yesterday));
            $yesterday_sum  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_summary} WHERE log_date = %s", $yesterday));
            $rollup_ok      = ($yesterday_raw === 0 || $yesterday_sum > 0);
        ?>

            <div style="margin-top:20px;">

                <!-- ① Cron Health Card -->
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px; margin-bottom:20px;">
                    <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-clock"></span> Scheduled Cron Health
                    </h3>
                    <table class="widefat" style="width:auto; min-width:500px;">
                        <tbody>
                            <tr>
                                <td style="width:250px;"><strong>Cron Event Hook</strong></td>
                                <td><code>aw_tracker_daily_maintenance_event</code></td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td>
                                    <?php if ($cron_healthy) : ?>
                                        <span style="color:#46b450; font-weight:bold;">✔ Scheduled</span>
                                    <?php else : ?>
                                        <span style="color:#dc3232; font-weight:bold;">✘ NOT SCHEDULED — Deactivate &amp; reactivate the plugin to re-register it.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Next Run (WordPress Time)</strong></td>
                                <td>
                                    <?php if ($cron_next_str) : ?>
                                        <code><?php echo esc_html($cron_next_str); ?></code>
                                        <span style="color:#888; font-size:12px;">(in <?php echo human_time_diff(current_time('timestamp'), $cron_next_ts); ?>)</span>
                                    <?php else : ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Maintenance Run</strong></td>
                                <td>
                                    <?php echo $last_prune_time
                                        ? '<code>' . esc_html($last_prune_time) . '</code>'
                                        : '<span style="color:#999;">Never run yet</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Rollup Window (since)</strong></td>
                                <td>
                                    <?php echo $last_rollup_since
                                        ? '<code>' . esc_html($last_rollup_since) . '</code>'
                                        : '<span style="color:#999;">—</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Raw Rows Pruned</strong></td>
                                <td>
                                    <?php echo $last_deleted_raw !== null
                                        ? '<code>' . number_format((int)$last_deleted_raw) . ' rows</code>'
                                        : '<span style="color:#999;">—</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Summary Rows Pruned</strong></td>
                                <td>
                                    <?php echo $last_deleted_sum !== null
                                        ? '<code>' . number_format((int)$last_deleted_sum) . ' rows</code>'
                                        : '<span style="color:#999;">—</span>'; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Re-register cron button if missing -->
                    <?php if (!$cron_healthy) : ?>
                    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:12px;">
                        <?php wp_nonce_field('aw_reregister_cron_nonce'); ?>
                        <input type="hidden" name="action" value="aw_reregister_cron">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Re-Register Cron Event Now
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- ② Table Health & Row Counts -->
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px; margin-bottom:20px;">
                    <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-database"></span> Database Table Health
                    </h3>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Row Count</th>
                                <th>Oldest Record</th>
                                <th>Newest Record</th>
                                <th>Retention Policy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code><?php echo esc_html($table_logs); ?></code><br><small>Raw activity logs</small></td>
                                <td><strong><?php echo number_format($raw_count); ?></strong></td>
                                <td><?php echo $raw_oldest ? '<code>' . esc_html($raw_oldest) . '</code>' : '—'; ?></td>
                                <td><?php echo $raw_newest ? '<code>' . esc_html($raw_newest) . '</code>' : '—'; ?></td>
                                <td><code><?php echo esc_html(str_replace('_', ' ', $raw_setting)); ?></code></td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_summary); ?></code><br><small>Daily aggregated summaries</small></td>
                                <td><strong><?php echo number_format($summary_count); ?></strong></td>
                                <td><?php echo $sum_oldest ? '<code>' . esc_html($sum_oldest) . '</code>' : '—'; ?></td>
                                <td><?php echo $sum_newest ? '<code>' . esc_html($sum_newest) . '</code>' : '—'; ?></td>
                                <td><code><?php echo esc_html(str_replace('_', ' ', $summary_setting)); ?></code></td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_devices); ?></code><br><small>Device registry</small></td>
                                <td><strong><?php echo number_format($device_count); ?></strong></td>
                                <td colspan="2">—</td>
                                <td>Permanent</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ③ Index Verification -->
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px; margin-bottom:20px;">
                    <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-performance"></span> Query Index Verification
                    </h3>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr><th>Table</th><th>Index Name</th><th>Status</th><th>Purpose</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code><?php echo esc_html($table_logs); ?></code></td>
                                <td><code>idx_machine_logged</code></td>
                                <td><?php echo $check($index_names_raw, 'idx_machine_logged'); ?></td>
                                <td>Machine + date range filters (main dashboard query)</td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_logs); ?></code></td>
                                <td><code>idx_user</code></td>
                                <td><?php echo $check($index_names_raw, 'idx_user'); ?></td>
                                <td>Floating user matrix queries</td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_logs); ?></code></td>
                                <td><code>idx_app</code></td>
                                <td><?php echo $check($index_names_raw, 'idx_app'); ?></td>
                                <td>Low app usage report filtering</td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_summary); ?></code></td>
                                <td><code>idx_date_machine</code></td>
                                <td><?php echo $check($index_names_sum, 'idx_date_machine'); ?></td>
                                <td>Historical trend queries by machine</td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_summary); ?></code></td>
                                <td><code>idx_date_user</code></td>
                                <td><?php echo $check($index_names_sum, 'idx_date_user'); ?></td>
                                <td>Historical trend queries by user</td>
                            </tr>
                            <tr>
                                <td><code><?php echo esc_html($table_summary); ?></code></td>
                                <td><code>uq_daily_metric</code></td>
                                <td><?php echo $check($index_names_sum, 'uq_daily_metric'); ?></td>
                                <td>Prevents duplicate rollup rows (ON DUPLICATE KEY UPDATE)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ④ Rollup Verification -->
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px; margin-bottom:20px;">
                    <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-update-alt"></span> Rollup Continuity Check (Yesterday: <code><?php echo esc_html($yesterday); ?></code>)
                    </h3>
                    <table class="widefat" style="width:auto; min-width:500px;">
                        <tbody>
                            <tr>
                                <td style="width:320px;"><strong>Raw log rows for yesterday</strong></td>
                                <td><code><?php echo number_format($yesterday_raw); ?> rows</code></td>
                            </tr>
                            <tr>
                                <td><strong>Summary rows for yesterday</strong></td>
                                <td><code><?php echo number_format($yesterday_sum); ?> rows</code></td>
                            </tr>
                            <tr>
                                <td><strong>Rollup status</strong></td>
                                <td>
                                    <?php if ($yesterday_raw === 0 && $yesterday_sum === 0) : ?>
                                        <span style="color:#888;">⚠ No data for yesterday yet — no devices have synced.</span>
                                    <?php elseif ($rollup_ok) : ?>
                                        <span style="color:#46b450; font-weight:bold;">✔ Summary rows exist for yesterday's raw data.</span>
                                    <?php else : ?>
                                        <span style="color:#dc3232; font-weight:bold;">✘ Raw logs exist for yesterday but no summary rows found. Run rollup manually.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="margin-top:15px; display:flex; gap:10px;">
                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                            <?php wp_nonce_field('aw_manual_maintenance_nonce'); ?>
                            <input type="hidden" name="action" value="aw_trigger_manual_maintenance">
                            <button type="submit" class="button button-primary" onclick="return confirm('Run daily aggregation rollup and purge expired logs based on the active policy now?');">
                                <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Run Rollup &amp; Purge Now
                            </button>
                        </form>
                        <a href="<?php echo esc_url(add_query_arg('tab', 'diagnostics', admin_url('admin.php?page=activitywatch-tracker'))); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-image-rotate" style="margin-top:3px;"></span> Refresh Diagnostics
                        </a>
                    </div>
                </div>

            </div><!-- end diagnostics wrap -->

        <?php endif; ?>
    </div>
    <?php
}

// ==================== CRON RE-REGISTER HANDLER ====================
add_action('admin_post_aw_reregister_cron', 'aw_tracker_handle_reregister_cron');

function aw_tracker_handle_reregister_cron() {
    if (!current_user_can('manage_options') && !current_user_can('manage_aw_tracker')) wp_die('Unauthorized');
    check_admin_referer('aw_reregister_cron_nonce');

    // Clear any stale event first, then re-schedule for next 02:00 AM
    wp_clear_scheduled_hook('aw_tracker_daily_maintenance_event');
    $two_am = strtotime('tomorrow 02:00:00');
    wp_schedule_event($two_am, 'daily', 'aw_tracker_daily_maintenance_event');

    wp_redirect(admin_url('admin.php?page=activitywatch-tracker&tab=diagnostics&cron_registered=1'));
    exit;
}
