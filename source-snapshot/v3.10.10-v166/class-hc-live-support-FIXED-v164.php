<?php
/**
 * HelpCenter Live Support: persistent threaded tickets, exclusive agent ownership,
 * visitor/admin message APIs and near-real-time incremental synchronization.
 */
if (!defined('ABSPATH')) exit;

class HC_Live_Support {
    const DB_VERSION = '17';
    const POLL_ACTIONS = array('queued', 'pending_email', 'active', 'waiting_agent', 'waiting_customer');

    public static function register() {
        self::install();
        // v83: explicitly enable the two support capabilities requested for this release.
        // The one-time marker prevents later administrator changes from being overwritten.
        if (!get_option('hc_v83_support_features_migrated')) {
            HC_Core::update_setting('hc_ai_support_ai_overview_enabled', '1');
            HC_Core::update_setting('hc_ai_support_media_enabled', '1');
            HC_Core::update_setting('hc_ai_support_max_files_per_message', '3');
            update_option('hc_v83_support_features_migrated', 1, false);
        }
        // v121: restore visitor/agent file sharing once. The setting remains
        // administrator-controlled after this migration and is never forced on again.
        if (!get_option('hc_v121_media_sharing_enabled')) {
            HC_Core::update_setting('hc_ai_support_media_enabled', '1');
            update_option('hc_v121_media_sharing_enabled', 1, false);
        }
        if (!wp_next_scheduled('hc_ai_support_stage_cleanup')) wp_schedule_event(time()+HOUR_IN_SECONDS, 'hourly', 'hc_ai_support_stage_cleanup');
        add_action('init', array(__CLASS__, 'grant_capabilities'), 20);
        add_action('wp_ajax_hc_ai_escalate', array(__CLASS__, 'create_ticket'));
        add_action('wp_ajax_nopriv_hc_ai_escalate', array(__CLASS__, 'create_ticket'));
        add_action('wp_ajax_hc_ai_escalation_check_followup', array(__CLASS__, 'check_followup'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_check_followup', array(__CLASS__, 'check_followup'));
        add_action('wp_ajax_hc_ai_escalation_status', array(__CLASS__, 'visitor_status'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_status', array(__CLASS__, 'visitor_status'));
        add_action('wp_ajax_hc_ai_escalation_history', array(__CLASS__, 'visitor_history'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_history', array(__CLASS__, 'visitor_history'));
        add_action('wp_ajax_hc_ai_escalation_send', array(__CLASS__, 'visitor_send'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_send', array(__CLASS__, 'visitor_send'));
        add_action('wp_ajax_hc_ai_escalation_realtime_publish', array(__CLASS__, 'visitor_realtime_publish'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_realtime_publish', array(__CLASS__, 'visitor_realtime_publish'));
        add_action('wp_ajax_hc_ai_escalation_typing', array(__CLASS__, 'visitor_typing'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_typing', array(__CLASS__, 'visitor_typing'));
        add_action('wp_ajax_hc_ai_escalation_admin_typing', array(__CLASS__, 'admin_typing'));
        add_action('wp_ajax_hc_ai_support_presence', array(__CLASS__, 'support_presence'));
        add_action('wp_ajax_hc_ai_escalation_upload', array(__CLASS__, 'upload_attachment'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_upload', array(__CLASS__, 'upload_attachment'));
        add_action('wp_ajax_hc_ai_escalation_stage_upload', array(__CLASS__, 'stage_attachment'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_stage_upload', array(__CLASS__, 'stage_attachment'));
        add_action('wp_ajax_hc_ai_escalation_cancel_staged', array(__CLASS__, 'cancel_staged_attachment'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_cancel_staged', array(__CLASS__, 'cancel_staged_attachment'));
        add_action('wp_ajax_hc_ai_escalation_send_staged', array(__CLASS__, 'send_staged_message'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_send_staged', array(__CLASS__, 'send_staged_message'));
        add_action('hc_ai_support_stage_cleanup', array(__CLASS__, 'cleanup_staged_attachments'));
        add_action('wp_ajax_hc_ai_escalation_download', array(__CLASS__, 'download_attachment'));
        add_action('wp_ajax_nopriv_hc_ai_escalation_download', array(__CLASS__, 'download_attachment')); 
        add_action('wp_ajax_hc_ai_conversations_live', array(__CLASS__, 'admin_conversations_live'));
        add_action('wp_ajax_hc_ai_conversation_messages_live', array(__CLASS__, 'admin_conversation_messages_live')); 
        add_action('wp_ajax_hc_ai_escalation_admin_poll', array(__CLASS__, 'admin_poll'));
        add_action('wp_ajax_hc_ai_escalation_ticket', array(__CLASS__, 'admin_ticket'));
        add_action('wp_ajax_hc_ai_escalation_accept', array(__CLASS__, 'claim_ticket'));
        add_action('wp_ajax_hc_ai_escalation_transfer', array(__CLASS__, 'transfer_ticket'));
        add_action('wp_ajax_hc_ai_escalation_admin_send', array(__CLASS__, 'admin_send'));
        add_action('wp_ajax_hc_ai_escalation_reply', array(__CLASS__, 'admin_send')); // Backward-compatible action name.
        add_action('wp_ajax_hc_ai_escalation_close', array(__CLASS__, 'close_ticket'));
        add_action('wp_ajax_hc_ai_escalation_archive', array(__CLASS__, 'archive_ticket'));
        add_action('wp_ajax_hc_ai_escalation_restore', array(__CLASS__, 'restore_ticket'));
        add_action('wp_ajax_hc_ai_support_force_logout', array(__CLASS__, 'force_logout_agent'));
        add_action('wp_ajax_hc_ai_escalation_summary', array(__CLASS__, 'generate_ticket_summary'));
        add_action('wp_ajax_hc_ai_escalation_admin_send_note', array(__CLASS__, 'admin_send_note'));
        add_action('wp_ajax_hc_ai_escalation_priority', array(__CLASS__, 'set_ticket_priority'));
        add_action('hc_ai_escalation_timeout', array(__CLASS__, 'timeout_ticket'));
        add_action('hc_ai_escalation_timeout_watchdog', array(__CLASS__, 'timeout_watchdog'));
        add_filter('cron_schedules', array(__CLASS__, 'timeout_cron_schedules'));
        if (wp_get_schedule('hc_ai_escalation_timeout_watchdog') !== 'hc_every_15_seconds_v2') {
            wp_clear_scheduled_hook('hc_ai_escalation_timeout_watchdog');
            wp_schedule_event(time()+15, 'hc_every_15_seconds_v2', 'hc_ai_escalation_timeout_watchdog');
        }
        add_action('hc_ai_classify_ticket_priority', array(__CLASS__, 'run_ai_priority_classifier'));
        add_action('hc_ai_support_retry_message_email', array(__CLASS__, 'retry_message_email'));
        add_action('hc_ai_attachment_cleanup_daily', array(__CLASS__, 'cleanup_attachments'));
        add_filter('cron_schedules', array(__CLASS__, 'attachment_cron_schedules'));
        $cleanup_enabled = HC_Core::get_setting('hc_ai_support_attachment_auto_cleanup', '1') === '1';
        $desired_schedule = $cleanup_enabled ? sanitize_key(HC_Core::get_setting('hc_ai_support_attachment_cleanup_frequency', 'daily')) : false;
        if (!in_array($desired_schedule, array('daily','hc_weekly','hc_monthly'), true)) $desired_schedule = 'daily';
        $current_schedule = wp_get_schedule('hc_ai_attachment_cleanup_daily');
        if ($current_schedule !== $desired_schedule) { wp_clear_scheduled_hook('hc_ai_attachment_cleanup_daily'); if ($desired_schedule) wp_schedule_event(time() + HOUR_IN_SECONDS, $desired_schedule, 'hc_ai_attachment_cleanup_daily'); }
        add_action('wp_ajax_hc_ai_attachment_cleanup_now', array(__CLASS__, 'manual_cleanup_attachments'));
    }

    public static function timeout_cron_schedules($schedules) {
        $schedules['hc_every_15_seconds_v2'] = array('interval' => 15, 'display' => 'Every 15 seconds (HelpCenter timeout watchdog)');
        return $schedules;
    }

    /** Process overdue handoffs even if an individual single event was missed. */
    public static function timeout_watchdog() {
        global $wpdb;
        $table = self::table();
        $cutoff = wp_date('Y-m-d H:i:s', time() - (5 * MINUTE_IN_SECONDS));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('queued','active','waiting_agent','waiting_customer','pending_email') AND assigned_agent_id=0 AND accepted_at IS NULL AND agent_connected_at IS NULL AND COALESCE(queue_started_at,created_at) <= %s ORDER BY id ASC LIMIT 50",
            $cutoff
        ));
        foreach ((array)$ids as $id) {
            $id=(int)$id;
            $guard='hc_timeout_watchdog_'.$id;
            if (get_transient($guard)) continue;
            set_transient($guard,1,12);
            self::timeout_ticket($id);
        }
    }

    public static function attachment_cron_schedules($schedules) {
        $schedules['hc_weekly'] = array('interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once weekly');
        $schedules['hc_monthly'] = array('interval' => 30 * DAY_IN_SECONDS, 'display' => 'Once every 30 days');
        return $schedules;
    }

    public static function grant_capabilities() {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('hc_manage_ai_escalations')) $role->add_cap('hc_manage_ai_escalations');
        $selected = self::eligible_agent_ids();
        foreach ($selected as $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user && !$user->has_cap('hc_manage_ai_escalations')) $user->add_cap('hc_manage_ai_escalations');
        }
        $previous = get_option('hc_ai_support_agent_cap_ids', array());
        if (!is_array($previous)) $previous = array();
        foreach (array_diff(array_map('absint', $previous), $selected) as $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user && !$user->has_cap('manage_options')) $user->remove_cap('hc_manage_ai_escalations');
        }
        update_option('hc_ai_support_agent_cap_ids', $selected, false);
    }

    private static function eligible_agent_ids() {
        $raw = HC_Core::get_setting('hc_ai_support_agent_ids', '');
        $ids = array_values(array_unique(array_filter(array_map('absint', preg_split('/[,;\s]+/', (string) $raw)))));
        // Agent-role accounts are always eligible. This avoids a stale/missing saved
        // ID list silently preventing newly-created agents from receiving tickets.
        $role_users = get_users(array('role' => 'hc_support_agent', 'fields' => 'ID', 'number' => 500));
        $ids = array_values(array_unique(array_merge($ids, array_map('absint', (array) $role_users))));
        if (!$ids) {
            $users = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 200));
            $ids = array_map('absint', (array) $users);
        }
        return $ids;
    }

    private static function agent_capacity($agent_id) {
        $map = json_decode((string) HC_Core::get_setting('hc_ai_support_agent_capacity_json', '{}'), true);
        if (!is_array($map)) $map = array();
        return max(1, min(100, absint($map[(string)(int)$agent_id] ?? 1)));
    }


    private static function agent_in_cooldown($agent_id) {
        global $wpdb;
        if (!$agent_id) return false;
        $until = $wpdb->get_var($wpdb->prepare("SELECT cooldown_until FROM " . self::presence_table() . " WHERE user_id=%d", (int)$agent_id));
        return !empty($until) && self::mysql_timestamp($until) > time();
    }

    private static function agent_active_count($agent_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE assigned_agent_id=%d AND status IN ('active','waiting_agent','waiting_customer','pending_email')", (int)$agent_id));
    }

    private static function presence_table() {
        global $wpdb;
        return $wpdb->prefix . 'hc_ai_support_presence';
    }

    private static function agent_is_online($user_id) {
        if (!$user_id) return false;
        global $wpdb;
        $cutoff = wp_date('Y-m-d H:i:s', time() - 35);
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::presence_table() . " WHERE user_id=%d AND is_available=1 AND last_seen >= %s", (int) $user_id, $cutoff));
    }

    private static function online_agent_ids() {
        global $wpdb;
        $ids = self::eligible_agent_ids();
        if (!$ids) return array();
        $cutoff = wp_date('Y-m-d H:i:s', time() - 35);
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare("SELECT user_id FROM " . self::presence_table() . " WHERE is_available=1 AND last_seen >= %s AND user_id IN ({$marks}) ORDER BY last_seen ASC", array_merge(array($cutoff), $ids));
        return array_map('absint', (array) $wpdb->get_col($sql));
    }

    private static function assign_available_agent($ticket_id) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", (int) $ticket_id));
        if (!$ticket || $ticket->status === 'closed' || (int) $ticket->assigned_agent_id) return $ticket;
        $online = self::online_agent_ids();
        if (!$online) return $ticket;
        $candidates = array();
        foreach ($online as $agent_id) {
            $load = self::agent_active_count($agent_id);
            $capacity = self::agent_capacity($agent_id);
            if ($load >= $capacity) continue;
            $presence_row = $wpdb->get_row($wpdb->prepare("SELECT last_seen FROM " . self::presence_table() . " WHERE user_id=%d", $agent_id));
            if (self::agent_in_cooldown($agent_id)) continue;
            $presence = $presence_row ? $presence_row->last_seen : '';
            $candidates[] = array('id'=>$agent_id, 'load'=>$load, 'capacity'=>$capacity, 'ratio'=>$load / $capacity, 'seen'=>(string)$presence);
        }
        usort($candidates, function($a,$b){ return ($a['load'] <=> $b['load']) ?: (($a['ratio'] <=> $b['ratio']) ?: strcmp($b['seen'],$a['seen'])); });
        foreach ($candidates as $candidate) {
            $user = get_userdata($candidate['id']);
            if (!$user) continue;
            $now = current_time('mysql');
            $changed = $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET assigned_agent_id=%d,assigned_agent_name=%s,status=IF(status='pending_email','pending_email','active'),accepted_at=%s,last_activity=%s,last_agent_seen_at=NULL WHERE id=%d AND assigned_agent_id=0 AND status IN ('queued','pending_email')", (int)$user->ID, $user->display_name ?: $user->user_login, $now, $now, (int)$ticket_id));
            if ($changed) {
                wp_clear_scheduled_hook('hc_ai_escalation_timeout', array((int)$ticket_id));
                self::insert_message((int)$ticket_id, (int)$ticket->conversation_id, 'system', 0, 'System', ($user->display_name ?: $user->user_login) . ($ticket->status === 'pending_email' ? ' has been assigned to your legacy email follow-up ticket.' : ' has been assigned to your conversation and will join shortly.'));
                if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$ticket_id, 'ticket.assigned', 0);
                if (class_exists('HC_Web_Push')) HC_Web_Push::send('agent', (int)$user->ID, 'New support ticket assigned', 'A new customer conversation has been assigned to you.', home_url('/support-agent/?tab=assigned&ticket='.(int)$ticket_id), 'hc-agent-new-ticket-' . (int)$ticket_id . '-assign', (int)$ticket_id);
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", (int)$ticket_id));
            }
        }
        return $ticket;
    }

    public static function dispatch_queue() { self::auto_assign_waiting_tickets(); }

    private static function auto_assign_waiting_tickets() {
        global $wpdb;
        if (!self::online_agent_ids()) return;
        // Serialize dispatchers from multiple agent heartbeats so simultaneous requests
        // cannot exceed an agent's configured active-ticket capacity.
        $lock_key = 'hc_ai_support_assignment_lock';
        $lock_at = (int) get_option($lock_key, 0);
        if ($lock_at && (time() - $lock_at) < 30) return;
        if ($lock_at) delete_option($lock_key);
        if (!add_option($lock_key, time(), '', false)) return;
        $ids = $wpdb->get_col("SELECT id FROM " . self::table() . " WHERE status IN ('queued','pending_email') AND assigned_agent_id=0 ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END ASC,created_at ASC,id ASC LIMIT 40");
        foreach ((array)$ids as $id) {
            $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", (int)$id));
            if (!$ticket) continue;
            // Queue dispatch must not close/convert a live queue item. The timeout
            // handler is responsible for the no-agent timeout independently.
            if ($ticket && in_array($ticket->status, array('queued','pending_email'), true) && !(int)$ticket->assigned_agent_id) self::assign_available_agent((int)$id);
        }
        delete_option($lock_key);
    }

    private static function reassign_offline_owned_tickets() {
        // Offline is a presence state, not an ownership change. Existing tickets remain
        // assigned to their owner until the agent returns, transfers, or a supervisor
        // explicitly force-logs them out.
        return;
    }

    public static function support_presence() {
        self::admin_nonce();
        global $wpdb;
        $user_id = get_current_user_id();
        if (!in_array($user_id, self::eligible_agent_ids(), true) && !current_user_can('manage_options')) wp_send_json_error(array('message'=>'You are not enabled as a support agent.'),403);
        $requested_available = isset($_POST['available']) ? (absint($_POST['available']) ? 1 : 0) : 0;
        $manual_toggle = !empty($_POST['manual_toggle']);
        $now = current_time('mysql');
        $presence = $wpdb->get_row($wpdb->prepare("SELECT is_available,force_logged_out FROM " . self::presence_table() . " WHERE user_id=%d", $user_id));
        // A heartbeat proves the agent is still present; it must never overwrite
        // an availability choice made in another tab or the Agent Portal.
        $available = $presence ? (int) $presence->is_available : 0;
        if ($manual_toggle) {
            $available = $requested_available;
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::presence_table() . " (user_id,is_available,last_seen,force_logged_out) VALUES (%d,%d,%s,0) ON DUPLICATE KEY UPDATE is_available=VALUES(is_available),last_seen=VALUES(last_seen),force_logged_out=0", $user_id, $available, $now));
        } else {
            if ($presence && !empty($presence->force_logged_out)) $available = 0;
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::presence_table() . " (user_id,is_available,last_seen) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)", $user_id, $available, $now));
        }
        if ($available) self::auto_assign_waiting_tickets();
        $online = count(self::online_agent_ids());
        if ($manual_toggle && class_exists('HC_Realtime')) HC_Realtime::publish_agent_event($user_id, 'agent.presence', array('available'=>(bool)$available, 'online_agents'=>$online));
        wp_send_json_success(array('available'=>(bool)$available,'online_agents'=>$online,'server_time'=>$now));
    }

    public static function can_manage() {
        return current_user_can('manage_options') || current_user_can('hc_manage_ai_escalations');
    }

    public static function install() {
        global $wpdb;
        $ticket_table = $wpdb->prefix . 'hc_ai_escalations';
        $message_table = $wpdb->prefix . 'hc_ai_escalation_messages';
        if (get_option('hc_ai_live_support_db_version') === self::DB_VERSION) return;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        // Prepare legacy message IDs before dbDelta adds the unique ticket/client key.
        $existing_messages = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $message_table));
        if ($existing_messages === $message_table) {
            $wpdb->query("UPDATE {$message_table} SET client_message_id=NULL WHERE client_message_id=''");
            $wpdb->query("UPDATE {$message_table} m INNER JOIN (SELECT ticket_id,client_message_id,MIN(id) keep_id FROM {$message_table} WHERE client_message_id IS NOT NULL AND client_message_id<>'' GROUP BY ticket_id,client_message_id HAVING COUNT(*)>1) d ON d.ticket_id=m.ticket_id AND d.client_message_id=m.client_message_id AND m.id<>d.keep_id SET m.client_message_id=NULL");
        }
        dbDelta("CREATE TABLE {$ticket_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_hash char(64) NOT NULL DEFAULT '',
            visitor_hash char(64) NOT NULL DEFAULT '',
            conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            visitor_name varchar(120) NOT NULL,
            visitor_email varchar(190) NOT NULL,
            issue longtext NOT NULL,
            ai_title varchar(180) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'queued',
            assigned_agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            assigned_agent_name varchar(120) NOT NULL DEFAULT '',
            admin_reply longtext NULL,
            created_at datetime NOT NULL,
            queue_started_at datetime NULL,
            replied_at datetime NULL,
            accepted_at datetime NULL,
            agent_connected_at datetime NULL,
            closed_at datetime NULL,
            last_activity datetime NULL,
            last_visitor_seen_at datetime NULL,
            last_agent_seen_at datetime NULL,
            visitor_typing_at datetime NULL,
            agent_typing_at datetime NULL,
            last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            timed_out_at datetime NULL,
            archived_from_status varchar(24) NOT NULL DEFAULT '',
            archived_at datetime NULL,
            queued_extra_messages smallint(5) unsigned NOT NULL DEFAULT 0,
            ai_summary longtext NULL,
            ai_summary_at datetime NULL,
            priority varchar(16) NOT NULL DEFAULT 'normal',
            closed_by_agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY status_created (status,created_at),
            KEY user_lookup (user_id,last_activity),
            KEY session_lookup (session_hash,status),
            KEY visitor_hash_lookup (visitor_hash,status),
            KEY agent_lookup (assigned_agent_id,status),
            KEY conversation_lookup (conversation_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$message_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sender_type varchar(20) NOT NULL,
            sender_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sender_name varchar(120) NOT NULL DEFAULT '',
            content longtext NOT NULL,
            attachment_path varchar(255) DEFAULT NULL,
            attachment_name varchar(255) DEFAULT NULL,
            attachment_mime varchar(120) DEFAULT NULL,
            attachment_size bigint(20) unsigned NOT NULL DEFAULT 0,
            attachment_kind varchar(20) DEFAULT NULL,
            attachment_deleted_at datetime NULL,
            client_message_id varchar(64) NULL DEFAULT NULL,
            read_by_agent_at datetime NULL,
            read_by_visitor_at datetime NULL,
            email_status varchar(16) NOT NULL DEFAULT 'not_required',
            email_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            email_attempted_at datetime NULL,
            email_sent_at datetime NULL,
            email_error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_order (ticket_id,id),
            KEY conversation_order (conversation_id,id),
            KEY sender_unread (ticket_id,sender_type,read_by_agent_at,read_by_visitor_at),
            UNIQUE KEY ticket_client_message (ticket_id,client_message_id)
        ) {$charset};");

        $presence_table = $wpdb->prefix . 'hc_ai_support_presence';
        dbDelta("CREATE TABLE {$presence_table} (
            user_id bigint(20) unsigned NOT NULL,
            is_available tinyint(1) NOT NULL DEFAULT 1,
            last_seen datetime NOT NULL,
            cooldown_until datetime NULL,
            force_logged_out tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (user_id),
            KEY availability_seen (is_available,last_seen)
        ) {$charset};");
        // NULL is required for messages without a client-side deduplication key.
        $wpdb->query("UPDATE {$message_table} SET client_message_id=NULL WHERE client_message_id=''");
        $wpdb->query("UPDATE {$message_table} SET email_status='not_required' WHERE email_status='none' OR email_status=''");

        // Migrate the v60 one-reply model without deleting any existing ticket data.
        $wpdb->query("UPDATE {$ticket_table} SET status='queued' WHERE status='open'");
        $wpdb->query("UPDATE {$ticket_table} SET status='active' WHERE status IN ('accepted','replied')");
        $legacy = $wpdb->get_results("SELECT * FROM {$ticket_table} ORDER BY id ASC", ARRAY_A);
        foreach ((array) $legacy as $ticket) {
            $ticket_id = (int) $ticket['id'];
            $session_hash = (string) ($ticket['session_hash'] ?? '');
            $conversation_id = (int) ($ticket['conversation_id'] ?? 0);
            if (!$conversation_id && $session_hash) {
                $conversation_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}hc_ai_conversations WHERE session_hash=%s ORDER BY updated_at DESC LIMIT 1",
                    $session_hash
                ));
                if ($conversation_id) $wpdb->update($ticket_table, array('conversation_id' => $conversation_id), array('id' => $ticket_id), array('%d'), array('%d'));
            }
            $has_messages = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$message_table} WHERE ticket_id=%d", $ticket_id));
            if (!$has_messages && !empty($ticket['issue'])) {
                self::insert_message($ticket_id, $conversation_id, 'visitor', 0, (string) ($ticket['visitor_name'] ?? 'Visitor'), (string) $ticket['issue']);
            }
            if (!$has_messages && !empty($ticket['admin_reply'])) {
                self::insert_message($ticket_id, $conversation_id, 'agent', 0, 'Support team', (string) $ticket['admin_reply']);
            }
        }
        $wpdb->query("UPDATE {$ticket_table} SET closed_by_agent_id=assigned_agent_id WHERE status='closed' AND closed_by_agent_id=0 AND assigned_agent_id>0");
        // Backfill stable browser identity for existing tickets from their original AI conversation.
        $ai_conversation_table = $wpdb->prefix . 'hc_ai_conversations';
        $ai_conversation_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ai_conversation_table));
        if ($ai_conversation_exists === $ai_conversation_table) {
            $wpdb->query("UPDATE {$ticket_table} t INNER JOIN {$ai_conversation_table} c ON c.id=t.conversation_id SET t.visitor_hash=c.visitor_hash WHERE (t.visitor_hash='' OR t.visitor_hash IS NULL) AND c.visitor_hash<>''");
        }
        // Backfill first-assignment evidence for older tickets. Once assigned, a conversation must never auto-convert due to an agent taking time to reply.
        $wpdb->query("UPDATE {$ticket_table} SET accepted_at=COALESCE(last_agent_seen_at,created_at) WHERE assigned_agent_id>0 AND accepted_at IS NULL");
        // Preserve evidence of an actual agent reply in older conversations. Assignment/claim alone is not a connection.
        $wpdb->query("UPDATE {$ticket_table} t SET agent_connected_at=(SELECT MIN(m.created_at) FROM {$message_table} m WHERE m.ticket_id=t.id AND m.sender_type='agent' AND m.sender_user_id>0) WHERE t.agent_connected_at IS NULL AND EXISTS (SELECT 1 FROM {$message_table} m2 WHERE m2.ticket_id=t.id AND m2.sender_type='agent' AND m2.sender_user_id>0)");
        // Older versions updated last_agent_seen_at when an agent actually opened the chat; a claim alone writes the same timestamp as accepted_at.
        $wpdb->query("UPDATE {$ticket_table} SET agent_connected_at=last_agent_seen_at WHERE agent_connected_at IS NULL AND last_agent_seen_at IS NOT NULL AND accepted_at IS NOT NULL AND last_agent_seen_at>accepted_at");
        // Older versions counted only extra replies. Include the initial concern in the new three-message total.
        $wpdb->query("UPDATE {$ticket_table} SET queued_extra_messages=LEAST(3,queued_extra_messages+1) WHERE status IN ('queued','active','waiting_agent','waiting_customer','pending_email') AND assigned_agent_id=0 AND accepted_at IS NULL AND agent_connected_at IS NULL");
        // Re-time existing, not-yet-connected conversations to the new fixed five-minute rule.
        $waiting_rows=$wpdb->get_results("SELECT id,status,queue_started_at,accepted_at,created_at FROM {$ticket_table} WHERE status IN ('queued','active','waiting_agent','waiting_customer','pending_email') AND assigned_agent_id=0 AND accepted_at IS NULL AND agent_connected_at IS NULL");
        foreach ((array)$waiting_rows as $waiting_row) {
            $started=self::mysql_timestamp($waiting_row->queue_started_at ?: $waiting_row->created_at);
            $legacy_pending = !empty($waiting_row->status) && $waiting_row->status==='pending_email';
            if (!$started && !$legacy_pending) continue;
            wp_clear_scheduled_hook('hc_ai_escalation_timeout',array((int)$waiting_row->id));
            $due=$legacy_pending?time()+5:max(time()+5,$started+5*MINUTE_IN_SECONDS);
            wp_schedule_single_event($due,'hc_ai_escalation_timeout',array((int)$waiting_row->id));
        }
        update_option('hc_ai_live_support_db_version', self::DB_VERSION, false);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'hc_ai_escalations';
    }

    private static function messages_table() {
        global $wpdb;
        return $wpdb->prefix . 'hc_ai_escalation_messages';
    }

    private static function public_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'hc_ai_public')) {
            wp_send_json_error(array('message' => 'Please refresh the chat and try again.'), 403);
        }
    }

    private static function admin_nonce() {
        if (!self::can_manage() || !wp_verify_nonce($_POST['nonce'] ?? '', 'hc_nonce')) {
            wp_send_json_error(array('message' => 'You are not allowed to manage human support.'), 403);
        }
    }

    public static function ticket_for_session($session_hash, $visitor_hash = '') {
        if (!$session_hash && !$visitor_hash) return null;
        global $wpdb;
        if ($visitor_hash) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE (session_hash=%s OR visitor_hash=%s) AND status NOT IN ('closed','archived','converted') ORDER BY last_activity DESC,id DESC LIMIT 1",
                (string)$session_hash, (string)$visitor_hash
            ));
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE session_hash=%s AND status NOT IN ('closed','archived','converted') ORDER BY id DESC LIMIT 1",
            $session_hash
        ));
    }

    private static function current_conversation_id($session_hash) {
        global $wpdb;
        if (!$session_hash) return 0;
        $table = $wpdb->prefix . 'hc_ai_conversations';
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_hash=%s ORDER BY updated_at DESC,id DESC LIMIT 1",
            $session_hash
        ));
        if ($id) $wpdb->update($table, array('status'=>'active','updated_at'=>current_time('mysql')), array('id'=>$id), array('%s','%s'), array('%d'));
        return $id;
    }

    /**
     * Return only AI turns from the exact conversation that initiated this
     * HelpCenter handoff. Other chats sharing a session, visitor hash, or user
     * account belong in customer history, not in this ticket transcript.
     */
    public static function get_ticket_ai_history($ticket, $after_id = 0, $limit = 1000, $latest = false) {
        global $wpdb;
        if (!$ticket || !is_object($ticket) || empty($ticket->conversation_id)) return array();
        $message_table = $wpdb->prefix . 'hc_ai_messages';
        $conversation_id = (int) $ticket->conversation_id;
        $limit = max(1, min(2000, (int) $limit));
        $after_id = max(0, (int) $after_id);
        if ($latest && $after_id <= 0) {
            $sql = $wpdb->prepare(
                "SELECT id,conversation_id,role,content,created_at FROM {$message_table} WHERE conversation_id=%d AND role IN ('user','assistant') ORDER BY id DESC LIMIT %d",
                $conversation_id, $limit
            );
            return array_reverse((array) $wpdb->get_results($sql, ARRAY_A));
        }
        $sql = $wpdb->prepare(
            "SELECT id,conversation_id,role,content,created_at FROM {$message_table} WHERE conversation_id=%d AND id>%d AND role IN ('user','assistant') ORDER BY id ASC LIMIT %d",
            $conversation_id, $after_id, $limit
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    private static function insert_message($ticket_id, $conversation_id, $sender_type, $sender_user_id, $sender_name, $content, $client_id = '', &$was_duplicate = null, $email_status = 'not_required', $update_ticket = true, $publish_event = true) {
        global $wpdb;
        $was_duplicate = false;
        $content = trim((string) $content);
        if ($content === '') return 0;
        $table = self::messages_table();
        if ($client_id !== '') {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE ticket_id=%d AND client_message_id=%s LIMIT 1",
                (int) $ticket_id, $client_id
            ));
            if ($existing) { $was_duplicate = true; return $existing; }
        }
        $now = current_time('mysql');
        $inserted = $wpdb->insert($table, array(
            'ticket_id' => (int) $ticket_id,
            'conversation_id' => (int) $conversation_id,
            'sender_type' => sanitize_key($sender_type),
            'sender_user_id' => (int) $sender_user_id,
            'sender_name' => mb_substr(sanitize_text_field($sender_name), 0, 120),
            'content' => mb_substr($content, 0, 5000),
            'client_message_id' => ($client_id !== '' ? sanitize_text_field($client_id) : null),
            'email_status' => in_array($email_status, array('pending','not_required'), true) ? $email_status : 'not_required',
            'created_at' => $now,
        ), array('%d','%d','%s','%d','%s','%s','%s','%s','%s'));
        $message_id = $inserted ? (int) $wpdb->insert_id : 0;
        if (!$message_id && $client_id !== '') {
            // A parallel request may have inserted the same client ID after our first lookup.
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE ticket_id=%d AND client_message_id=%s LIMIT 1",
                (int) $ticket_id, $client_id
            ));
            if ($existing) { $was_duplicate = true; return $existing; }
        }
        if ($message_id) {
            // Endpoints that also change ticket state can fold this into their one
            // atomic ticket UPDATE instead of writing the same ticket row twice.
            if ($update_ticket) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . self::table() . " SET last_message_id=GREATEST(last_message_id,%d), last_activity=GREATEST(COALESCE(last_activity,%s),%s) WHERE id=%d",
                    $message_id, $now, $now, (int)$ticket_id
                ));
            }
            if ($publish_event && class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$ticket_id, 'message.created', $message_id);
            // Deliver an independent background push after the message is committed.
            if (class_exists('HC_Web_Push')) {
                $ticket_row = $wpdb->get_row($wpdb->prepare('SELECT assigned_agent_id,visitor_name FROM '.self::table().' WHERE id=%d', (int)$ticket_id));
                $snippet = wp_trim_words(wp_strip_all_tags($content), 22, '…');
                if ($sender_type === 'visitor' && $ticket_row && (int)$ticket_row->assigned_agent_id > 0) {
                    HC_Web_Push::send('agent', (int)$ticket_row->assigned_agent_id, 'New customer reply', $snippet, home_url('/support-agent/?tab=assigned&ticket='.(int)$ticket_id), 'hc-agent-reply-'.$ticket_id.'-'.$message_id, (int)$ticket_id, $now);
                } elseif ($sender_type === 'agent') {
                    HC_Web_Push::send('customer', (int)$ticket_id, 'New support reply', $snippet, home_url('/helpcenter/'), 'hc-customer-ticket-'.$ticket_id.'-'.$message_id, (int)$ticket_id, $now);
                }
            }
            if (in_array((string)$sender_type, array('visitor','agent'), true) && !wp_next_scheduled('hc_agent_generate_handover_summary', array((int)$ticket_id))) {
                wp_schedule_single_event(time() + 30, 'hc_agent_generate_handover_summary', array((int)$ticket_id));
            }
        }
        return $message_id;
    }

    private static function format_message($row, $session_id = '', $session_hash_override = '') {
        $out = array(
            'id' => (int) $row->id,
            'client_message_id' => (string) ($row->client_message_id ?? ''),
            'sender_type' => (string) $row->sender_type,
            'sender_user_id' => (int) $row->sender_user_id,
            'sender_name' => (string) $row->sender_name,
            'content' => (string) $row->content,
            'created_at' => (string) $row->created_at,
            'avatar_url' => !empty($row->sender_user_id) ? get_avatar_url((int)$row->sender_user_id, array('size'=>48)) : '',
        );
        if (!empty($row->attachment_name)) {
            $attachment = array('name'=>(string)$row->attachment_name,'mime'=>(string)$row->attachment_mime,'size'=>(int)$row->attachment_size,'kind'=>(string)$row->attachment_kind,'expired'=>empty($row->attachment_path) && !empty($row->attachment_deleted_at));
            if (!empty($row->attachment_path)) {
                $attachment['url'] = self::signed_preview_url((int)$row->ticket_id, (int)$row->id, $session_id, $session_hash_override);
            }
            $out['attachment'] = $attachment;
        }
        return $out;
    }

    private static function mysql_timestamp($value) {
        if (!$value) return 0;
        try { $date = date_create_from_format('Y-m-d H:i:s', (string) $value, wp_timezone()); return $date ? $date->getTimestamp() : 0; }
        catch (Exception $e) { return 0; }
    }

    /**
     * Deliver a single agent message only when its ticket is in email-follow-up mode.
     * The database state acts as a small outbox so failed wp_mail calls can be retried.
     */
    private static function deliver_email_message($message_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*,t.visitor_name,t.visitor_email,t.status AS ticket_status FROM " . self::messages_table() . " m INNER JOIN " . self::table() . " t ON t.id=m.ticket_id WHERE m.id=%d",
            (int)$message_id
        ));
        if (!$row || $row->sender_type !== 'agent' || !in_array((string)$row->email_status, array('pending','failed','sending'), true)) return null;
        if ($row->ticket_status === 'converted') { $wpdb->update(self::messages_table(),array('email_status'=>'cancelled','email_error'=>'Conversation was moved to the HCK ticket portal; email replies are no longer used.'),array('id'=>(int)$message_id),array('%s','%s'),array('%d')); return false; }
        $now = current_time('mysql');
        $stale = wp_date('Y-m-d H:i:s', time() - 300);
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::messages_table() . " SET email_status='sending',email_attempts=email_attempts+1,email_attempted_at=%s,email_error=NULL WHERE id=%d AND email_attempts<5 AND (email_status IN ('pending','failed') OR (email_status='sending' AND (email_attempted_at IS NULL OR email_attempted_at<%s)))",
            $now, (int)$message_id, $stale
        ));
        if (!$claimed) {
            $status = (string)$wpdb->get_var($wpdb->prepare("SELECT email_status FROM " . self::messages_table() . " WHERE id=%d", (int)$message_id));
            return $status === 'sent';
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", (int)$message_id));
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT visitor_name,visitor_email FROM " . self::table() . " WHERE id=%d", (int)$row->ticket_id));
        if (!$ticket) { $wpdb->update(self::messages_table(), array('email_status'=>'failed','email_error'=>'Support ticket record was not found.'), array('id'=>(int)$message_id), array('%s','%s'), array('%d')); return false; }
        $attempt = (int)$row->email_attempts;
        $attachments = array();
        $subject = 'New reply to your Help Center support request #' . (int)$row->ticket_id;
        $body = "Hello " . ($ticket->visitor_name ?: 'there') . ",\n\n" . (string)$row->content . "\n\nThis ticket is in email follow-up mode. Further updates from our support team will be sent to this email address.\n\nTicket #" . (int)$row->ticket_id;
        if (!empty($row->attachment_path)) {
            $path = self::attachment_absolute_path($row->attachment_path);
            if ($path && is_file($path)) {
                $attachments[] = $path;
                $subject = 'File attached to your Help Center support request #' . (int)$row->ticket_id;
                $body = "Hello " . ($ticket->visitor_name ?: 'there') . ",\n\nOur support team sent you the file: " . (string)$row->attachment_name . ". Please find it attached.\n\nTicket #" . (int)$row->ticket_id;
            } else {
                $body .= "\n\nAttachment " . (string)$row->attachment_name . " is currently unavailable. Please contact our support team if you need it resent.";
            }
        }
        $sent = $ticket && is_email($ticket->visitor_email) ? wp_mail($ticket->visitor_email, $subject, $body, array(), $attachments) : false;
        if ($sent) {
            $wpdb->update(self::messages_table(), array('email_status'=>'sent','email_sent_at'=>current_time('mysql'),'email_error'=>null), array('id'=>(int)$message_id), array('%s','%s','%s'), array('%d'));
            return true;
        }
        $wpdb->update(self::messages_table(), array('email_status'=>'failed','email_error'=>'WordPress mail transport did not confirm delivery.'), array('id'=>(int)$message_id), array('%s','%s'), array('%d'));
        if ($attempt < 5) {
            wp_schedule_single_event(time() + min(900, 60 * $attempt), 'hc_ai_support_retry_message_email', array((int)$message_id));
        }
        return false;
    }

    public static function retry_message_email($message_id) {
        self::deliver_email_message((int)$message_id);
    }

    private static function retry_due_email_messages() {
        global $wpdb;
        $stale = wp_date('Y-m-d H:i:s', time() - 300);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT m.id FROM " . self::messages_table() . " m INNER JOIN " . self::table() . " t ON t.id=m.ticket_id WHERE t.status IN ('pending_email','closed') AND m.sender_type='agent' AND m.email_attempts<5 AND (m.email_status IN ('pending','failed','sending') AND (m.email_attempted_at IS NULL OR m.email_attempted_at<%s)) LIMIT 20",
            $stale
        ));
        foreach ((array)$ids as $id) self::deliver_email_message((int)$id);
    }

    private static function maybe_timeout($ticket) {
        if (!$ticket || in_array($ticket->status,array('converted','closed'),true)) return $ticket;
        $legacy_followup = $ticket->status === 'pending_email';
        if (!empty($ticket->assigned_agent_id) || !empty($ticket->accepted_at) || !empty($ticket->agent_connected_at)) return $ticket;
        $prejoin = in_array($ticket->status,array('queued','active','waiting_agent','waiting_customer'),true) && empty($ticket->agent_connected_at);
        if (!$legacy_followup && !$prejoin) return $ticket;
        // Legacy pending_email conversations are migrated immediately. New requests
        // convert five minutes after the original request, not after assignment/messages.
        if (!$legacy_followup) {
            $created=self::mysql_timestamp($ticket->queue_started_at ?: $ticket->created_at);
            if (!$created || time() < $created + (5*MINUTE_IN_SECONDS)) return $ticket;
        }
        if (class_exists('HCK_HelpCenter_Integration')) {
            $result=HCK_HelpCenter_Integration::auto_convert((int)$ticket->id);
            if (!is_wp_error($result)) {
                global $wpdb;
                $fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',(int)$ticket->id));
                return $fresh ?: $ticket;
            }
            if (in_array($result->get_error_code(),array('agent_joined','agent_assigned'),true)) return $ticket;
            error_log('[HelpCenter] Automatic HCK follow-up conversion will retry for conversation '.(int)$ticket->id.': '.$result->get_error_message());
            if (!wp_next_scheduled('hc_ai_escalation_timeout',array((int)$ticket->id))) wp_schedule_single_event(time()+60,'hc_ai_escalation_timeout',array((int)$ticket->id));
            return $ticket;
        }
        error_log('[HelpCenter] HCK integration is unavailable; cannot convert timed-out conversation '.(int)$ticket->id);
        if (!wp_next_scheduled('hc_ai_escalation_timeout',array((int)$ticket->id))) wp_schedule_single_event(time()+60,'hc_ai_escalation_timeout',array((int)$ticket->id));
        return $ticket;
    }

    private static function is_recent($value, $seconds) {
        $ts = self::mysql_timestamp($value);
        return $ts > 0 && (time() - $ts) <= (int) $seconds;
    }

    public static function visitor_typing() {
        self::public_nonce();
        global $wpdb;
        $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $hash = HC_AI_Platform::session_hash($session);
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $typing = !empty($_POST['typing']);
        if (!$hash || !$ticket_id) wp_send_json_error(array('message' => 'Conversation not found.'), 404);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d AND session_hash=%s", $ticket_id, $hash));
        if (!$ticket || $ticket->status === 'closed') wp_send_json_error(array('message' => 'This support conversation is closed.'), 403);
        $ticket = self::maybe_timeout($ticket);
        if (in_array($ticket->status, array('pending_email','converted'), true)) wp_send_json_error(array('message' => $ticket->status === 'converted' ? 'This conversation has been moved to the ticket portal. Please use the secure ticket link sent to your email.' : 'This chat has moved to email follow-up.', 'converted' => $ticket->status === 'converted'), 403);
        $now = $typing ? current_time('mysql') : null;
        $wpdb->update(self::table(), array('visitor_typing_at' => $now, 'last_visitor_seen_at' => current_time('mysql')), array('id' => $ticket_id), array('%s','%s'), array('%d'));
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'typing.visitor', 0, array('typing'=>(bool)$typing));
        wp_send_json_success(array('typing' => $typing));
    }

    public static function admin_typing() {
        self::admin_nonce();
        global $wpdb;
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $typing = !empty($_POST['typing']);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT id,status,assigned_agent_id FROM " . self::table() . " WHERE id=%d", $ticket_id));
        if (!$ticket || in_array($ticket->status, array('closed','converted'), true)) wp_send_json_error(array('message' => 'This ticket is closed, converted, or unavailable.'), 404);
        if ((int) $ticket->assigned_agent_id !== get_current_user_id()) wp_send_json_error(array('message' => 'Only the assigned agent can update typing status.'), 403);
        $now = $typing ? current_time('mysql') : null;
        $wpdb->update(self::table(), array('agent_typing_at' => $now, 'last_agent_seen_at' => current_time('mysql'), 'agent_connected_at' => current_time('mysql')), array('id' => $ticket_id), array('%s','%s','%s'), array('%d'));
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'typing.agent', 0, array('typing'=>(bool)$typing));
        wp_send_json_success(array('typing' => $typing));
    }

    public static function admin_conversations_live() {
        self::admin_nonce();
        global $wpdb;
        $table = $wpdb->prefix . 'hc_ai_conversations';
        $messages = $wpdb->prefix . 'hc_ai_messages';
        $tickets_table = self::table();
        $ticket_messages_table = self::messages_table();
        $users = $wpdb->users;
        $q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = max(1, min(50, absint($_POST['per_page'] ?? 10)));
        $sort_by = sanitize_key($_POST['sort_by'] ?? 'updated');
        $sort_dir = strtoupper(sanitize_key($_POST['sort_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sort_map = array(
            'updated' => 'c.updated_at', 'created' => 'c.created_at', 'id' => 'c.id',
            'messages' => 'message_count', 'username' => 'COALESCE(NULLIF(u.display_name,\'\'),u.user_login)',
        );
        $order_sql = $sort_map[$sort_by] ?? 'c.updated_at';
        $where = '1=1'; $args = array();
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $ticket_search = ltrim($q, "# \t\n\r\0\x0B");
            $ticket_like = '%' . $wpdb->esc_like($ticket_search) . '%';
            $where .= " AND (CAST(c.id AS CHAR) LIKE %s OR CAST(c.user_id AS CHAR) LIKE %s OR c.title LIKE %s OR c.visitor_hash LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR c.created_at LIKE %s OR c.updated_at LIKE %s OR EXISTS (SELECT 1 FROM {$tickets_table} stq WHERE (stq.conversation_id=c.id OR (c.session_hash<>'' AND stq.session_hash=c.session_hash) OR (c.visitor_hash<>'' AND stq.visitor_hash=c.visitor_hash)) AND CAST(stq.id AS CHAR) LIKE %s))";
            array_push($args, $like,$like,$like,$like,$like,$like,$like,$like,$like,$ticket_like);
        }
        $from = " FROM {$table} c LEFT JOIN {$users} u ON u.ID=c.user_id ";
        $total_sql = "SELECT COUNT(*)" . $from . " WHERE {$where}";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($total_sql,$args) : $total_sql);
        $offset = ($page - 1) * $per_page;
        $support_count = "(SELECT COUNT(*) FROM {$ticket_messages_table} sm INNER JOIN {$tickets_table} st ON st.id=sm.ticket_id WHERE (st.conversation_id=c.id OR (c.session_hash<>'' AND st.session_hash=c.session_hash) OR (c.visitor_hash<>'' AND st.visitor_hash=c.visitor_hash)) AND sm.sender_type<>'internal_note')";
        $ticket_ids_sql = "(SELECT GROUP_CONCAT(DISTINCT stc.id ORDER BY stc.id DESC SEPARATOR ',') FROM {$tickets_table} stc WHERE (stc.conversation_id=c.id OR (c.session_hash<>'' AND stc.session_hash=c.session_hash) OR (c.visitor_hash<>'' AND stc.visitor_hash=c.visitor_hash)))";
        $sql = "SELECT c.id,c.title,c.created_at,c.updated_at,c.user_id,c.visitor_hash,COALESCE(u.display_name,'') AS display_name,COALESCE(u.user_login,'') AS user_login,{$ticket_ids_sql} AS ticket_ids,((SELECT COUNT(*) FROM {$messages} mm WHERE mm.conversation_id=c.id)+{$support_count}) AS message_count" . $from . " WHERE {$where} ORDER BY {$order_sql} {$sort_dir},c.id DESC LIMIT %d OFFSET %d";
        $query_args = $args; $query_args[] = $per_page; $query_args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql,$query_args), ARRAY_A);
        $items = array();
        foreach ((array) $rows as $row) {
            $visitor = $row['display_name'] ?: ($row['user_login'] ?: (!empty($row['visitor_hash']) ? 'Anonymous visitor · ' . substr((string)$row['visitor_hash'],0,7) : 'Anonymous visitor'));
            $items[] = array(
                'id'=>(int)$row['id'], 'title'=>(string)($row['title'] ?: 'New conversation'),
                'message_count'=>(int)$row['message_count'], 'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)$row['updated_at'], 'visitor'=>$visitor, 'is_user'=>(bool)$row['user_id'],
                'ticket_ids'=>array_values(array_filter(array_map('absint', explode(',', (string)($row['ticket_ids'] ?? ''))))),
                'url'=>add_query_arg('hc_ai_conversation',(int)$row['id'],home_url('/helpcenter/admin/ai-platform/')).'#hc-ai-conversations-card',
            );
        }
        wp_send_json_success(array('items'=>$items,'page'=>$page,'per_page'=>$per_page,'total'=>$total,'pages'=>(int)ceil($total/$per_page),'sort_by'=>$sort_by,'sort_dir'=>$sort_dir,'server_time'=>current_time('mysql')));
    }

    public static function admin_conversation_messages_live() {
        self::admin_nonce();
        global $wpdb;
        $conversation_id = absint($_POST['conversation_id'] ?? 0);
        $after_id = absint($_POST['after_id'] ?? 0);
        $ticket_after_id = absint($_POST['ticket_after_id'] ?? 0);
        if (!$conversation_id) wp_send_json_error(array('message' => 'Conversation not found.'), 404);
        $conversation_table = $wpdb->prefix . 'hc_ai_conversations';
        $conversation = $wpdb->get_row($wpdb->prepare("SELECT id,session_hash,visitor_hash FROM {$conversation_table} WHERE id=%d", $conversation_id));
        if (!$conversation) wp_send_json_error(array('message' => 'Conversation not found.'), 404);

        // AI messages and human-support messages live in separate tables. Resolve
        // every ticket linked by conversation ID or the same visitor/session hash.
        $ticket_table = self::table();
        $ticket_message_table = self::messages_table();
        $ticket_where = array('conversation_id=%d');
        $ticket_args = array($conversation_id);
        if (!empty($conversation->session_hash)) { $ticket_where[] = 'session_hash=%s'; $ticket_args[] = (string)$conversation->session_hash; }
        if (!empty($conversation->visitor_hash)) { $ticket_where[] = 'visitor_hash=%s'; $ticket_args[] = (string)$conversation->visitor_hash; }
        $ticket_sql = "SELECT id FROM {$ticket_table} WHERE (" . implode(' OR ', $ticket_where) . ") ORDER BY id ASC";
        $ticket_ids = array_values(array_filter(array_map('absint', (array)$wpdb->get_col($wpdb->prepare($ticket_sql, $ticket_args)))));

        $messages_table = $wpdb->prefix . 'hc_ai_messages';
        $ai_rows = $wpdb->get_results($wpdb->prepare("SELECT id,role,content,sources,created_at FROM {$messages_table} WHERE conversation_id=%d AND id>%d ORDER BY id ASC LIMIT 200", $conversation_id, $after_id), ARRAY_A);
        $last_ai = $after_id;
        $out = array();
        foreach ((array)$ai_rows as $row) {
            $last_ai = max($last_ai, (int)$row['id']);
            $out[] = array('id'=>(int)$row['id'],'source_type'=>'ai','source_id'=>(int)$row['id'],'role'=>(string)$row['role'],'content'=>(string)$row['content'],'sources'=>(string)$row['sources'],'created_at'=>(string)$row['created_at']);
        }

        $ticket_rows = array();
        $last_ticket = $ticket_after_id;
        $total_ticket_messages = 0;
        if ($ticket_ids) {
            $placeholders = implode(',', array_fill(0, count($ticket_ids), '%d'));
            $count_sql = "SELECT COUNT(*) FROM {$ticket_message_table} WHERE ticket_id IN ({$placeholders}) AND sender_type<>'internal_note'";
            $total_ticket_messages = (int)$wpdb->get_var($wpdb->prepare($count_sql, $ticket_ids));
            $query_args = array_merge($ticket_ids, array($ticket_after_id));
            $ticket_rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ticket_message_table} WHERE ticket_id IN ({$placeholders}) AND id>%d AND sender_type<>'internal_note' ORDER BY id ASC LIMIT 200", $query_args));
            foreach ((array)$ticket_rows as $row) {
                $last_ticket = max($last_ticket, (int)$row->id);
                $formatted = self::format_message($row, '');
                $role = (string)$row->sender_type;
                $out[] = array(
                    'id'=>(int)$row->id,'source_type'=>'ticket','source_id'=>(int)$row->id,
                    'role'=>$role,'sender_name'=>(string)$row->sender_name,
                    'content'=>(string)$row->content,'sources'=>'','created_at'=>(string)$row->created_at,
                    'attachment'=>$formatted['attachment'] ?? null
                );
            }
        }
        usort($out, function($a,$b){$cmp=strcmp((string)($a['created_at']??''),(string)($b['created_at']??''));if($cmp!==0)return $cmp;return (($a['source_type']??'ai')==='ai'?0:1) <=> (($b['source_type']??'ai')==='ai'?0:1);});
        $total_ai = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$messages_table} WHERE conversation_id=%d", $conversation_id));
        wp_send_json_success(array(
            'messages'=>$out,
            'last_message_id'=>$last_ai,
            'last_ticket_message_id'=>$last_ticket,
            'total_messages'=>$total_ai + $total_ticket_messages
        ));
    }

    public static function run_ai_priority_classifier($ticket_id) {
        global $wpdb; $ticket_id=absint($ticket_id); $ticket=$wpdb->get_row($wpdb->prepare("SELECT id,issue,priority,status FROM ".self::table()." WHERE id=%d",$ticket_id));
        if(!$ticket || in_array($ticket->status,array('closed','archived'),true) || !class_exists('HC_AI') || !method_exists('HC_AI','chat_model')) return;
        $prompt="Classify this customer support ticket priority. Return ONLY one word: low, normal, high, or urgent. Criteria: URGENT = immediate safety/security risk, suspected account takeover/fraud/data breach, serious harm, or critical payment compromise; HIGH = money deducted/payment failure, repeated charge, undelivered time-sensitive order, spoiled/damaged essential goods, severe service interruption, or refund significantly overdue; NORMAL = ordinary refund, replacement, missing/wrong item, cancellation, complaint or delivery delay; LOW = general information, how-to, product details, or non-urgent request. Do not escalate ordinary dissatisfaction to urgent. Ticket text: ".wp_strip_all_tags((string)$ticket->issue);
        $result=HC_AI::chat_model(array(array('role'=>'system','content'=>'You are a conservative support-ticket triage classifier. Follow the priority criteria exactly.'),array('role'=>'user','content'=>$prompt)));
        if(is_wp_error($result)||!is_string($result)) return; $priority=strtolower(trim(wp_strip_all_tags($result))); $priority=preg_replace('/[^a-z]/','',$priority); if(!in_array($priority,array('low','normal','high','urgent'),true)) return;
        $wpdb->update(self::table(),array('priority'=>$priority),array('id'=>$ticket_id),array('%s'),array('%d'));
    }

    private static function classify_ticket_priority($text) {
        $v = strtolower(wp_strip_all_tags((string)$text));
        // Conservative deterministic triage: urgent is reserved for immediate safety/security or severe service-impact signals.
        $urgent = array('account hacked','unauthorized access','fraud','stolen','safety issue','serious injury','emergency','data breach','payment charged multiple times');
        $high = array('payment failed','money deducted','double charged','wrong amount charged','order not delivered','delivery driver','perishable','spoiled','damaged','refund overdue','cannot access account','cancel immediately','today delivery');
        foreach ($urgent as $term) if (strpos($v,$term)!==false) return 'urgent';
        foreach ($high as $term) if (strpos($v,$term)!==false) return 'high';
        if (preg_match('/\b(refund|replacement|missing item|wrong item|cancel|late delivery|complaint|unavailable)\b/',$v)) return 'normal';
        return 'low';
    }

    public static function check_followup() {
        self::public_nonce();
        if (!class_exists('HCK_HelpCenter_Integration')) wp_send_json_success(array('followup_blocked'=>false));
        $visitor_raw=sanitize_text_field(wp_unslash($_POST['visitor_id']??''));
        $visitor_hash=HC_AI_Platform::session_hash($visitor_raw);
        $email=sanitize_email(wp_unslash($_POST['email']??''));
        if (get_current_user_id()) { $u=wp_get_current_user(); if ($u && $u->exists()) $email=$u->user_email; }
        $row=HCK_HelpCenter_Integration::blocking_followup(get_current_user_id(),$email,$visitor_hash);
        if (!$row) wp_send_json_success(array('followup_blocked'=>false));
        wp_send_json_success(array('followup_blocked'=>true,'hck_ticket_id'=>(int)$row->id,'hck_ticket_number'=>hck_get_ticket_number($row),'ticket_portal_url'=>$row->portal_url,'guest_access_pending'=>!(int)$row->user_id,'guest_contact_email'=>(string)$row->customer_email,'message'=>'You already have an open follow-up ticket. Please reply there first. Once an agent responds, you can request human support again.'));
    }

    public static function create_ticket() {
        self::public_nonce();
        global $wpdb;
        $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $session_hash = HC_AI_Platform::session_hash($session);
        $support_session_raw = sanitize_text_field(wp_unslash($_POST['support_session_id'] ?? ''));
        $support_session_hash = HC_AI_Platform::session_hash($support_session_raw);
        // The source AI session owns the knowledge transcript; the dedicated
        // support session owns live ticket messages, typing, uploads and Ably.
        $ticket_session_hash = $support_session_hash ?: $session_hash;
        $visitor_raw = sanitize_text_field(wp_unslash($_POST['visitor_id'] ?? ''));
        $visitor_hash = HC_AI_Platform::session_hash($visitor_raw);
        if (!$session_hash || !$ticket_session_hash) wp_send_json_error(array('message' => 'Your chat session has expired. Please refresh and try again.'), 400);
        self::reassign_offline_owned_tickets();
        // A converted support conversation can never create or reopen another
        // HelpCenter live ticket under the same original session. Its follow-up
        // belongs exclusively to the linked HCK ticket.
        $current_ai_conversation_id = self::current_conversation_id($session_hash);
        // A converted conversation blocks only that exact AI conversation. A genuinely
        // new chatbot conversation in the same browser/session may start a fresh request.
        $links_table=$wpdb->prefix.'hck_hc_links';
        $has_links=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$links_table))===$links_table;
        $linked_only=$has_links ? " AND EXISTS (SELECT 1 FROM {$links_table} hcl WHERE hcl.hc_ticket_id=".self::table().".id)" : " AND status='converted'";
        if ($current_ai_conversation_id) {
            // Session hashes are intentionally separate after handoff. The
            // originating AI conversation ID remains the durable link to its
            // converted HCK ticket and is therefore the authoritative lookup.
            $converted = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE conversation_id=%d AND status IN ('converted','closed') {$linked_only} ORDER BY id DESC LIMIT 1", $current_ai_conversation_id));
        } else {
            $converted = null;
        }
        if ($converted) {
            $hck_id = $wpdb->get_var($wpdb->prepare('SELECT hck_ticket_id FROM '.$wpdb->prefix.'hck_hc_links WHERE hc_ticket_id=%d',(int)$converted->id));
            $hck_row = ($hck_id && function_exists('hck_get_ticket')) ? hck_get_ticket((int)$hck_id) : null;
            $hck_number = ($hck_row && function_exists('hck_get_ticket_number')) ? hck_get_ticket_number($hck_row) : '';
            $portal = ($hck_id && (int)$converted->user_id > 0) ? home_url('/ticket/view/'.(int)$hck_id.'/') : '';
            $guest_url=''; if($hck_id && !(int)$converted->user_id && class_exists('HCK_HelpCenter_Integration')) { $guest_url=HCK_HelpCenter_Integration::guest_link_for_chat((int)$converted->id,(int)$hck_id,(string)$converted->visitor_email,(string)$converted->visitor_name); }
            wp_send_json_success(array('ticket_id'=>(int)$converted->id,'status'=>'converted','hck_ticket_id'=>(int)$hck_id,'hck_ticket_number'=>$hck_number,'auto_created'=>($hck_row && ($hck_row->source??'')==='helpcenter_timeout'),'ticket_portal_url'=>$portal,'guest_access_url'=>$guest_url,'guest_access_pending'=>(int)$converted->user_id===0,'guest_contact_email'=>(string)$converted->visitor_email,'message'=>'This conversation has already been converted. It is read-only; please continue in your HCK ticket portal or start a new AI chat.'));
        }
        // A new human escalation is blocked while the same customer has a follow-up
        // ticket that has not yet received a public agent response. AI chat remains available.
        if (class_exists('HCK_HelpCenter_Integration')) {
            $candidate_email=sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if (get_current_user_id()) { $cu=wp_get_current_user(); if ($cu && $cu->exists()) $candidate_email=$cu->user_email; }
            $blocking=HCK_HelpCenter_Integration::blocking_followup(get_current_user_id(),$candidate_email,$visitor_hash);
            if ($blocking) {
                wp_send_json_success(array('followup_blocked'=>true,'hck_ticket_id'=>(int)$blocking->id,'hck_ticket_number'=>hck_get_ticket_number($blocking),'ticket_portal_url'=>$blocking->portal_url,'guest_access_pending'=>!(int)$blocking->user_id,'guest_contact_email'=>(string)$blocking->customer_email,'status'=>'followup_blocked','message'=>'You already have an open follow-up request. Please reply in that ticket first. Once our agent responds, you can request human support again.'));
            }
        }
        $existing = self::ticket_for_session($ticket_session_hash, $visitor_hash);
        // Closed support conversations are historical records. A fresh request gets a new
        // HelpCenter conversation; only an active unresolved request is reused above.
        if ($existing) {
            // Reuse the active support session for this browser, but never
            // overwrite the ticket's original AI conversation link when the
            // customer happened to escalate from a different AI conversation.
            if ($visitor_hash && !hash_equals((string)$existing->session_hash, (string)$ticket_session_hash)) {
                $wpdb->update(self::table(), array('session_hash'=>$ticket_session_hash,'visitor_hash'=>$visitor_hash), array('id'=>(int)$existing->id), array('%s','%s'), array('%d'));
                $existing->session_hash = $ticket_session_hash;
                $existing->visitor_hash = $visitor_hash;
            }
            $agent_id = (int)($existing->assigned_agent_id ?? 0);
            $agent_user = $agent_id ? get_userdata($agent_id) : false;
            wp_send_json_success(array('ticket_id' => (int) $existing->id, 'status' => $existing->status, 'agent_id'=>$agent_id, 'agent_name'=>$agent_user ? ($agent_user->display_name ?: $agent_user->user_login) : (string)$existing->assigned_agent_name, 'agent_connected'=>!empty($existing->agent_connected_at), 'queued_extra_messages'=>(int)$existing->queued_extra_messages, 'max_wait_messages'=>3, 'wait_minutes'=>5, 'no_agents_online'=>!self::online_agent_ids(), 'message' => 'Your existing human-support request is still active. We have restored the same support conversation.'));
        }
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $issue = trim(sanitize_textarea_field(wp_unslash($_POST['issue'] ?? '')));
        $user = wp_get_current_user();
        $conversation_id = self::current_conversation_id($session_hash);
        if (mb_strlen($issue) < 100 && $conversation_id) {
            $ai_table = $wpdb->prefix . 'hc_ai_messages';
            $recent_user = $wpdb->get_col($wpdb->prepare("SELECT content FROM {$ai_table} WHERE conversation_id=%d AND role='user' ORDER BY id DESC LIMIT 8", $conversation_id));
            $handoff_re = '/\b(agent|human|person|real person|support|customer service|representative|connect me|talk to|speak to)\b/i';
            $substantive = array();
            foreach (array_reverse((array)$recent_user) as $candidate) {
                $candidate = trim(wp_strip_all_tags((string)$candidate));
                if ($candidate !== '' && !preg_match($handoff_re, $candidate)) $substantive[] = $candidate;
            }
            if ($substantive) $issue = mb_substr(implode("\n", $substantive), 0, 3000);
        }
        if (mb_strlen($issue) < 100) wp_send_json_error(array('message'=>'Please describe your complete concern in at least 100 characters so the support team can understand the issue without asking you to repeat it.'), 400);
        if ($user && $user->exists()) {
            $name = $name ?: ($user->display_name ?: $user->user_login);
            $email = $email ?: $user->user_email;
        }
        if (!$name || !is_email($email)) wp_send_json_error(array('message' => 'Please provide your name and a valid email address.'));

        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::table(), array(
            'session_hash' => $ticket_session_hash,
            'visitor_hash' => $visitor_hash,
            'conversation_id' => $conversation_id,
            'user_id' => get_current_user_id(),
            'visitor_name' => mb_substr($name, 0, 120),
            'visitor_email' => $email,
            'issue' => $issue,
            'ai_title' => '', // Keep the title area in a stable loading state until the AI-generated case title is ready.
            'status' => 'queued',
            'priority' => self::classify_ticket_priority($issue),
            'created_at' => $now,
            'queue_started_at' => $now,
            'last_activity' => $now,
            'last_visitor_seen_at' => $now,
            'queued_extra_messages' => 1,
        ), array('%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d'));
        $ticket_id = (int) $wpdb->insert_id;
        if ($ticket_id) {
            wp_schedule_single_event(time()+2, 'hc_ai_classify_ticket_priority', array($ticket_id));
            if (!wp_next_scheduled('hc_agent_generate_ticket_title', array($ticket_id))) wp_schedule_single_event(time()+3, 'hc_agent_generate_ticket_title', array($ticket_id));
        }
        if (!$inserted || !$ticket_id) wp_send_json_error(array('message' => 'We could not create your support request. Please try again.'), 500);
        $first_id = self::insert_message($ticket_id, $conversation_id, 'visitor', get_current_user_id(), $name, $issue);
        if ($first_id) $wpdb->update(self::table(), array('last_message_id' => $first_id), array('id' => $ticket_id), array('%d'), array('%d'));
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'ticket.created', $first_id);
        // The timeout is fixed at exactly five minutes from the original handoff.
        wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'hc_ai_escalation_timeout', array($ticket_id));
        // Dispatch immediately so tickets created while an agent is already available are not stranded.
        self::dispatch_queue();
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $ticket_id));
        $assigned_id = $fresh ? (int)$fresh->assigned_agent_id : 0;
        $assigned_user = $assigned_id ? get_userdata($assigned_id) : false;
        $first_row = $first_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE id=%d AND ticket_id=%d', $first_id, $ticket_id)) : null;
        $first_message = $first_row ? self::format_message($first_row, $support_session_raw ?: $session) : null;
        $queue_start = $fresh ? ($fresh->queue_started_at ?: $fresh->created_at) : $now;
        $queue_epoch = self::mysql_timestamp($queue_start);
        $remaining = ($assigned_id || ($fresh && !empty($fresh->accepted_at))) ? 0 : ($queue_epoch ? max(0, ($queue_epoch + 5 * MINUTE_IN_SECONDS) - time()) : 300);
        wp_send_json_success(array('ticket_id' => $ticket_id, 'status' => $fresh ? $fresh->status : 'queued', 'first_message' => $first_message, 'queue_started_at' => $queue_start, 'wait_seconds_remaining' => $remaining, 'queued_extra_messages' => $fresh ? (int)$fresh->queued_extra_messages : 1, 'max_wait_messages' => 3, 'wait_minutes' => 5, 'agent_id' => $assigned_id, 'agent_name' => $assigned_user ? ($assigned_user->display_name ?: $assigned_user->user_login) : '', 'agent_connected' => $fresh ? !empty($fresh->agent_connected_at) : false, 'agent_assigned' => (bool)($assigned_id || ($fresh && !empty($fresh->accepted_at))), 'no_agents_online' => !self::online_agent_ids(), 'message' => $assigned_id ? 'Your support request has been assigned to ' . ($assigned_user->display_name ?: $assigned_user->user_login) . '.' : 'Your request has been added to the support queue. A specialist will be assigned when an eligible agent becomes available. If no agent is assigned within five minutes, we will create a support ticket and send you a secure link to continue there.'));
    }

    /**
     * List only the human-support tickets owned by this browser visitor.
     * The random visitor token and/or the current session token are required;
     * no account-wide ticket search is performed from this public endpoint.
     */
    public static function visitor_history() {
        self::public_nonce();
        global $wpdb;
        $session_raw = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $visitor_raw = sanitize_text_field(wp_unslash($_POST['visitor_id'] ?? ''));
        $session_hash = HC_AI_Platform::session_hash($session_raw);
        $visitor_hash = HC_AI_Platform::session_hash($visitor_raw);
        if (!$session_hash && !$visitor_hash) {
            wp_send_json_error(array('message' => 'Your conversation identity could not be verified. Please refresh the page.'), 400);
        }
        $table = self::table();
        if ($session_hash && $visitor_hash) {
            $sql = $wpdb->prepare(
                "SELECT id,status,issue,ai_title,created_at,last_activity,assigned_agent_id,assigned_agent_name,agent_connected_at FROM {$table} WHERE visitor_hash=%s OR session_hash=%s ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 30",
                $visitor_hash, $session_hash
            );
        } elseif ($visitor_hash) {
            $sql = $wpdb->prepare(
                "SELECT id,status,issue,ai_title,created_at,last_activity,assigned_agent_id,assigned_agent_name,agent_connected_at FROM {$table} WHERE visitor_hash=%s ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 30",
                $visitor_hash
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id,status,issue,ai_title,created_at,last_activity,assigned_agent_id,assigned_agent_name,agent_connected_at FROM {$table} WHERE session_hash=%s ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 30",
                $session_hash
            );
        }
        $rows = (array)$wpdb->get_results($sql);
        $items = array();
        foreach ($rows as $row) {
            $title = trim(wp_strip_all_tags((string)($row->ai_title ?: $row->issue)));
            $title = preg_replace('/\s+/u', ' ', $title);
            if ($title === '') $title = 'Human support conversation';
            if (function_exists('mb_strlen') && mb_strlen($title) > 72) $title = mb_substr($title, 0, 69) . '…';
            elseif (strlen($title) > 72) $title = substr($title, 0, 69) . '…';
            $agent_name = trim((string)($row->assigned_agent_name ?? ''));
            if (!empty($row->assigned_agent_id)) {
                $agent_user = get_userdata((int)$row->assigned_agent_id);
                if ($agent_user) $agent_name = (string)($agent_user->display_name ?: $agent_user->user_login);
            }
            $items[] = array(
                'ticket_id' => (int)$row->id,
                'status' => (string)$row->status,
                'title' => $title,
                'created_at' => (string)$row->created_at,
                'last_activity' => (string)($row->last_activity ?: $row->created_at),
                'agent_name' => $agent_name,
                'agent_connected' => !empty($row->agent_connected_at),
            );
        }
        wp_send_json_success(array('tickets' => $items, 'count' => count($items)));
    }

    public static function visitor_status() {
        self::public_nonce();
        global $wpdb;
        $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $hash = HC_AI_Platform::session_hash($session);
        $visitor_hash = HC_AI_Platform::session_hash(sanitize_text_field(wp_unslash($_POST['visitor_id'] ?? '')));
        $current_user_id = get_current_user_id();
        if (!$hash && !$visitor_hash && !$current_user_id) wp_send_json_error(array('message' => 'Conversation not found.'), 404);
        // Frequent status polls must stay scoped to this visitor's ticket. The
        // scheduled 15-second watchdog and per-ticket maybe_timeout() check keep
        // timeout conversion reliable without scanning the global ticket table
        // on every customer's poll.
        $ticket = null;
        $session_only = !empty($_POST['session_only']);
        $requested_ticket_id = absint($_POST['ticket_id'] ?? 0);
        // A refreshed browser already has the exact active ticket ID saved locally.
        // Resolve that ticket directly instead of deriving it from whichever AI/bot
        // conversation happens to be selected in this tab. The unguessable session
        // hash remains mandatory, so a ticket ID alone never grants access.
        if ($requested_ticket_id) {
            // Ticket navigation must resolve the exact selected ticket. A stale
            // or different AI session must never silently load another active
            // ticket. The visitor token is an independent browser ownership proof.
            if ($hash) {
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d AND session_hash=%s LIMIT 1", $requested_ticket_id, $hash));
            }
            if (!$ticket && $visitor_hash) {
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d AND visitor_hash=%s LIMIT 1", $requested_ticket_id, $visitor_hash));
            }
            if (!$ticket) {
                wp_send_json_error(array('message' => 'This support conversation does not belong to the current browser session.'), 403);
            }
        }
        if (!$ticket && $session_only) {
            $active_statuses = "('queued','active','waiting_agent','waiting_customer')";
            if ($hash) {
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . self::table() . " WHERE session_hash=%s AND status IN {$active_statuses} ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 1",
                    $hash
                ));
            }
            // The visitor token is a second, browser-held ownership proof. It
            // recovers an active ticket if the current AI session key changed
            // during a refresh or a previous conversation-history selection.
            if (!$ticket && $visitor_hash) {
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . self::table() . " WHERE visitor_hash=%s AND status IN {$active_statuses} ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 1",
                    $visitor_hash
                ));
            }
            // When the customer is browsing a bot conversation, allow an explicit
            // "view previous human conversation" action to discover the latest closed
            // ticket using the same browser ownership token. Never expose internal notes.
            if (!$ticket && !empty($_POST['include_recent']) && $visitor_hash) {
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . self::table() . " WHERE visitor_hash=%s AND status IN ('closed','converted','pending_email','archived') ORDER BY COALESCE(last_activity,created_at) DESC,id DESC LIMIT 1",
                    $visitor_hash
                ));
            }
        } elseif (!$ticket && $hash && !$session_only) {
            $current_ai_conversation_id = self::current_conversation_id($hash);
            if ($current_ai_conversation_id) {
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE session_hash=%s AND conversation_id=%d ORDER BY id DESC LIMIT 1", $hash, $current_ai_conversation_id));
            } else {
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE session_hash=%s ORDER BY id DESC LIMIT 1", $hash));
            }
        }
        if (!$ticket) wp_send_json_success(array('status' => 'none', 'messages' => array(), 'last_message_id' => 0));
        if ($requested_ticket_id && $hash && $visitor_hash && hash_equals((string)$ticket->visitor_hash, (string)$visitor_hash) && !hash_equals((string)$ticket->session_hash, (string)$hash)) {
            // The explicit ticket ID plus the unguessable browser visitor token
            // authorizes opening this exact historical ticket. Rebind it only to
            // the dedicated human-support session supplied by the customer widget;
            // the AI session remains separate and is never changed here.
            $wpdb->update(self::table(), array('session_hash'=>$hash), array('id'=>(int)$ticket->id), array('%s'), array('%d'));
            $ticket->session_hash = $hash;
        }
        if ($hash && hash_equals((string)$ticket->session_hash, (string)$hash) && $visitor_hash && !hash_equals((string)$ticket->visitor_hash, (string)$visitor_hash)) {
            // Refresh the secondary browser ownership token only when this exact
            // ticket's primary human-support session has already been validated.
            $wpdb->update(self::table(), array('visitor_hash'=>$visitor_hash), array('id'=>(int)$ticket->id), array('%s'), array('%d'));
            $ticket->visitor_hash = $visitor_hash;
        }
        // Older v162/v163 tickets can have the human-support session stored as
        // session_hash while their originating AI conversation_id is missing or
        // stale. The browser sends the original AI session only for this exact
        // ticket; bind it only when the unguessable visitor token matches both
        // the ticket and the AI conversation. This repairs the transcript link
        // without ever searching by account or attaching another chat by recency.
        $source_session_raw = sanitize_text_field(wp_unslash($_POST['source_session_id'] ?? ''));
        $source_session_hash = HC_AI_Platform::session_hash($source_session_raw);
        if ($requested_ticket_id && $source_session_hash && $visitor_hash && hash_equals((string)($ticket->visitor_hash ?? ''), (string)$visitor_hash)) {
            $ai_conversation_table = $wpdb->prefix . 'hc_ai_conversations';
            $ai_message_table = $wpdb->prefix . 'hc_ai_messages';
            $source_conversation_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT c.id FROM {$ai_conversation_table} c WHERE c.session_hash=%s AND c.visitor_hash=%s AND EXISTS (SELECT 1 FROM {$ai_message_table} m WHERE m.conversation_id=c.id AND m.role IN ('user','assistant')) ORDER BY c.updated_at DESC,c.id DESC LIMIT 1",
                $source_session_hash, $visitor_hash
            ));
            if ($source_conversation_id && (int)($ticket->conversation_id ?? 0) !== $source_conversation_id) {
                $wpdb->update(self::table(), array('conversation_id'=>$source_conversation_id), array('id'=>(int)$ticket->id), array('%d'), array('%d'));
                $ticket->conversation_id = $source_conversation_id;
            }
        }
        $ticket = self::maybe_timeout($ticket);
        if ($ticket->status === 'active' && (int)$ticket->assigned_agent_id) $ticket = self::maybe_timeout($ticket);
        $id = (int) $ticket->id;
        $after = absint($_POST['after_id'] ?? 0);
        $now_ts = time();
        $seen = self::mysql_timestamp($ticket->last_visitor_seen_at ?? '');
        if (!$seen || $now_ts - $seen > 8) {
            $wpdb->update(self::table(), array('last_visitor_seen_at' => current_time('mysql')), array('id' => $id), array('%s'), array('%d'));
        }
        $messages_table = self::messages_table();
        $wpdb->query($wpdb->prepare("UPDATE {$messages_table} SET read_by_visitor_at=%s WHERE ticket_id=%d AND sender_type IN ('agent','system') AND read_by_visitor_at IS NULL", current_time('mysql'), $id));
        if ($after > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$messages_table} WHERE ticket_id=%d AND id>%d AND sender_type<>'internal_note' ORDER BY id ASC LIMIT 1000", $id, $after));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM (SELECT * FROM {$messages_table} WHERE ticket_id=%d AND sender_type<>'internal_note' ORDER BY id DESC LIMIT 1000) recent ORDER BY id ASC", $id));
        }
        $ticket_session_hash = (string)($ticket->session_hash ?? '');
        $messages = array_map(function($row) use ($session, $ticket_session_hash) { return self::format_message($row, $session, $ticket_session_hash); }, (array) $rows);
        // On the initial transcript load, include the AI conversation that led to
        // this human handoff. AI IDs are namespaced and do not affect ticket cursors.
        if ($after === 0 && method_exists(__CLASS__, 'get_ticket_ai_history')) {
            $ai_history = self::get_ticket_ai_history($ticket, 0, 1000, true);
            foreach ((array) $ai_history as $ai_row) {
                $messages[] = array(
                    'id' => 0,
                    'message_key' => 'ai:' . (int)($ai_row['id'] ?? 0),
                    'source_id' => (int)($ai_row['id'] ?? 0),
                    'sender_type' => (($ai_row['role'] ?? '') === 'user') ? 'visitor' : 'ai',
                    'sender_name' => (($ai_row['role'] ?? '') === 'user') ? (string)$ticket->visitor_name : 'AI Assistant',
                    'content' => (string)($ai_row['content'] ?? ''),
                    'created_at' => (string)($ai_row['created_at'] ?? ''),
                );
            }
        }
        // Guarantee that the detailed handoff concern is visible even when an
        // older message row is missing or falls outside the recent-message window.
        if ($after === 0) {
            $handoff_issue = trim(wp_strip_all_tags((string) $ticket->issue));
            $handoff_issue = preg_replace('/(?:\\s*Five minutes have passed\\.?\\s*Preparing your secure follow-up ticket(?:\\.{1,3}|…)?\\s*)+/iu', ' ', $handoff_issue);
            $handoff_issue = preg_replace('/(?:\\s*Five minutes have passed\\.?\\s*)+/iu', ' ', (string) $handoff_issue);
            $handoff_issue = preg_replace('/(?:\\s*Preparing your secure follow-up ticket(?:\\.{1,3}|…)?\\s*)+/iu', ' ', (string) $handoff_issue);
            $handoff_issue = trim((string) $handoff_issue);
            if (mb_strlen($handoff_issue) < 100) {
                foreach ((array) $rows as $issue_row) {
                    $candidate = trim(wp_strip_all_tags((string) ($issue_row->content ?? '')));
                    $candidate = preg_replace('/(?:\\s*Five minutes have passed\\.?\\s*Preparing your secure follow-up ticket(?:\\.{1,3}|…)?\\s*)+/iu', ' ', $candidate);
                    $candidate = preg_replace('/(?:\\s*Five minutes have passed\\.?\\s*)+/iu', ' ', (string) $candidate);
                    $candidate = preg_replace('/(?:\\s*Preparing your secure follow-up ticket(?:\\.{1,3}|…)?\\s*)+/iu', ' ', (string) $candidate);
                    $candidate = trim((string) $candidate);
                    if (($issue_row->sender_type ?? '') === 'visitor' && mb_strlen($candidate) >= 100) {
                        $handoff_issue = $candidate;
                        break;
                    }
                }
            }
            $issue_key = preg_replace('/\\s+/u', ' ', mb_strtolower($handoff_issue));
            $issue_found = false;
            if ($handoff_issue !== '') {
                foreach ($messages as $existing_message) {
                    if (($existing_message['sender_type'] ?? '') === 'visitor' && preg_replace('/\\s+/u', ' ', mb_strtolower(trim(wp_strip_all_tags((string) ($existing_message['content'] ?? ''))))) === $issue_key) {
                        $issue_found = true;
                        break;
                    }
                }
                if (!$issue_found) {
                    $messages[] = array(
                        'id' => 0,
                        'message_key' => 'hcissue:' . $id,
                        'source_id' => 0,
                        'sender_type' => 'visitor',
                        'sender_name' => (string) ($ticket->visitor_name ?: 'Customer'),
                        'content' => $handoff_issue,
                        'created_at' => (string) $ticket->created_at,
                    );
                }
            }
        }
        if ($after === 0) {
            usort($messages, function($a,$b){$cmp=strcmp((string)($a['created_at']??''),(string)($b['created_at']??''));if($cmp!==0)return $cmp;return (($a['message_key']??'') <=> ($b['message_key']??''));});
        }
        $last_delivered_id = $after;
        foreach ((array) $rows as $delivered_row) $last_delivered_id = max($last_delivered_id, (int)($delivered_row->id ?? 0));
        $agent = $ticket->assigned_agent_id ? get_userdata((int) $ticket->assigned_agent_id) : false;
        $hck_ticket_id = 0;
        $hck_ticket_number = '';
        $hck_row = null;
        if ($ticket->status === 'converted' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix.'hck_hc_links')) === $wpdb->prefix.'hck_hc_links') {
            $hck_ticket_id = (int)$wpdb->get_var($wpdb->prepare('SELECT hck_ticket_id FROM '.$wpdb->prefix.'hck_hc_links WHERE hc_ticket_id=%d', $id));
            $hck_row = ($hck_ticket_id && function_exists('hck_get_ticket')) ? hck_get_ticket($hck_ticket_id) : null;
            $hck_ticket_number = ($hck_row && function_exists('hck_get_ticket_number')) ? hck_get_ticket_number($hck_row) : '';
        }
        $guest_access_url='';
        if ($hck_ticket_id && (int)$ticket->user_id===0 && class_exists('HCK_HelpCenter_Integration')) { $guest_access_url=HCK_HelpCenter_Integration::guest_link_for_chat((int)$id,(int)$hck_ticket_id,(string)$ticket->visitor_email,(string)$ticket->visitor_name); }
        wp_send_json_success(array(
            'ticket_id' => $id,
            'hck_ticket_id' => $hck_ticket_id,
            'hck_ticket_number' => $hck_ticket_number,
            'auto_created' => ($hck_row && ($hck_row->source??'')==='helpcenter_timeout'),
            'ticket_portal_url' => ($hck_ticket_id && (int)$ticket->user_id > 0) ? home_url('/ticket/view/'.$hck_ticket_id.'/') : '',
            'guest_access_url' => $guest_access_url,
            'guest_access_pending' => ($ticket->status === 'converted' && (int)$ticket->user_id === 0),
            'guest_contact_email' => ($ticket->status === 'converted' && (int)$ticket->user_id === 0) ? (string)$ticket->visitor_email : '',
            'status' => (string) $ticket->status,
            'visitor_name' => (string) $ticket->visitor_name,
            'visitor_email' => (string) $ticket->visitor_email,
            'agent_name' => $agent ? ($agent->display_name ?: $ticket->assigned_agent_name) : (string) $ticket->assigned_agent_name,
            'agent_id' => (int) $ticket->assigned_agent_id,
            'messages' => $messages,
            'last_message_id' => (int) $last_delivered_id,
            'wait_minutes' => 5,
            'queue_started_at' => (string) ($ticket->queue_started_at ?: $ticket->created_at),
            'wait_seconds_remaining' => (!empty($ticket->assigned_agent_id) || !empty($ticket->accepted_at) || !empty($ticket->agent_connected_at) || !in_array($ticket->status,array('queued','active','waiting_agent','waiting_customer'),true)) ? 0 : max(0, (self::mysql_timestamp($ticket->queue_started_at ?: $ticket->created_at) + 5 * MINUTE_IN_SECONDS) - time()),
            'agent_assigned' => (!empty($ticket->assigned_agent_id) || !empty($ticket->accepted_at)),
            'max_wait_messages' => 3,
            'queued_extra_messages' => (int) $ticket->queued_extra_messages,
            'agent_typing' => self::is_recent($ticket->agent_typing_at ?? '', 5),
            'agent_online' => self::agent_is_online((int)$ticket->assigned_agent_id),
            'agent_joined' => !empty($ticket->agent_connected_at),
            'agent_connected' => !empty($ticket->agent_connected_at),
            'no_agents_online' => !self::online_agent_ids(),
            'email_mode' => $ticket->status === 'pending_email',
            'converted' => $ticket->status === 'converted',
        ));
    }

    private static function send_json_success_fast($data, $started_at = 0) {
        if (!function_exists('fastcgi_finish_request')) wp_send_json_success($data);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            if ($started_at) header('Server-Timing: app;dur=' . number_format(max(0, (microtime(true) - $started_at) * 1000), 1, '.', ''));
        }
        echo wp_json_encode(array('success' => true, 'data' => $data));
        @fastcgi_finish_request();
        exit;
    }

    /**
     * Publish a visitor message to the assigned agent's private Ably channel
     * BEFORE the transcript write. The visitor session hash is the authority;
     * the endpoint never accepts an arbitrary ticket/session pairing.
     */
    public static function visitor_realtime_publish() {
        self::public_nonce();
        $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $hash = HC_AI_Platform::session_hash($session);
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $client_id = sanitize_text_field(wp_unslash($_POST['client_message_id'] ?? ''));
        $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
        if (!$hash || !$ticket_id || !$message || mb_strlen($message) > 5000 || !preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $client_id)) {
            wp_send_json_error(array('message' => 'Invalid realtime message request.'), 400);
        }
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id,session_hash,status,visitor_name FROM " . self::table() . " WHERE id=%d AND session_hash=%s LIMIT 1",
            $ticket_id, $hash
        ));
        if (!$ticket || !in_array((string)$ticket->status, array('queued','active','waiting_agent','waiting_customer'), true)) {
            wp_send_json_error(array('message' => 'This support conversation is no longer available.'), 403);
        }
        if (!class_exists('HC_Realtime') || !method_exists('HC_Realtime', 'publish_customer_message')) {
            wp_send_json_error(array('message' => 'Realtime publishing is unavailable.'), 503);
        }
        $payload = array(
            'ticket_id' => $ticket_id,
            'message' => array(
                'id' => 0,
                'client_message_id' => $client_id,
                'sender_type' => 'visitor',
                'sender_name' => sanitize_text_field((string)($ticket->visitor_name ?: 'Customer')),
                'content' => $message,
                'created_at' => current_time('mysql'),
            ),
        );
        if (!HC_Realtime::publish_customer_message($ticket_id, $payload)) {
            wp_send_json_error(array('message' => 'Realtime delivery could not be confirmed.'), 502);
        }
        wp_send_json_success(array('published' => true, 'client_message_id' => $client_id));
    }

    public static function visitor_send() {
        $send_started_at = microtime(true);
        self::public_nonce();
        global $wpdb;
        $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $hash = HC_AI_Platform::session_hash($session);
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
        $client_id = sanitize_text_field(wp_unslash($_POST['client_message_id'] ?? ''));
        $realtime_delivered = !empty($_POST['realtime_delivered']);
        if (!$hash || !$ticket_id || mb_strlen($message) < 1 || mb_strlen($message) > 5000) wp_send_json_error(array('message' => 'Enter a message of up to 5,000 characters.'));

        $ticket_table = self::table();
        $message_table = self::messages_table();
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ticket_table} WHERE id=%d AND session_hash=%s", $ticket_id, $hash));
        if (!$ticket || in_array($ticket->status, array('closed','converted'), true)) wp_send_json_error(array('message' => $ticket && $ticket->status === 'converted' ? 'This conversation has been converted. Continue in the HCK ticket portal.' : 'This support conversation is closed or unavailable.'), 403);
        $ticket = self::maybe_timeout($ticket);
        if (in_array($ticket->status, array('pending_email','converted','closed'), true)) wp_send_json_error(array('message' => $ticket->status === 'converted' ? 'This conversation is now read-only. Please continue in your HCK ticket portal.' : 'This conversation has moved to email follow-up or is closed.'), 403);

        // Unique client IDs make retries idempotent. Check before reserving one of
        // the three pre-agent messages so a duplicate retry does not consume quota.
        $existing_id = $client_id !== '' ? (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$message_table} WHERE ticket_id=%d AND client_message_id=%s LIMIT 1", $ticket_id, $client_id
        )) : 0;
        if ($existing_id) {
            // A retry is also a reconciliation opportunity: re-emit the durable
            // event so a recipient that missed the original live publish can catch up.
            if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'message.created', $existing_id);
            $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$message_table} WHERE id=%d", $existing_id));
            $message_data = $existing_row ? self::format_message($existing_row, $session) : null;
            wp_send_json_success(array('message_id'=>$existing_id,'message_data'=>$message_data,'message'=>'Message was already saved.','duplicate'=>true));
        }

        $prejoin = in_array($ticket->status, array('queued','active','waiting_agent','waiting_customer'), true) && empty($ticket->agent_connected_at);
        $reserved_wait_slot = false;
        if ($prejoin) {
            // Atomic quota reservation prevents simultaneous requests from all
            // passing a stale queued_extra_messages value.
            $reserved = $wpdb->query($wpdb->prepare(
                "UPDATE {$ticket_table} SET queued_extra_messages=queued_extra_messages+1 WHERE id=%d AND status IN ('queued','active','waiting_agent','waiting_customer') AND agent_connected_at IS NULL AND queued_extra_messages<3",
                $ticket_id
            ));
            if ($reserved === 1) {
                $reserved_wait_slot = true;
            } else {
                $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ticket_table} WHERE id=%d AND session_hash=%s", $ticket_id, $hash));
                if (!$ticket || in_array($ticket->status, array('closed','converted','pending_email'), true)) wp_send_json_error(array('message'=>'This support conversation is closed or unavailable.'),403);
                $prejoin = in_array($ticket->status, array('queued','active','waiting_agent','waiting_customer'), true) && empty($ticket->agent_connected_at);
                if ($prejoin && (int)$ticket->queued_extra_messages >= 3) wp_send_json_error(array('message'=>'You have used all three messages allowed before an agent connects. Please wait for a support specialist.'),429);
            }
        }

        $insert_duplicate = false;
        $message_id = self::insert_message(
            $ticket_id, (int)$ticket->conversation_id, 'visitor', (int)$ticket->user_id,
            (string)$ticket->visitor_name, $message, $client_id, $insert_duplicate,
            'not_required', false, false
        );
        if (!$message_id) {
            if ($reserved_wait_slot) $wpdb->query($wpdb->prepare("UPDATE {$ticket_table} SET queued_extra_messages=GREATEST(queued_extra_messages-1,0) WHERE id=%d",$ticket_id));
            wp_send_json_error(array('message' => 'Your message could not be saved. Please try again.'), 500);
        }
        if ($insert_duplicate) {
            if ($reserved_wait_slot) $wpdb->query($wpdb->prepare("UPDATE {$ticket_table} SET queued_extra_messages=GREATEST(queued_extra_messages-1,0) WHERE id=%d",$ticket_id));
            if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'message.created', $message_id);
            $message_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$message_table} WHERE id=%d", $message_id));
            $message_data = $message_row ? self::format_message($message_row, $session) : null;
            wp_send_json_success(array('message_id'=>$message_id,'message_data'=>$message_data,'message'=>'Message was already saved.','duplicate'=>true));
        }

        $now = current_time('mysql');
        // One atomic ticket-row update per new message. GREATEST protects the
        // latest-message pointer when customer and agent requests overlap.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$ticket_table} SET status=CASE WHEN last_message_id<=%d AND status NOT IN ('closed','converted','pending_email') THEN IF(agent_connected_at IS NULL,status,'waiting_agent') ELSE status END, last_message_id=GREATEST(last_message_id,%d), last_activity=GREATEST(COALESCE(last_activity,%s),%s), last_visitor_seen_at=GREATEST(COALESCE(last_visitor_seen_at,%s),%s), visitor_typing_at=NULL WHERE id=%d",
            $message_id, $message_id, $now, $now, $now, $now, $ticket_id
        ));
        // Always emit the durable server-side event after persistence, even when
        // the browser already published an optimistic Ably event. The agent client
        // deduplicates by client_message_id and upgrades its temporary bubble with
        // the committed database ID. This also recovers messages sent during an
        // agent/customer reconnect, when the early Ably event may have been missed.
        if (class_exists('HC_Realtime')) {
            HC_Realtime::publish_ticket_event($ticket_id, 'message.created', $message_id);
        }
        $message_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$message_table} WHERE id=%d", $message_id));
        $message_data = $message_row ? self::format_message($message_row, $session) : array(
            'id'=>$message_id,'client_message_id'=>$client_id,'sender_type'=>'visitor','sender_user_id'=>(int)$ticket->user_id,
            'sender_name'=>(string)$ticket->visitor_name,'content'=>$message,'created_at'=>$now,'avatar_url'=>''
        );
        self::send_json_success_fast(array('message_id'=>$message_id,'message_data'=>$message_data,'message'=>'Message saved.','realtime_delivered'=>(bool)$realtime_delivered), $send_started_at);
    }

    public static function admin_poll() {
        self::admin_nonce();
        global $wpdb;
        $table = self::table();
        self::retry_due_email_messages();
        // Queue distribution is triggered by an agent becoming available, not by admin polling.
        $queued_to_check = $wpdb->get_results("SELECT * FROM {$table} WHERE status='pending_email' OR status='queued' OR (status IN ('active','waiting_agent') AND agent_connected_at IS NULL) ORDER BY created_at ASC LIMIT 40");
        foreach ((array)$queued_to_check as $queued_ticket) self::maybe_timeout($queued_ticket);
        $message_table = self::messages_table();
        $ticket_select = "SELECT t.*, (SELECT COUNT(*) FROM {$message_table} m WHERE m.ticket_id=t.id AND m.sender_type='visitor' AND m.read_by_agent_at IS NULL) AS unread_count, (SELECT sender_type FROM {$message_table} lm WHERE lm.ticket_id=t.id ORDER BY lm.id DESC LIMIT 1) AS last_sender_type, (SELECT content FROM {$message_table} lm2 WHERE lm2.ticket_id=t.id ORDER BY lm2.id DESC LIMIT 1) AS last_message_preview FROM {$table} t";
        // Keep archived tickets independently retrievable even when the active inbox is busy.
        $rows = $wpdb->get_results($ticket_select . " WHERE t.status<>'archived' ORDER BY CASE WHEN t.status IN ('queued','pending_email') THEN 0 WHEN t.status NOT IN ('closed') THEN 1 ELSE 2 END, CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END ASC, CASE WHEN t.status IN ('queued','pending_email') THEN t.created_at ELSE t.last_activity END ASC, t.id ASC LIMIT 150");
        $archived_rows = $wpdb->get_results($ticket_select . " WHERE t.status='archived' ORDER BY t.archived_at DESC,t.id DESC LIMIT 150");
        $rows = array_merge((array)$rows, (array)$archived_rows);
        $tickets = array();
        foreach ((array) $rows as $ticket) {
            $agent = $ticket->assigned_agent_id ? get_userdata((int) $ticket->assigned_agent_id) : false;
            $tickets[] = array(
                'id' => (int) $ticket->id,
                'visitor_name' => (string) $ticket->visitor_name,
                'visitor_email' => (string) $ticket->visitor_email,
                'issue' => (string) $ticket->issue,
                'ai_summary' => (string) ($ticket->ai_summary ?? ''),
                'ai_summary_at' => (string) ($ticket->ai_summary_at ?? ''),
                'visitor_avatar' => get_avatar_url((int)$ticket->user_id, array('size'=>64)),
                'agent_avatar' => $agent ? get_avatar_url((int)$agent->ID, array('size'=>64)) : '',
                'status' => (string) $ticket->status,
                'priority' => (string) ($ticket->priority ?? 'normal'),
                'created_at' => (string) $ticket->created_at,
                'queue_started_at' => (string) ($ticket->queue_started_at ?: $ticket->created_at),
                'last_activity' => (string) $ticket->last_activity,
                'assigned_agent_id' => (int) $ticket->assigned_agent_id,
                'assigned_agent_name' => $agent ? ($agent->display_name ?: $ticket->assigned_agent_name) : (string) $ticket->assigned_agent_name,
                'closed_by_name' => !empty($ticket->closed_by_agent_id) && get_userdata((int)$ticket->closed_by_agent_id) ? (get_userdata((int)$ticket->closed_by_agent_id)->display_name . ' (' . get_userdata((int)$ticket->closed_by_agent_id)->user_email . ')') : '',
                'last_message_id' => (int) $ticket->last_message_id,
                'unread_count' => (int) $ticket->unread_count,
                'last_sender_type' => (string) $ticket->last_sender_type,
                'last_message_preview' => mb_substr((string) $ticket->last_message_preview, 0, 140),
            );
        }
        $counts = array(
            'queued' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','pending_email')"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('active','waiting_agent','waiting_customer')"),
            'mine' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE assigned_agent_id=%d AND status NOT IN ('closed','archived')", get_current_user_id())),
            'unread' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::messages_table() . " m INNER JOIN {$table} t ON t.id=m.ticket_id WHERE m.sender_type='visitor' AND m.read_by_agent_at IS NULL AND t.status NOT IN ('closed','archived')"),
            'closed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='closed'"),
            'archived' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='archived'"),
            'active_pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','active','waiting_agent','waiting_customer','pending_email')"),
            'online_agents' => count(self::online_agent_ids()),
        );
        wp_send_json_success(array('tickets' => $tickets, 'counts' => $counts, 'server_time' => current_time('mysql')));
    }

    public static function admin_ticket() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket) wp_send_json_error(array('message' => 'Ticket not found.'), 404);
        $ticket = self::maybe_timeout($ticket);
        $wpdb->query($wpdb->prepare("UPDATE " . self::messages_table() . " SET read_by_agent_at=%s WHERE ticket_id=%d AND sender_type='visitor' AND read_by_agent_at IS NULL", current_time('mysql'), $id));
        if ((int) $ticket->assigned_agent_id === get_current_user_id()) { $seen_now = current_time('mysql'); $wpdb->update(self::table(), array('last_agent_seen_at'=>$seen_now,'agent_connected_at'=>$seen_now), array('id'=>$id), array('%s','%s'), array('%d')); $ticket->last_agent_seen_at = $seen_now; $ticket->agent_connected_at = $seen_now; }
        $after = absint($_POST['after_id'] ?? 0);
        $ai_after = absint($_POST['ai_after_id'] ?? 0);
        if ($after > 0) {
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::messages_table() . " WHERE ticket_id=%d AND id>%d ORDER BY id ASC LIMIT 100",
                $id, $after
            ));
        } else {
            // Load the complete human-support transcript on first open; subsequent polls are incremental.
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::messages_table() . " WHERE ticket_id=%d ORDER BY id ASC",
                $id
            ));
        }
        $context = array();
        $context = self::get_ticket_ai_history($ticket, $ai_after, 1000);
        $last_ai_id = $ai_after;
        foreach ((array)$context as $context_row) $last_ai_id = max($last_ai_id, (int)($context_row['id'] ?? 0));
        $related = array();
        if (!$after && (int) $ticket->user_id) {
            $related = $wpdb->get_results($wpdb->prepare("SELECT id,visitor_name,status,created_at,last_activity FROM " . self::table() . " WHERE user_id=%d AND id<>%d ORDER BY last_activity DESC LIMIT 10", (int) $ticket->user_id, $id), ARRAY_A);
        } elseif (!$after && !empty($ticket->session_hash)) {
            $related = $wpdb->get_results($wpdb->prepare("SELECT id,visitor_name,status,created_at,last_activity FROM " . self::table() . " WHERE session_hash=%s AND id<>%d ORDER BY last_activity DESC LIMIT 10", $ticket->session_hash, $id), ARRAY_A);
        }
        $agent = $ticket->assigned_agent_id ? get_userdata((int) $ticket->assigned_agent_id) : false;
        $hck_link = null;
        if (function_exists('hck_get_ticket') && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix.'hck_hc_links')) === $wpdb->prefix.'hck_hc_links') {
            $hck_link = $wpdb->get_row($wpdb->prepare('SELECT hck_ticket_id FROM '.$wpdb->prefix.'hck_hc_links WHERE hc_ticket_id=%d', $id));
        }
        $hck_ticket_id = $hck_link ? (int)$hck_link->hck_ticket_id : 0;
        $hck_ticket = ($hck_ticket_id && function_exists('hck_get_ticket')) ? hck_get_ticket($hck_ticket_id) : null;
        $agents = array();
        if (self::can_manage() && !$after) {
            $users = get_users(array('capability' => 'hc_manage_ai_escalations', 'fields' => array('ID', 'display_name', 'user_login', 'user_email'), 'number' => 100, 'orderby' => 'display_name', 'order' => 'ASC'));
            foreach ((array) $users as $support_user) {
                $agents[] = array('id' => (int) $support_user->ID, 'name' => (string) ($support_user->display_name ?: $support_user->user_login), 'email' => (string) $support_user->user_email, 'online' => self::agent_is_online((int)$support_user->ID));
            }
        }
        wp_send_json_success(array(
            'ticket' => array(
                'id' => (int) $ticket->id,
                'hck_ticket_id' => $hck_ticket_id,
                'hck_ticket_url' => $hck_ticket_id ? (current_user_can('manage_options') ? home_url('/ticket/admin/view/'.$hck_ticket_id.'/') : home_url('/ticket/admin/view/'.$hck_ticket_id.'/')) : '',
                'hck_ticket_number' => ($hck_ticket && function_exists('hck_get_ticket_number')) ? hck_get_ticket_number($hck_ticket) : '',
                'conversation_id' => (int) $ticket->conversation_id,
                'visitor_name' => (string) $ticket->visitor_name,
                'visitor_email' => (string) $ticket->visitor_email,
                'user_id' => (int) $ticket->user_id,
                'status' => (string) $ticket->status,
                'priority' => (string) ($ticket->priority ?? 'normal'),
                'assigned_agent_id' => (int) $ticket->assigned_agent_id,
                'assigned_agent_name' => $agent ? ($agent->display_name ?: $ticket->assigned_agent_name) : (string) $ticket->assigned_agent_name,
                'closed_by_name' => !empty($ticket->closed_by_agent_id) && get_userdata((int)$ticket->closed_by_agent_id) ? (get_userdata((int)$ticket->closed_by_agent_id)->display_name . ' (' . get_userdata((int)$ticket->closed_by_agent_id)->user_email . ')') : '',
                'created_at' => (string) $ticket->created_at,
                'last_activity' => (string) $ticket->last_activity,
                'issue' => (string) $ticket->issue,
                'ai_summary' => (string) ($ticket->ai_summary ?? ''),
                'ai_summary_at' => (string) ($ticket->ai_summary_at ?? ''),
                'visitor_avatar' => get_avatar_url((int)$ticket->user_id, array('size'=>64)),
                'agent_avatar' => $agent ? get_avatar_url((int)$agent->ID, array('size'=>64)) : '',
                'visitor_online' => self::is_recent($ticket->last_visitor_seen_at ?? '', 25),
                'visitor_typing' => self::is_recent($ticket->visitor_typing_at ?? '', 5),
                'agent_online' => self::agent_is_online((int)$ticket->assigned_agent_id),
            'agent_joined' => !empty($ticket->agent_connected_at),
            'agent_connected' => !empty($ticket->agent_connected_at),
            'no_agents_online' => !self::online_agent_ids(),
            'email_mode' => $ticket->status === 'pending_email',
                'agent_typing' => self::is_recent($ticket->agent_typing_at ?? '', 5),
            ),
            'messages' => array_map(function($row) { return self::format_message($row); }, (array) $messages),
            'context' => array_map(function($row) { return array('id'=>(int)($row['id']??0),'message_key'=>'ai:'.(int)($row['id']??0),'role' => $row['role'], 'content' => $row['content'], 'created_at' => $row['created_at']); }, (array) $context),
            'last_ai_id' => (int) $last_ai_id,
            'related' => array_map(function($row) { return array('id' => (int) $row['id'], 'visitor_name' => $row['visitor_name'], 'status' => $row['status'], 'created_at' => $row['created_at'], 'last_activity' => $row['last_activity']); }, (array) $related),
            'current_user_id' => get_current_user_id(),
            'agents' => $agents,
        ));
    }

    public static function claim_ticket() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $user = wp_get_current_user();
        if (!$id || !$user || !$user->exists()) wp_send_json_error(array('message' => 'Ticket or agent account is invalid.'));
        if (!in_array((int)$user->ID, self::eligible_agent_ids(), true)) wp_send_json_error(array('message' => 'Your account is not enabled as a Human Support agent. Ask a supervisor to enable it in AI settings.'), 403);
        if (!self::agent_is_online((int)$user->ID)) wp_send_json_error(array('message'=>'Set your Human Support status to Available before claiming a ticket.'),409);
        if (self::agent_in_cooldown((int)$user->ID)) wp_send_json_error(array('message'=>'Your post-resolution cooldown is still active. You can receive another ticket when it expires.'),409);
        if (!self::agent_is_online((int)$user->ID)) wp_send_json_error(array('message'=>'Set your Human Support status to Available before claiming a ticket.'),409);
        if (self::agent_in_cooldown((int)$user->ID)) wp_send_json_error(array('message'=>'Your post-resolution cooldown is still active. You can receive another ticket when it expires.'),409);
        $table = self::table();
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
        if (!$ticket || in_array($ticket->status, array('closed','converted'), true)) wp_send_json_error(array('message' => 'This ticket is closed or has been converted to HCK.'));
        $ticket = self::maybe_timeout($ticket);
        if ((int) $ticket->assigned_agent_id && (int) $ticket->assigned_agent_id !== get_current_user_id()) {
            wp_send_json_error(array('message' => 'This ticket is already assigned to ' . $ticket->assigned_agent_name . '. Ask a supervisor to reassign it.'));
        }
        if (!(int) $ticket->assigned_agent_id) {
            $agent_load = self::agent_active_count((int)$user->ID);
            $agent_capacity = self::agent_capacity((int)$user->ID);
            if ($agent_load >= $agent_capacity) wp_send_json_error(array('message' => 'You have reached your configured active-ticket limit (' . $agent_capacity . '). Finish or transfer a ticket before claiming another.'), 409);
            $now = current_time('mysql');
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET assigned_agent_id=%d,assigned_agent_name=%s,status=IF(status='pending_email','pending_email','active'),accepted_at=%s,last_activity=%s,last_agent_seen_at=%s WHERE id=%d AND assigned_agent_id=0 AND status<>'closed'",
                get_current_user_id(), $user->display_name ?: $user->user_login, $now, $now, $now, $id
            ));
            if (!$updated) wp_send_json_error(array('message' => 'Another support agent claimed this ticket first. Refresh the queue.'));
            wp_clear_scheduled_hook('hc_ai_escalation_timeout', array((int)$id));
            self::insert_message($id, (int) $ticket->conversation_id, 'system', 0, 'System', ($user->display_name ?: $user->user_login) . ($ticket->status === 'pending_email' ? ' accepted the legacy email follow-up ticket.' : ' has been assigned to your conversation and will join shortly.'));
        }
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.claimed', 0); wp_send_json_success(array('message' => 'Ticket assigned to you. Human support now owns this conversation.'));
    }

    public static function transfer_ticket() {
        self::admin_nonce();

        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $target_id = absint($_POST['agent_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket || in_array($ticket->status, array('closed','converted'), true)) wp_send_json_error(array('message' => 'This ticket is closed, converted, or unavailable.'), 404);
        $is_owner = (int)$ticket->assigned_agent_id === get_current_user_id();
        if (!$is_owner && !current_user_can('manage_options')) wp_send_json_error(array('message'=>'Only the assigned agent or a supervisor can transfer this ticket.'),403);
        $target = $target_id ? get_userdata($target_id) : false;
        if ($target_id && (!$target || !in_array($target_id, self::eligible_agent_ids(), true))) {
            wp_send_json_error(array('message' => 'Choose an enabled Human Support agent.'), 400);
        }
        if ($target_id && !self::agent_is_online($target_id)) {
            wp_send_json_error(array('message' => 'That agent is currently offline. Choose an online agent so the visitor is not left waiting.'), 409);
        }
        if ($target_id && $target_id !== (int)$ticket->assigned_agent_id && self::agent_in_cooldown($target_id)) wp_send_json_error(array('message'=>'That agent is still in the post-resolution cooldown period.'),409);
        if ($target_id && $target_id !== (int)$ticket->assigned_agent_id && self::agent_active_count($target_id) >= self::agent_capacity($target_id)) {
            wp_send_json_error(array('message' => 'That agent has reached their active-ticket capacity. Choose another agent.'), 409);
        }
        $old_name = $ticket->assigned_agent_name ?: 'Unassigned';
        $new_name = $target ? ($target->display_name ?: $target->user_login) : 'Unassigned';
        $now = current_time('mysql');
        $new_status = $ticket->status === 'pending_email' ? 'pending_email' : ($target ? 'active' : 'queued');
        $updated = $wpdb->update(self::table(), array(
            'assigned_agent_id' => $target ? (int) $target->ID : 0,
            'assigned_agent_name' => $new_name,
            'status' => $new_status,
            'agent_typing_at' => null,
            'last_agent_seen_at' => null,
            'accepted_at' => $target ? $now : $ticket->accepted_at,
            'last_activity' => $now,
            'queue_started_at' => (string) ($ticket->queue_started_at ?: $ticket->created_at),
        ), array('id' => $id), array('%d','%s','%s','%s','%s','%s','%s','%s'), array('%d'));
        if ($updated === false) wp_send_json_error(array('message' => 'The ticket could not be reassigned. Please try again.'), 500);
        if($target) wp_clear_scheduled_hook('hc_ai_escalation_timeout',array((int)$id));
        self::insert_message($id, (int) $ticket->conversation_id, 'system', 0, 'System', 'Ticket ownership changed from ' . $old_name . ' to ' . $new_name . '.');
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.transferred', 0); wp_send_json_success(array('message' => 'Ticket reassigned to ' . $new_name . '.'));
    }

    public static function admin_send() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? ($_POST['reply'] ?? ''))));
        $client_id = sanitize_text_field(wp_unslash($_POST['client_message_id'] ?? ''));
        if (!$id || mb_strlen($message) < 1 || mb_strlen($message) > 5000) wp_send_json_error(array('message' => 'Enter a reply of up to 5,000 characters.'));
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket || in_array($ticket->status, array('closed','converted'), true)) wp_send_json_error(array('message' => 'This ticket is closed or has been converted to HCK.'));
        $ticket = self::maybe_timeout($ticket);
        if ($ticket->status === 'closed') wp_send_json_error(array('message' => 'This ticket is closed.'));
        if ((int) $ticket->assigned_agent_id !== get_current_user_id()) wp_send_json_error(array('message' => 'Claim this ticket before replying. Only the assigned agent can send replies.'));
        $user = wp_get_current_user();
        $email_mode = $ticket->status === 'pending_email';
        $insert_duplicate = false;
        $realtime_delivered = !empty($_POST['realtime_delivered']);
        $message_id = self::insert_message($id, (int) $ticket->conversation_id, 'agent', get_current_user_id(), $user->display_name ?: $user->user_login, $message, $client_id, $insert_duplicate, $email_mode ? 'pending' : 'not_required', false, false);
        if (!$message_id) wp_send_json_error(array('message' => 'Reply could not be saved. Please try again.'), 500);
        // A retry of a previously inserted message must not repeat ticket updates,
        // realtime fan-out, or email scheduling.
        if ($insert_duplicate) {
            // A direct Ably publish ACK proves only that Ably accepted the event;
            // it does not prove the customer was subscribed when it was sent.
            // Re-emit the committed row on retries so reconnecting customers can
            // reconcile immediately. Clients deduplicate by message/client ID.
            if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($id, 'message.created', $message_id);
            $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $message_id));
            wp_send_json_success(array('message'=>'Reply was already saved.','message_id'=>$message_id,'message_data'=>$existing_row ? self::format_message($existing_row) : null,'duplicate'=>true));
        }
        $now = current_time('mysql');
        $next_status = $email_mode ? 'pending_email' : 'waiting_customer';
        $wpdb->query($wpdb->prepare(
            "UPDATE ".self::table()." SET admin_reply=IF(last_message_id<=%d,%s,admin_reply),status=IF(last_message_id<=%d,%s,status),replied_at=IF(last_message_id<=%d,%s,replied_at),last_activity=GREATEST(COALESCE(last_activity,%s),%s),last_message_id=GREATEST(last_message_id,%d),agent_typing_at=NULL,last_agent_seen_at=GREATEST(COALESCE(last_agent_seen_at,%s),%s),agent_connected_at=GREATEST(COALESCE(agent_connected_at,%s),%s) WHERE id=%d",
            $message_id,$message,$message_id,$next_status,$message_id,$now,$now,$now,$message_id,$now,$now,$now,$now,$id
        ));
        // Always publish a durable post-save reconciliation event. A successful
        // browser publish ACK is not proof that a recipient received the event;
        // the recipient may have refreshed or been reconnecting at that moment.
        // The UI deduplicates the direct and committed copies by client_message_id.
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($id, 'message.created', $message_id);
        $email_sent = null;
        if ($email_mode) {
            $email_state = (string)$wpdb->get_var($wpdb->prepare("SELECT email_status FROM " . self::messages_table() . " WHERE id=%d", $message_id));
            if ($email_state === 'sent') $email_sent = true;
            elseif (in_array($email_state, array('pending','failed','sending'), true)) wp_schedule_single_event(time(), 'hc_ai_support_retry_message_email', array((int)$message_id));
        }
        $message_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $message_id));
        wp_send_json_success(array('message' => $email_mode ? ($email_sent === true ? 'Email follow-up already delivered.' : 'Reply saved in the ticket. Email delivery has been queued and will retry automatically if needed.') : 'Reply sent in the live conversation.', 'message_id' => $message_id, 'message_data' => $message_row ? self::format_message($message_row) : null, 'email_sent' => $email_sent, 'email_mode' => $email_mode));
    }


    public static function set_ticket_priority() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $priority = sanitize_key(wp_unslash($_POST['priority'] ?? 'normal'));
        if (!in_array($priority, array('low','normal','high','urgent'), true)) wp_send_json_error(array('message'=>'Choose a valid ticket priority.'));
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket || in_array($ticket->status,array('closed','archived','converted'),true)) wp_send_json_error(array('message'=>'This HelpCenter conversation is no longer editable.'),404);
        if ((int)$ticket->assigned_agent_id !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(array('message'=>'Only the assigned agent or a supervisor can change priority.'),403);
        $wpdb->update(self::table(), array('priority'=>$priority,'last_activity'=>current_time('mysql')), array('id'=>$id), array('%s','%s'), array('%d'));
        self::insert_message($id,(int)$ticket->conversation_id,'system',0,'System','Ticket priority changed to '.ucfirst($priority).'.');
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.priority', 0); wp_send_json_success(array('priority'=>$priority,'message'=>'Ticket priority updated.'));
    }

    public static function admin_send_note() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
        $client_id = sanitize_text_field(wp_unslash($_POST['client_message_id'] ?? ''));
        if (!$id || mb_strlen($message) < 1 || mb_strlen($message) > 5000) wp_send_json_error(array('message'=>'Enter an internal note of up to 5,000 characters.'));
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket || in_array($ticket->status, array('closed','archived','converted'), true)) wp_send_json_error(array('message'=>'This ticket is not active or has been converted to HCK.'));
        if ((int)$ticket->assigned_agent_id !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(array('message'=>'Only the assigned agent or a supervisor can add an internal note.'),403);
        $user = wp_get_current_user();
        $duplicate = false;
        $message_id = self::insert_message($id, (int)$ticket->conversation_id, 'internal_note', get_current_user_id(), $user->display_name ?: $user->user_login, $message, $client_id, $duplicate);
        if (!$message_id) wp_send_json_error(array('message'=>'Internal note could not be saved.'),500);
        $now = current_time('mysql');
        $wpdb->update(self::table(), array('last_activity'=>$now,'last_agent_seen_at'=>$now,'agent_connected_at'=>$now), array('id'=>$id), array('%s','%s','%s'), array('%d'));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $message_id));
        wp_send_json_success(array('message'=>'Internal note saved. It is visible only to support staff.','message_id'=>$message_id,'message_data'=>$row ? self::format_message($row) : null));
    }

    public static function generate_ticket_summary() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket) wp_send_json_error(array('message'=>'Ticket not found.'),404);
        // AI summaries are available to authorized support staff whenever the AI provider is configured.
        if (!class_exists('HC_AI') || !method_exists('HC_AI','chat_model')) wp_send_json_error(array('message'=>'The configured AI chat provider is not available.'),503);
        $events = array();
        foreach (self::get_ticket_ai_history($ticket, 0, 1500) as $row) {
            $events[] = array('at'=>(string)$row['created_at'],'text'=>(($row['role']==='user'?'Customer':'AI assistant').': '.wp_strip_all_tags((string)$row['content'])));
        }
        $human_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sender_type,sender_name,content,created_at FROM " . self::messages_table() . " WHERE ticket_id=%d AND sender_type<>'internal_note' ORDER BY id ASC LIMIT 1000",
            $id
        ));
        foreach ((array)$human_rows as $row) {
            $label = $row->sender_type === 'visitor' ? 'Customer' : ($row->sender_type === 'system' ? 'System' : 'Support agent ' . $row->sender_name);
            $events[] = array('at'=>(string)$row->created_at,'text'=>$label.': '.wp_strip_all_tags((string)$row->content));
        }
        usort($events, function($a,$b){ return strcmp($a['at'],$b['at']); });
        $history = array_slice(array_column($events,'text'), -180);
        $prompt = "Create a concise internal support-agent handover overview using ONLY the transcript below. Format the answer in clean Markdown with a short heading and 4-6 bullet points: customer issue, important facts/order references, what AI/support already tried or promised, unresolved questions, and recommended next step. Clearly identify missing information. Do not invent facts or claim an action is complete unless the transcript says so.\n\nTicket issue: " . (string)$ticket->issue . "\n\nTranscript:\n" . implode("\n", $history);
        try {
            $summary = HC_AI::chat_model(array(
                array('role'=>'system','content'=>'You are a precise customer-support case summarizer. Do not answer the customer. Produce an internal agent handover only. Follow the requested Markdown format. Summarize only verified transcript facts.'),
                array('role'=>'user','content'=>$prompt)
            ));
        } catch (Throwable $e) {
            error_log('[HelpCenter] Ticket summary exception for ticket '.$id.': '.$e->getMessage());
            wp_send_json_error(array('message'=>'Summary generation failed inside the AI provider. Check the HelpCenter PHP error log for the ticket summary exception.'),502);
        }
        if (is_wp_error($summary) || !is_string($summary) || trim($summary)==='') {
            $reason = is_wp_error($summary) ? $summary->get_error_message() : 'The AI provider returned an empty summary.';
            error_log('[HelpCenter] Ticket summary provider error for ticket '.$id.': '.$reason);
            wp_send_json_error(array('message'=>$reason),502);
        }
        $summary = mb_substr(trim($summary),0,8000);
        $now = current_time('mysql');
        $wpdb->update(self::table(), array('ai_summary'=>$summary,'ai_summary_at'=>$now), array('id'=>$id), array('%s','%s'), array('%d'));
        wp_send_json_success(array('summary'=>$summary,'generated_at'=>$now));
    }

    public static function force_logout_agent() {
        self::admin_nonce();
        if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'Only a supervisor can force logout an agent.'),403);
        global $wpdb;
        $agent_id = absint($_POST['agent_id'] ?? 0);
        if (!$agent_id || !in_array($agent_id, self::eligible_agent_ids(), true)) wp_send_json_error(array('message'=>'Choose an enabled support agent.'),400);
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::presence_table() . " (user_id,is_available,last_seen,force_logged_out) VALUES (%d,0,%s,1) ON DUPLICATE KEY UPDATE is_available=0,last_seen=VALUES(last_seen),force_logged_out=1", $agent_id, $now));
        if (function_exists('wp_destroy_all_sessions')) wp_destroy_all_sessions($agent_id);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,conversation_id,status FROM " . self::table() . " WHERE assigned_agent_id=%d AND status IN ('active','waiting_agent','waiting_customer','pending_email')", $agent_id));
        $user = get_userdata($agent_id); $name = $user ? ($user->display_name ?: $user->user_login) : 'Agent';
        foreach ((array)$rows as $row) {
            $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET assigned_agent_id=0,assigned_agent_name='',status=IF(status='pending_email','pending_email','queued'),queue_started_at=COALESCE(queue_started_at,created_at),agent_typing_at=NULL,last_agent_seen_at=NULL,last_activity=%s WHERE id=%d AND assigned_agent_id=%d", $now, (int)$row->id, $agent_id));
            self::insert_message((int)$row->id,(int)$row->conversation_id,'system',0,'System',$name.' was force-logged out by a supervisor. This conversation has returned to the support queue.');
        }
        self::auto_assign_waiting_tickets();
        wp_send_json_success(array('message'=>$name.' has been force-logged out. '.count($rows).' active ticket(s) were returned to the queue or reassigned.','tickets_returned'=>count($rows)));
    }

    public static function archive_ticket() {
        self::admin_nonce(); global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket) wp_send_json_error(array('message'=>'Ticket not found.'),404);
        $owner = (int)$ticket->assigned_agent_id === get_current_user_id();
        $supervisor = current_user_can('manage_options');
        if (!$owner && !$supervisor) wp_send_json_error(array('message'=>'Only the assigned agent or a supervisor can archive this ticket.'),403);
        if ($ticket->status !== 'closed') wp_send_json_error(array('message'=>'Close the ticket before archiving it. Active conversations cannot be archived.'),409);
        $now = current_time('mysql');
        $updated = $wpdb->update(self::table(), array('archived_from_status'=>'closed','archived_at'=>$now,'status'=>'archived','last_activity'=>$now), array('id'=>$id), array('%s','%s','%s','%s'), array('%d'));
        if ($updated === false) wp_send_json_error(array('message'=>'Could not archive this ticket.'),500);
        self::insert_message($id,(int)$ticket->conversation_id,'system',0,'System','Ticket archived by '.(wp_get_current_user()->display_name ?: 'support agent').'. The full transcript and attachments have been preserved.');
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.archived', 0); wp_send_json_success(array('message'=>'Ticket archived. Its transcript and attachments have been preserved.'));
    }

    public static function restore_ticket() {
        self::admin_nonce(); global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0); $target_id = absint($_POST['agent_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket || $ticket->status !== 'archived') wp_send_json_error(array('message'=>'Archived ticket not found.'),404);
        if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'Only a supervisor can restore archived tickets.'),403);
        $target = $target_id ? get_userdata($target_id) : false;
        if ($target_id && (!$target || !in_array($target_id,self::eligible_agent_ids(),true))) wp_send_json_error(array('message'=>'Choose an enabled support agent.'),400);
        if ($target_id && !self::agent_is_online($target_id)) wp_send_json_error(array('message'=>'The selected agent is currently offline.'),409);
        if ($target_id && self::agent_in_cooldown($target_id)) wp_send_json_error(array('message'=>'The selected agent is still in the post-resolution cooldown period.'),409);
        if ($target_id && self::agent_active_count($target_id) >= self::agent_capacity($target_id)) wp_send_json_error(array('message'=>'The selected agent has reached their ticket capacity.'),409);
        $now=current_time('mysql'); $name=$target?($target->display_name ?: $target->user_login):'';
        $updated=$wpdb->update(self::table(),array('status'=>$target?'active':'queued','assigned_agent_id'=>$target?(int)$target->ID:0,'assigned_agent_name'=>$name,'archived_from_status'=>'','archived_at'=>null,'queue_started_at'=>(string)($ticket->queue_started_at ?: $ticket->created_at),'accepted_at'=>$target?$now:$ticket->accepted_at,'last_agent_seen_at'=>null,'last_activity'=>$now),array('id'=>$id),array('%s','%d','%s','%s','%s','%s','%s','%s','%s'),array('%d'));
        if ($updated===false) wp_send_json_error(array('message'=>'Could not restore ticket.'),500);
        self::insert_message($id,(int)$ticket->conversation_id,'system',0,'System',$target?('Archived ticket restored and assigned to '.$name.'.'):('Archived ticket restored to the unassigned support queue.'));
        if (!$target) self::assign_available_agent($id);
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.restored', 0); wp_send_json_success(array('message'=>$target?'Ticket restored and assigned to '.$name.'.':'Ticket restored to the unassigned queue.'));
    }

    public static function close_ticket() {
        self::admin_nonce();
        global $wpdb;
        $id = absint($_POST['ticket_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id));
        if (!$ticket) wp_send_json_error(array('message' => 'Ticket not found.'), 404);
        if ($ticket->status === 'converted') wp_send_json_error(array('message'=>'This conversation has been converted to HCK and can no longer be closed here.'),409);
        if ($ticket->status === 'closed') wp_send_json_success(array('message' => 'Ticket is already closed.'));
        $is_supervisor = current_user_can('manage_options');
        if (!$is_supervisor && (!(int) $ticket->assigned_agent_id || (int) $ticket->assigned_agent_id !== get_current_user_id())) {
            wp_send_json_error(array('message' => 'Only the assigned support agent or a supervisor can close this ticket.'), 403);
        }
        $now = current_time('mysql');
        $user = wp_get_current_user();
        $wpdb->update(self::table(), array('status' => 'closed', 'closed_at' => $now, 'closed_by_agent_id' => get_current_user_id(), 'last_activity' => $now, 'visitor_typing_at' => null, 'agent_typing_at' => null), array('id' => $id), array('%s','%s','%d','%s','%s','%s'), array('%d'));
        $cooldown_minutes = max(0, min(240, absint(HC_Core::get_setting('hc_ai_support_assignment_interval_minutes', '7'))));
        if ((int)$ticket->assigned_agent_id > 0 && $cooldown_minutes > 0) {
            $cooldown_until = wp_date('Y-m-d H:i:s', time() + ($cooldown_minutes * MINUTE_IN_SECONDS));
            $wpdb->update(self::presence_table(), array('cooldown_until'=>$cooldown_until), array('user_id'=>(int)$ticket->assigned_agent_id), array('%s'), array('%d'));
        }
        // Assignment cooldown begins at resolution; another queued ticket may be assigned after it expires.
        self::insert_message($id, (int) $ticket->conversation_id, 'system', 0, 'System', 'Support ticket closed by ' . ($user->display_name ?: 'a support agent') . '. The AI assistant can now resume.');
        self::auto_assign_waiting_tickets();
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event((int)$id, 'ticket.closed', 0); wp_send_json_success(array('message' => 'Ticket closed. The AI assistant can resume in this conversation.'));
    }


    /** Private attachment storage lives inside HelpCenter, never in the WordPress Media Library. */
    private static function attachment_storage_dir($subdir = '') {
        $base = trailingslashit(dirname(__FILE__)) . 'storage/attachments';
        $dir = $subdir !== '' ? trailingslashit($base) . trim($subdir, '/\\') : $base;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return '';
        foreach (array(
            'index.php' => "<?php // HelpCenter private storage.\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>Require all denied</IfModule>\n<IfModule !mod_authz_core.c>Order deny,allow\nDeny from all\n</IfModule>\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><directoryBrowse enabled="false"/><security><authorization><clear/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>'
        ) as $name => $body) {
            $target = trailingslashit($base) . $name;
            if (!file_exists($target)) @file_put_contents($target, $body);
        }
        return $dir;
    }

    private static function attachment_absolute_path($stored) {
        $stored = basename((string)$stored);
        if ($stored === '' || $stored === '.' || $stored === '..') return false;
        $base = realpath(self::attachment_storage_dir());
        if ($base) {
            $path = realpath($base . DIRECTORY_SEPARATOR . $stored);
            if ($path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) return $path;
        }
        // Read legacy v85 files during migration; all new files use HelpCenter storage.
        $uploads = wp_upload_dir();
        $legacy = realpath(trailingslashit($uploads['basedir']) . 'helpcenter-private');
        if ($legacy) {
            $path = realpath($legacy . DIRECTORY_SEPARATOR . $stored);
            if ($path && strpos($path, $legacy . DIRECTORY_SEPARATOR) === 0 && is_file($path)) return $path;
        }
        return false;
    }

    public static function attachment_preview_url($ticket_id, $message_id, $session_id = '') {
        return self::signed_preview_url($ticket_id, $message_id, $session_id);
    }

    private static function signed_preview_url($ticket_id, $message_id, $session_id = '', $session_hash_override = '') {
        $expires = time() + HOUR_IN_SECONDS;
        $session_hash = $session_hash_override !== '' ? (string)$session_hash_override : ($session_id !== '' ? HC_AI_Platform::session_hash($session_id) : '');
        $viewer = $session_hash !== '' ? 'v:' . $session_hash : 'u:' . get_current_user_id();
        $payload = (int)$ticket_id . '|' . (int)$message_id . '|' . $expires . '|' . $viewer;
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        $args = array('action'=>'hc_ai_escalation_download','ticket_id'=>(int)$ticket_id,'message_id'=>(int)$message_id,'expires'=>$expires,'viewer'=>$viewer,'sig'=>$signature);
        return add_query_arg($args, admin_url('admin-ajax.php'));
    }

    private static function verify_preview_signature($ticket_id, $message_id) {
        $expires = absint($_GET['expires'] ?? 0);
        $viewer = sanitize_text_field(wp_unslash($_GET['viewer'] ?? ''));
        $sig = sanitize_text_field(wp_unslash($_GET['sig'] ?? ''));
        if (!$expires || $expires < time() || $expires > time() + (2 * HOUR_IN_SECONDS) || !$viewer || !preg_match('/^[a-f0-9]{64}$/', $sig)) return false;
        $payload = (int)$ticket_id . '|' . (int)$message_id . '|' . $expires . '|' . $viewer;
        if (!hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $sig)) return false;
        if (strpos($viewer, 'v:') === 0) {
            return preg_match('/^v:[a-f0-9]{32,64}$/', $viewer) === 1;
        }
        return strpos($viewer, 'u:') === 0 && is_user_logged_in() && (int)substr($viewer, 2) === get_current_user_id();
    }

    private static function staged_upload_context() {
        // The staged-upload endpoint is shared by the public chatbot and Agent Desk.
        // Do not infer an admin request from manage_options: a logged-in site owner can
        // also be a customer using the public widget, whose nonce is hc_ai_public.
        // Only the restricted Agent Desk uses the agent nonce; everyone else is a visitor.
        $is_agent = is_user_logged_in() && current_user_can('hc_agent_portal_access');
        $is_admin = $is_agent; // Existing ownership checks below use this as the agent flag.
        if ($is_agent) {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'hc_agent_portal')) wp_send_json_error(array('message'=>'Agent session expired. Please refresh the Agent Desk.'),403);
        } else {
            self::public_nonce();
        }
        if (HC_Core::get_setting('hc_ai_support_media_enabled','1') !== '1') wp_send_json_error(array('message'=>'File sharing is disabled in Human Support settings.'),403);
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $ticket_id));
        if (!$ticket || in_array($ticket->status,array('closed','archived'),true)) wp_send_json_error(array('message'=>'This support conversation is closed or unavailable.'),403);
        $ticket = self::maybe_timeout($ticket);
        if ($is_admin) {
            if ((int)$ticket->assigned_agent_id !== get_current_user_id()) wp_send_json_error(array('message'=>'Only the assigned agent can exchange files in this ticket.'),403);
            $owner = 'user:' . get_current_user_id(); $sender = 'agent';
        } else {
            if (!(int)$ticket->assigned_agent_id && $ticket->status !== 'queued') wp_send_json_error(array('message'=>'File sharing becomes available when your support conversation is active.'),403);
            if ($ticket->status === 'pending_email') wp_send_json_error(array('message'=>'This conversation has moved to email follow-up.'),403);
            $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
            if (!$session || HC_AI_Platform::session_hash($session) !== $ticket->session_hash) wp_send_json_error(array('message'=>'Conversation not found.'),403);
            $owner = 'visitor:' . $ticket->session_hash; $sender = 'visitor';
        }
        return array($ticket,$sender,$owner,$session ?? '');
    }

    private static function staged_index_key($ticket_id, $owner) {
        return 'hc_ai_stage_idx_' . md5((int)$ticket_id . '|' . (string)$owner);
    }

    private static function staged_index_remove($ticket_id, $owner, $token) {
        $key = self::staged_index_key($ticket_id, $owner);
        $tokens = get_transient($key);
        if (!is_array($tokens)) return;
        $tokens = array_values(array_diff($tokens, array((string)$token)));
        if ($tokens) set_transient($key, $tokens, HOUR_IN_SECONDS);
        else delete_transient($key);
    }

    public static function stage_attachment() {
        list($ticket,$sender,$owner,$session) = self::staged_upload_context();
        $upload_error = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error !== UPLOAD_ERR_OK || empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $upload_errors = array(
                UPLOAD_ERR_INI_SIZE => 'The file exceeds the PHP upload_max_filesize limit. Choose a smaller file or ask the site administrator to increase the upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The file exceeds the form upload limit. Choose a smaller file.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a file before uploading.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary folder is missing. Please contact the site administrator.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file. Check disk space and upload-folder permissions.',
                UPLOAD_ERR_EXTENSION => 'A server extension stopped this upload. Please contact the site administrator.',
            );
            $msg = $upload_errors[$upload_error] ?? 'The upload failed. Please try again with a different file.';
            wp_send_json_error(array('message'=>$msg),400);
        }
        $maximum = max(1,min(3,absint(HC_Core::get_setting('hc_ai_support_max_files_per_message','3'))));
        $index_key = self::staged_index_key((int)$ticket->id, $owner);
        $indexed = get_transient($index_key); $indexed = is_array($indexed) ? $indexed : array();
        $active_tokens = array();
        foreach ($indexed as $indexed_token) {
            $existing = get_transient('hc_ai_stage_' . $indexed_token);
            if (is_array($existing) && (int)($existing['ticket_id']??0)===(int)$ticket->id && ($existing['owner']??'')===$owner && !empty($existing['path']) && is_file($existing['path'])) $active_tokens[] = $indexed_token;
        }
        if (count($active_tokens) >= $maximum) wp_send_json_error(array('message'=>'You can stage up to '.$maximum.' files for one message. Remove an existing file before adding another.'),400);
        $file=$_FILES['file']; $original=sanitize_file_name(wp_unslash($file['name'])); $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        $rules=array('jpg'=>array('image/jpeg','image'),'jpeg'=>array('image/jpeg','image'),'png'=>array('image/png','image'),'gif'=>array('image/gif','image'),'webp'=>array('image/webp','image'),'mp4'=>array('video/mp4','video'),'webm'=>array('video/webm','video'),'txt'=>array('text/plain','text'),'csv'=>array('text/csv','text'),'pdf'=>array('application/pdf','file'),'zip'=>array('application/zip','zip'));
        if(!isset($rules[$ext])) wp_send_json_error(array('message'=>'Allowed formats: JPG, PNG, GIF, WebP, MP4, WebM, TXT, CSV, PDF and ZIP.'),400);
        list($mime,$kind)=$rules[$ext]; $actual=function_exists('mime_content_type')?mime_content_type($file['tmp_name']):$mime;
        $actual_allowed=array($mime); if($ext==='csv')$actual_allowed=array('text/csv','text/plain','application/vnd.ms-excel','application/csv'); if($ext==='zip')$actual_allowed=array('application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream');
        if(!in_array($actual,$actual_allowed,true))wp_send_json_error(array('message'=>'The uploaded file type does not match its extension.'),400);
        $default=($kind==='video'||$kind==='zip')?'20':(($kind==='file')?'10':(($kind==='text')?'2':'5'));
        $limit=max(1,min(100,absint(HC_Core::get_setting('hc_ai_support_'.$kind.'_max_mb',$default))));
        if((int)$file['size']>$limit*MB_IN_BYTES)wp_send_json_error(array('message'=>'This file exceeds the '.$limit.' MB limit for '.$kind.' files.'),400);
        $dir=self::attachment_storage_dir('staged');
        if(!wp_mkdir_p($dir))wp_send_json_error(array('message'=>'The private upload folder could not be created.'),500);
        foreach(array('index.php'=>"<?php // no direct access\n",'.htaccess'=>"Deny from all\n<IfModule mod_authz_core.c>Require all denied</IfModule>\n") as $name=>$body){if(!file_exists($dir.'/'.$name))@file_put_contents($dir.'/'.$name,$body);}
        $token=function_exists('random_bytes')?bin2hex(random_bytes(18)):wp_generate_password(36,false,false);$token=strtolower(preg_replace('/[^a-f0-9]/i','',$token));$stored=time().'-'.$token.'.'.$ext;$path=trailingslashit($dir).$stored;
        if(!move_uploaded_file($file['tmp_name'],$path))wp_send_json_error(array('message'=>'The file could not be staged on the server.'),500);
        $record=array('ticket_id'=>(int)$ticket->id,'owner'=>$owner,'sender'=>$sender,'path'=>$path,'stored'=>$stored,'name'=>$original,'mime'=>$mime,'size'=>(int)$file['size'],'kind'=>$kind,'created'=>time());
        set_transient('hc_ai_stage_'.$token,$record,HOUR_IN_SECONDS);
        $active_tokens[] = $token;
        set_transient($index_key, array_values(array_unique($active_tokens)), HOUR_IN_SECONDS);
        wp_send_json_success(array('token'=>$token,'name'=>$original,'size'=>(int)$file['size'],'kind'=>$kind,'message'=>'File uploaded and ready to send.'));
    }

    public static function cancel_staged_attachment() {
        list($ticket,$sender,$owner) = self::staged_upload_context();
        $token=sanitize_text_field(wp_unslash($_POST['staged_id']??''));
        if(!preg_match('/^[a-f0-9]{36}$/',$token))wp_send_json_error(array('message'=>'Invalid staged upload token.'),400);
        $key='hc_ai_stage_'.$token;$record=get_transient($key);
        if(!is_array($record)||(int)($record['ticket_id']??0)!==(int)$ticket->id||($record['owner']??'')!==$owner)wp_send_json_error(array('message'=>'This staged file has expired or belongs to another conversation.'),404);
        if(!empty($record['path'])&&is_file($record['path']))@unlink($record['path']);delete_transient($key);self::staged_index_remove((int)$ticket->id,$owner,$token);
        wp_send_json_success(array('message'=>'Staged file removed.'));
    }

    public static function send_staged_message() {
        $send_started_at = microtime(true);
        list($ticket,$sender,$owner,$session) = self::staged_upload_context();
        global $wpdb;
        $text=trim(sanitize_textarea_field(wp_unslash($_POST['message']??'')));
        if(mb_strlen($text)>5000)wp_send_json_error(array('message'=>'Message text must be 5,000 characters or fewer.'),400);
        $raw=wp_unslash($_POST['staged_ids']??array());$tokens=is_array($raw)?$raw:array($raw);$tokens=array_values(array_unique(array_filter(array_map('sanitize_text_field',$tokens))));
        $maximum=max(1,min(3,absint(HC_Core::get_setting('hc_ai_support_max_files_per_message','3'))));
        if(count($tokens)>$maximum)wp_send_json_error(array('message'=>'You can send up to '.$maximum.' files in one message.'),400);
        if($text===''&&!$tokens)wp_send_json_error(array('message'=>'Write a message or attach at least one file.'),400);
        if(!$sender||(!$ticket->assigned_agent_id&&$ticket->status!=='queued'))wp_send_json_error(array('message'=>'File and message delivery is not available for this conversation state.'),403);
        if($sender==='visitor'&&in_array($ticket->status,array('pending_email','converted'),true))wp_send_json_error(array('message'=>$ticket->status==='converted'?'This conversation is read-only. Please continue in the ticket portal.':'This conversation has moved to email follow-up.'),403);
        if($sender==='visitor'&&in_array($ticket->status,array('queued','active','waiting_agent'),true)&&empty($ticket->agent_connected_at)&&(int)$ticket->queued_extra_messages>=3)wp_send_json_error(array('message'=>'Please wait for a support specialist to respond.'),429);
        $staged=array();
        foreach($tokens as $token){if(!preg_match('/^[a-f0-9]{36}$/',$token))wp_send_json_error(array('message'=>'One of the staged file tokens is invalid.'),400);$record=get_transient('hc_ai_stage_'.$token);if(!is_array($record)||(int)($record['ticket_id']??0)!==(int)$ticket->id||($record['owner']??'')!==$owner||empty($record['path'])||!is_file($record['path']))wp_send_json_error(array('message'=>'A staged file has expired. Please upload it again.'),409);$staged[]=array($token,$record);}
        $u=$sender==='agent'?wp_get_current_user():null;$sender_id=$sender==='agent'?(int)$u->ID:(int)$ticket->user_id;$sender_name=$sender==='agent'?($u->display_name?:$u->user_login):(string)$ticket->visitor_name;$now=current_time('mysql');$created=array();$private_base=self::attachment_storage_dir();if(!$private_base)wp_send_json_error(array('message'=>'Private HelpCenter attachment storage is unavailable. Check folder permissions.'),500);
        if($text!==''){$duplicate=false;$mid=self::insert_message((int)$ticket->id,(int)$ticket->conversation_id,$sender,$sender_id,$sender_name,$text,sanitize_text_field(wp_unslash($_POST['client_message_id']??'')),$duplicate);if(!$mid)wp_send_json_error(array('message'=>'Message text could not be saved.'),500);$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE id=%d',$mid));if($row)$created[]=self::format_message($row,$sender==='visitor'?$session:'');}
        foreach($staged as $entry){list($token,$record)=$entry;$final=trailingslashit($private_base).'sent-'.$record['stored'];if(!@rename($record['path'],$final))wp_send_json_error(array('message'=>'Could not move a staged file into the ticket. The staged file remains available to retry.'),500);
            $client='hcfile-'.substr($token,0,24);$insert=$wpdb->insert(self::messages_table(),array('ticket_id'=>(int)$ticket->id,'conversation_id'=>(int)$ticket->conversation_id,'sender_type'=>$sender,'sender_user_id'=>$sender_id,'sender_name'=>$sender_name,'content'=>'','attachment_path'=>basename($final),'attachment_name'=>$record['name'],'attachment_mime'=>$record['mime'],'attachment_size'=>(int)$record['size'],'attachment_kind'=>$record['kind'],'client_message_id'=>$client,'email_status'=>'not_required','created_at'=>$now),array('%d','%d','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s'));
            if(!$insert){@rename($final,$record['path']);wp_send_json_error(array('message'=>'A file could not be attached to the ticket. Please retry.'),500);}
            $mid=(int)$wpdb->insert_id;$wpdb->update(self::table(),array('last_message_id'=>$mid,'last_activity'=>$now),array('id'=>(int)$ticket->id),array('%d','%s'),array('%d'));
            if(class_exists('HC_Realtime'))HC_Realtime::publish_ticket_event((int)$ticket->id,'message.attachment',$mid);
            if(class_exists('HC_Web_Push')) { if($sender==='visitor' && (int)$ticket->assigned_agent_id>0) HC_Web_Push::send('agent',(int)$ticket->assigned_agent_id,'New customer attachment','A customer sent an attachment.',home_url('/support-agent/?tab=assigned&ticket='.(int)$ticket->id),'hc-agent-attachment-'.$ticket->id.'-'.(int)$wpdb->insert_id,(int)$ticket->id); elseif($sender==='agent') HC_Web_Push::send('customer',(int)$ticket->id,'New support attachment','Your support specialist sent an attachment.',home_url('/helpcenter/'),'hc-customer-attachment-'.$ticket->id.'-'.(int)$wpdb->insert_id,(int)$ticket->id); }
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::messages_table().' WHERE id=%d',$mid));$formatted=self::format_message($row,$sender==='visitor'?$session:'');
            if($sender==='agent'&&!empty($formatted['attachment'])){
                $agent_attachment_url=self::signed_preview_url((int)$ticket->id,$mid,'');
                $formatted['attachment']['url']=$agent_attachment_url;
                // The Agent Desk message renderer consumes the legacy top-level
                // attachment fields; keep both shapes during the UI transition.
                $formatted['attachment_url']=$agent_attachment_url;
                $formatted['attachment_name']=$record['name'];
                $formatted['attachment_mime']=$record['mime'];
                $formatted['attachment_size']=(int)$record['size'];
                $formatted['attachment_kind']=$record['kind'];
            }
            $created[]=$formatted;delete_transient('hc_ai_stage_'.$token);self::staged_index_remove((int)$ticket->id,$owner,$token);
        }
        $next_status=$ticket->status==='queued'?'queued':($sender==='agent'?'waiting_customer':'waiting_agent');
        $ticket_update=array('last_activity'=>$now,'status'=>$next_status,'visitor_typing_at'=>$sender==='visitor'?null:$ticket->visitor_typing_at,'agent_typing_at'=>$sender==='agent'?null:$ticket->agent_typing_at);
        if($sender==='visitor'&&in_array($ticket->status,array('queued','active','waiting_agent'),true)&&empty($ticket->agent_connected_at))$ticket_update['queued_extra_messages']=(int)$ticket->queued_extra_messages+1;
        $wpdb->update(self::table(),$ticket_update,array('id'=>(int)$ticket->id));
        self::send_json_success_fast(array('messages'=>$created,'message'=>'Message delivered.'), $send_started_at);
    }

    public static function cleanup_staged_attachments() {
        $dir=self::attachment_storage_dir('staged');if(!is_dir($dir))return;$cutoff=time()-2*HOUR_IN_SECONDS;
        foreach(glob($dir.'/*')?:array() as $file){if(!is_file($file)||in_array(basename($file),array('index.php','.htaccess'),true))continue;if(filemtime($file)<$cutoff)@unlink($file);}
    }

    public static function upload_attachment() {
        $is_agent = is_user_logged_in() && current_user_can('hc_agent_portal_access');
        // Preserve the legacy wp-admin upload flow only when its own admin nonce is
        // actually supplied. A logged-in administrator using the public widget must
        // still be treated as a visitor and validated with hc_ai_public.
        $is_admin_request = is_user_logged_in() && self::can_manage() && wp_verify_nonce($_POST['nonce'] ?? '', 'hc_nonce');
        $is_admin = $is_agent || $is_admin_request;
        if ($is_agent) { if (!wp_verify_nonce($_POST['nonce'] ?? '', 'hc_agent_portal')) wp_send_json_error(array('message'=>'Agent session expired.'),403); }
        elseif ($is_admin_request) self::admin_nonce();
        else self::public_nonce();
        if (HC_Core::get_setting('hc_ai_support_media_enabled', '1') !== '1') wp_send_json_error(array('message'=>'File sharing is disabled.'),403);
        if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $upload_error = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : 0;
            $message = $upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE ? 'The file exceeds the server upload limit. Increase upload_max_filesize and post_max_size or choose a smaller file.' : 'Choose a valid file to upload.';
            wp_send_json_error(array('message'=>$message),400);
        }
        global $wpdb;
        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $ticket_id));
        if (!$ticket || in_array($ticket->status,array('closed','converted'),true)) wp_send_json_error(array('message'=>'This conversation is not accepting uploads.'),403);
        $ticket = self::maybe_timeout($ticket);
        if (!$is_admin && in_array($ticket->status,array('pending_email','converted'),true)) wp_send_json_error(array('message'=>$ticket->status==='converted'?'This conversation is read-only. Please continue in the ticket portal.':'This conversation has moved to email follow-up. Please check your email for support replies.'),403);
        $prejoin_visitor_upload = !$is_admin && in_array($ticket->status,array('queued','active','waiting_agent'),true) && empty($ticket->agent_connected_at);
        if ($prejoin_visitor_upload && (int)$ticket->queued_extra_messages >= 3) wp_send_json_error(array('message'=>'You have used all three messages allowed before an agent connects. Please wait for a support specialist.'),429);
        if ($is_admin) {
            if ((int)$ticket->assigned_agent_id !== get_current_user_id()) wp_send_json_error(array('message'=>'Only the assigned agent can upload files.'),403);
        } else {
            if (!(int)$ticket->assigned_agent_id) wp_send_json_error(array('message'=>'File sharing becomes available after a support specialist joins.'),403);
            $session = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
            if (!$session || HC_AI_Platform::session_hash($session) !== $ticket->session_hash) wp_send_json_error(array('message'=>'Conversation not found.'),403);
        }
        $client_id = sanitize_text_field(wp_unslash($_POST['client_message_id'] ?? ''));
        if ($client_id !== '') {
            $existing_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::messages_table() . " WHERE ticket_id=%d AND client_message_id=%s LIMIT 1", $ticket_id, $client_id));
            if ($existing_id) {
                $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $existing_id));
                $email_state = ($is_admin && $ticket->status === 'pending_email') ? (string)$wpdb->get_var($wpdb->prepare("SELECT email_status FROM " . self::messages_table() . " WHERE id=%d", $existing_id)) : '';
                if (in_array($email_state, array('pending','failed','sending'), true)) wp_schedule_single_event(time(), 'hc_ai_support_retry_message_email', array((int)$existing_id));
                $email_sent = $email_state === 'sent' ? true : null;
                wp_send_json_success(array('message'=>self::format_message($existing_row, $is_admin ? '' : $session), 'message_id'=>$existing_id, 'duplicate'=>true,'email_mode'=>$is_admin && $ticket->status === 'pending_email','email_sent'=>$email_sent));
            }
        }
        $file = $_FILES['file'];
        if ((int)$file['error'] !== UPLOAD_ERR_OK) wp_send_json_error(array('message'=>'The upload did not complete. Please retry the file.'),400);
        $original = sanitize_file_name(wp_unslash($file['name']));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $rules = array(
            'jpg'=>array('image/jpeg','image'), 'jpeg'=>array('image/jpeg','image'), 'png'=>array('image/png','image'), 'gif'=>array('image/gif','image'), 'webp'=>array('image/webp','image'),
            'mp4'=>array('video/mp4','video'), 'webm'=>array('video/webm','video'),
            'txt'=>array('text/plain','text'), 'csv'=>array('text/csv','text'), 'pdf'=>array('application/pdf','file'),
            'zip'=>array('application/zip','zip'),
        );
        if (!isset($rules[$ext])) wp_send_json_error(array('message'=>'Allowed formats: JPG, PNG, GIF, WebP, MP4, WebM, TXT, CSV, PDF and ZIP.'));
        $mime = $rules[$ext][0]; $kind = $rules[$ext][1];
        $actual = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $mime;
        $allowed_actual = array($mime);
        if ($ext === 'csv') $allowed_actual = array('text/csv','text/plain','application/vnd.ms-excel','application/csv');
        if ($ext === 'zip') $allowed_actual = array('application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream');
        if (!in_array($actual, $allowed_actual, true)) wp_send_json_error(array('message'=>'The uploaded file type does not match its extension.'));
        $default_limit = ($kind === 'video' || $kind === 'zip') ? '20' : (($kind === 'file') ? '10' : (($kind === 'text') ? '2' : '5'));
        $limit = max(1, min(100, absint(HC_Core::get_setting('hc_ai_support_' . $kind . '_max_mb', $default_limit))));
        if ((int)$file['size'] > $limit * MB_IN_BYTES) wp_send_json_error(array('message'=>'This file exceeds the ' . $limit . ' MB limit for ' . $kind . ' files.'));
        $dir = self::attachment_storage_dir();
        if (!$dir) wp_send_json_error(array('message'=>'The private HelpCenter attachment directory could not be created. Check folder permissions.'),500);
        $stored = wp_generate_password(28, false, false) . '.' . $ext;
        $path = trailingslashit($dir) . $stored;
        if (!move_uploaded_file($file['tmp_name'], $path)) wp_send_json_error(array('message'=>'The uploaded file could not be saved.'),500);
        $sender_type = $is_admin ? 'agent' : 'visitor';
        $sender_id = $is_admin ? get_current_user_id() : (int)$ticket->user_id;
        $sender_user = $is_admin ? wp_get_current_user() : null;
        $sender_name = $is_admin ? ($sender_user->display_name ?: $sender_user->user_login) : (string)$ticket->visitor_name;
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::messages_table(), array(
            'ticket_id'=>$ticket_id,'conversation_id'=>(int)$ticket->conversation_id,'sender_type'=>$sender_type,'sender_user_id'=>$sender_id,'sender_name'=>$sender_name,'content'=>'',
            'attachment_path'=>$stored,'attachment_name'=>$original,'attachment_mime'=>$mime,'attachment_size'=>(int)$file['size'],'attachment_kind'=>$kind,
            'client_message_id'=>$client_id !== '' ? $client_id : null,'email_status'=>($is_admin && $ticket->status === 'pending_email') ? 'pending' : 'not_required','created_at'=>$now
        ), array('%d','%d','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s'));
        $message_id = (int)$wpdb->insert_id;
        if (!$inserted || !$message_id) {
            @unlink($path);
            // A simultaneous retry with the same id may have won the insert race.
            $existing_id = $client_id !== '' ? (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::messages_table() . " WHERE ticket_id=%d AND client_message_id=%s LIMIT 1", $ticket_id, $client_id)) : 0;
            if ($existing_id) {
                $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $existing_id));
                $email_state = ($is_admin && $ticket->status === 'pending_email') ? (string)$wpdb->get_var($wpdb->prepare("SELECT email_status FROM " . self::messages_table() . " WHERE id=%d", $existing_id)) : '';
                if (in_array($email_state, array('pending','failed','sending'), true)) wp_schedule_single_event(time(), 'hc_ai_support_retry_message_email', array((int)$existing_id));
                $email_sent = $email_state === 'sent' ? true : null;
                wp_send_json_success(array('message'=>self::format_message($existing_row, $is_admin ? '' : $session), 'message_id'=>$existing_id, 'duplicate'=>true,'email_mode'=>$is_admin && $ticket->status === 'pending_email','email_sent'=>$email_sent));
            }
            wp_send_json_error(array('message'=>'The file was uploaded but the message could not be saved. Please retry.'),500);
        }
        $email_mode = $ticket->status === 'pending_email';
        $next_status = $email_mode ? 'pending_email' : ($prejoin_visitor_upload ? $ticket->status : ($is_admin ? 'waiting_customer' : 'waiting_agent'));
        $upload_update=array('last_message_id'=>$message_id,'last_activity'=>$now,'status'=>$next_status,'agent_typing_at'=>$is_admin ? null : $ticket->agent_typing_at,'visitor_typing_at'=>$is_admin ? $ticket->visitor_typing_at : null);
        if ($prejoin_visitor_upload) $upload_update['queued_extra_messages']=(int)$ticket->queued_extra_messages+1;
        $wpdb->update(self::table(), $upload_update, array('id'=>$ticket_id));
        if (class_exists('HC_Realtime')) HC_Realtime::publish_ticket_event($ticket_id, 'message.attachment', $message_id);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::messages_table() . " WHERE id=%d", $message_id));
        $formatted = self::format_message($row, $is_admin ? '' : $session);
        $email_sent = null;
        if ($is_admin && $email_mode) {
            $email_state = (string)$wpdb->get_var($wpdb->prepare("SELECT email_status FROM " . self::messages_table() . " WHERE id=%d", $message_id));
            if ($email_state === 'sent') $email_sent = true;
            elseif (in_array($email_state, array('pending','failed','sending'), true)) wp_schedule_single_event(time(), 'hc_ai_support_retry_message_email', array((int)$message_id));
        }
        wp_send_json_success(array('message'=>$formatted,'message_id'=>$message_id,'email_mode'=>$email_mode,'email_sent'=>$email_sent));
    }

    public static function download_attachment() {
        global $wpdb;
        $ticket_id = absint($_GET['ticket_id'] ?? 0); $message_id = absint($_GET['message_id'] ?? 0);
        if (!self::verify_preview_signature($ticket_id, $message_id)) wp_die('This preview link is invalid or has expired. Reopen the conversation to generate a fresh preview.', 'Preview expired', array('response'=>403));
        $row = $wpdb->get_row($wpdb->prepare("SELECT m.*,t.session_hash,t.status,t.assigned_agent_id FROM " . self::messages_table() . " m INNER JOIN " . self::table() . " t ON t.id=m.ticket_id WHERE m.id=%d AND m.ticket_id=%d", $message_id, $ticket_id));
        if (!$row) wp_die('Attachment record not found.', '', array('response'=>404));
        $viewer = sanitize_text_field(wp_unslash($_GET['viewer'] ?? ''));
        if (strpos($viewer, 'v:') === 0) {
            if (!hash_equals((string)$row->session_hash, substr($viewer, 2))) wp_die('Access denied.', '', array('response'=>403));
        } else {
            if (!is_user_logged_in() || ((int)$row->assigned_agent_id !== get_current_user_id() && !current_user_can('manage_options'))) wp_die('Access denied.', '', array('response'=>403));
        }
        if (empty($row->attachment_path)) wp_die('This attachment has expired under the configured retention policy.', 'Attachment expired', array('response'=>410));
        $path = self::attachment_absolute_path($row->attachment_path);
        if (!$path) wp_die('Attachment is unavailable.', '', array('response'=>404));
        $mime = (string)($row->attachment_mime ?: 'application/octet-stream');
        $previewable = strpos($mime, 'image/') === 0 || in_array($mime, array('application/pdf','video/mp4','video/webm','text/plain','text/csv'), true);
        if (!$previewable) wp_die('This file type cannot be previewed in chat. Only image, PDF, video and text previews are supported.', 'Preview unavailable', array('response'=>415));
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; media-src \'self\';');
        header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($row->attachment_name ?: 'preview') . '"');
        readfile($path); exit;
    }

    /** Remove attachment binaries after the administrator-defined retention period.
     * Message records remain so agents and visitors can see why a preview is unavailable.
     */
    public static function cleanup_attachments() {
        global $wpdb;
        $days = max(1, min(3650, absint(HC_Core::get_setting('hc_ai_support_attachment_retention_days', '30'))));
        $table = self::messages_table();
        $cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $ticket_table = self::table();
        // Keep binaries attached to active/queued/pending-email conversations even
        // when they exceed the normal retention age. Retention begins after closure.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.id,m.attachment_path FROM {$table} m LEFT JOIN {$ticket_table} t ON t.id=m.ticket_id WHERE m.attachment_path IS NOT NULL AND m.attachment_path<>'' AND m.created_at<%s AND (t.id IS NULL OR t.status IN ('closed','archived')) ORDER BY m.id ASC LIMIT 1000", $cutoff));
        $deleted = 0;
        foreach ((array)$rows as $row) {
            $path = self::attachment_absolute_path($row->attachment_path);
            if ($path && is_file($path)) { @unlink($path); $deleted++; }
            $wpdb->update($table, array('attachment_path'=>null, 'attachment_deleted_at'=>current_time('mysql')), array('id'=>(int)$row->id), array('%s','%s'), array('%d'));
        }
        // Remove orphaned old binaries that no longer have a message record.
        $referenced = $wpdb->get_col("SELECT attachment_path FROM {$table} WHERE attachment_path IS NOT NULL AND attachment_path<>''");
        $referenced = array_fill_keys(array_map('basename', (array)$referenced), true);
        $base = self::attachment_storage_dir();
        if ($base) foreach (glob(trailingslashit($base) . '*') ?: array() as $file) {
            $name = basename($file);
            if (!is_file($file) || in_array($name, array('index.php','.htaccess','web.config'), true) || isset($referenced[$name]) || filemtime($file) >= time() - ($days * DAY_IN_SECONDS)) continue;
            if (@unlink($file)) $deleted++;
        }
        // Expired staged uploads have no message row; remove them independently.
        $staged = self::attachment_storage_dir('staged');
        if ($staged) foreach (glob(trailingslashit($staged) . '*') ?: array() as $file) {
            if (is_file($file) && !in_array(basename($file), array('index.php','.htaccess','web.config'), true) && filemtime($file) < time() - 2 * HOUR_IN_SECONDS) @unlink($file);
        }
        update_option('hc_ai_attachment_cleanup_last_run', current_time('mysql'), false);
        update_option('hc_ai_attachment_cleanup_last_count', (int)$deleted, false);
        return (int)$deleted;
    }

    public static function manual_cleanup_attachments() {
        if (!current_user_can('manage_options') || !check_ajax_referer('hc_nonce', 'nonce', false)) wp_send_json_error(array('message'=>'Administrator permission required.'),403);
        $deleted = self::cleanup_attachments();
        wp_send_json_success(array('message'=>'Cleanup completed. Removed '.$deleted.' expired attachment file(s).','deleted'=>$deleted,'last_run'=>get_option('hc_ai_attachment_cleanup_last_run')));
    }

    public static function timeout_ticket($ticket_id) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", (int) $ticket_id));
        if (!$ticket) return;
        self::maybe_timeout($ticket);
    }
}