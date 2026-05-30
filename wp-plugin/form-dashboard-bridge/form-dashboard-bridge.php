<?php
/**
 * Plugin Name: Form Dashboard Bridge
 * Description: Sends form submissions from this site to a central Form Dashboard. Supports Forminator, Contact Form 7, Gravity Forms, WPForms, Fluent Forms, and Elementor Forms.
 * Version:     1.2.0
 * Author:      You
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

class Form_Dashboard_Bridge {

    const OPT            = 'fdash_settings';
    const VERSION        = '1.2.0';
    const UPDATE_JSON_URL = 'https://raw.githubusercontent.com/Osamaislam1/form-dashboard/main/plugin-version.json';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        // Check for pending resyncs on every admin page load (rate-limited to once per 5 min).
        // This avoids relying on WP-Cron, which doesn't fire reliably on low-traffic sites.
        add_action('admin_init', [__CLASS__, 'maybe_check_resync_on_admin_load']);

        // Forminator — register all known hook variants across versions
        add_action('forminator_custom_form_after_save_entry', [__CLASS__, 'on_forminator'],        20, 2);
        add_action('forminator_form_after_save_entry',        [__CLASS__, 'on_forminator_modern'], 20, 2);
        add_action('forminator_after_save_entry',             [__CLASS__, 'on_forminator_modern'], 20, 2);
        // Contact Form 7
        add_action('wpcf7_mail_sent', [__CLASS__, 'on_cf7']);
        // Gravity Forms
        add_action('gform_after_submission', [__CLASS__, 'on_gravity'], 20, 2);
        // WPForms
        add_action('wpforms_process_complete', [__CLASS__, 'on_wpforms'], 20, 4);
        // Fluent Forms
        add_action('fluentform/submission_inserted', [__CLASS__, 'on_fluent'], 20, 3);
        // Elementor Forms
        add_action('elementor_pro/forms/new_record', [__CLASS__, 'on_elementor'], 20, 2);

        // Migrate heartbeat from daily → hourly on first load after upgrade to v1.2
        $recurrence = wp_get_schedule('fdash_heartbeat');
        if ($recurrence && $recurrence !== 'hourly') {
            self::schedule_heartbeat();
        }

        // Re-register cron events if they were wiped (hosting migration, option flush, etc.)
        if (!wp_next_scheduled('fdash_heartbeat')) {
            self::schedule_heartbeat();
        }
        if (!wp_next_scheduled('fdash_retry') && get_option('fdash_retry_queue', [])) {
            wp_schedule_event(time() + 300, 'hourly', 'fdash_retry');
        }
    }

    public static function menu() {
        add_options_page('Form Dashboard', 'Form Dashboard', 'manage_options', 'fdash', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('fdash_group', self::OPT, [
            'sanitize_callback' => function ($v) {
                return [
                    'endpoint'        => esc_url_raw($v['endpoint'] ?? ''),
                    'api_key'         => sanitize_text_field($v['api_key'] ?? ''),
                    'secret'          => sanitize_text_field($v['secret'] ?? ''),
                    'email_monitor'   => !empty($v['email_monitor']) ? '1' : '0',
                    'email_test_to'   => sanitize_email($v['email_test_to'] ?? ''),
                    'email_frequency' => in_array($v['email_frequency'] ?? '', ['hourly', 'twicedaily', 'daily']) ? $v['email_frequency'] : 'daily',
                    'auto_update'     => !empty($v['auto_update']) ? '1' : '0',
                ];
            }
        ]);
    }

    public static function settings_page() {
        $opt = get_option(self::OPT, []);
        $test = null;
        $email_test = null;
        $sync_result = null;
        $resync_result = null;

        // Handle Test Ping (type=test so dashboard doesn't store it as a real submission)
        if (!empty($_POST['fdash_test']) && check_admin_referer('fdash_test')) {
            $test = self::send([
                'type'         => 'test',
                'plugin'       => 'system',
                'form_id'      => 'connection-test',
                'form_title'   => 'Connection Test',
                'submitted_at' => gmdate('c'),
                'fields'       => ['site_url' => home_url(), 'plugin_version' => self::VERSION],
            ]);
        }

        // Handle Email Health Test
        if (!empty($_POST['fdash_email_test']) && check_admin_referer('fdash_email_test')) {
            $email_test = self::check_email_health(true);
        }

        // Handle Bulk Sync
        if (!empty($_POST['fdash_bulk_sync']) && check_admin_referer('fdash_bulk_sync')) {
            $sync_result = self::handle_bulk_sync($_POST['sync_plugin'] ?? '');
        }

        // Handle manual resync check
        if (!empty($_POST['fdash_check_resync']) && check_admin_referer('fdash_check_resync')) {
            self::check_resync_request();
            $resync_result = 'Resync check complete. If a resync was pending, it has been processed.';
        }

        $queue_depth   = count(get_option('fdash_retry_queue', []));
        $dead_count    = count(get_option('fdash_dead_letter', []));
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $last_send     = get_option('fdash_last_send_result', null);
        $mailer        = self::detect_mailer();
        $freq          = $opt['email_frequency'] ?? 'daily';
        ?>
        <style>
        #fdash-wrap { max-width: 960px; }
        #fdash-wrap * { box-sizing: border-box; }

        /* ── Header ── */
        .fdash-header {
            background: #1d2327;
            color: #f0f0f1;
            padding: 20px 28px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .fdash-header h1 {
            color: #f0f0f1;
            font-size: 18px;
            margin: 0;
            padding: 0;
            border: none;
            flex: 1;
        }
        .fdash-header h1 span {
            font-size: 12px;
            font-weight: 400;
            opacity: .6;
            margin-left: 6px;
        }
        .fdash-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
            white-space: nowrap;
        }
        .fdash-chip-ok   { background: rgba(0,163,42,.18); color: #6dda85; }
        .fdash-chip-warn { background: rgba(220,133,0,.18); color: #f0b849; }
        .fdash-chip-err  { background: rgba(220,50,50,.18);  color: #f87171; }
        .fdash-chip-info { background: rgba(255,255,255,.1); color: #c3c4c7; }

        /* ── Cards ── */
        .fdash-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 0;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(0,0,0,.06);
        }
        .fdash-card-head {
            padding: 16px 24px;
            border-bottom: 1px solid #dcdcde;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .fdash-card-head h2 {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
            padding: 0;
            color: #1d2327;
        }
        .fdash-card-head .fdash-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .fdash-icon-blue  { background: #e8f0fe; }
        .fdash-icon-green { background: #e6f4ea; }
        .fdash-icon-purple{ background: #f3e8fd; }
        .fdash-card-body  { padding: 20px 24px; }

        /* ── Form rows ── */
        .fdash-row {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 12px 20px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f1;
        }
        .fdash-row:last-child { border-bottom: none; }
        .fdash-row label { font-size: 13px; font-weight: 500; color: #3c434a; }
        .fdash-row .fdash-desc { font-size: 12px; color: #787c82; margin-top: 3px; }
        .fdash-row input[type=url],
        .fdash-row input[type=text],
        .fdash-row input[type=email],
        .fdash-row input[type=password],
        .fdash-row select {
            width: 100%;
            max-width: 420px;
            border-radius: 4px;
            border: 1px solid #8c8f94;
            padding: 6px 10px;
            font-size: 13px;
            color: #1d2327;
        }
        .fdash-row input:focus,
        .fdash-row select:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }

        /* ── Toggle checkbox ── */
        .fdash-toggle { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .fdash-toggle input[type=checkbox] { width: 36px; height: 20px; cursor: pointer; accent-color: #00a32a; }
        .fdash-toggle-label { font-size: 13px; color: #1d2327; }

        /* ── Save button row ── */
        .fdash-save-row { padding: 16px 24px; background: #f6f7f7; border-top: 1px solid #dcdcde; border-radius: 0 0 6px 6px; }

        /* ── Tools grid ── */
        .fdash-tools {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-top: 8px;
        }
        .fdash-tool-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,.06);
        }
        .fdash-tool-card h3 {
            font-size: 13px;
            font-weight: 600;
            margin: 0 0 6px;
            color: #1d2327;
        }
        .fdash-tool-card p {
            font-size: 12px;
            color: #787c82;
            margin: 0 0 14px;
            line-height: 1.5;
        }
        .fdash-tool-card select {
            width: 100%;
            margin-bottom: 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            padding: 5px 8px;
            font-size: 12px;
        }
        .fdash-tool-card .button { width: 100%; text-align: center; }
        .fdash-tool-result {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 4px;
            font-size: 12px;
            line-height: 1.5;
        }
        .fdash-tool-result.ok   { background:#edfaef; border:1px solid #c6e8cb; color:#1a5c27; }
        .fdash-tool-result.err  { background:#fdf0f0; border:1px solid #f5c2c2; color:#8b1a1a; }
        .fdash-tool-result.info { background:#f0f6fd; border:1px solid #c2d8f5; color:#1a3a5c; }

        /* ── Section label ── */
        .fdash-section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #787c82;
            margin: 24px 0 10px;
        }
        </style>

        <div id="fdash-wrap" class="wrap">

            <?php if ($cron_disabled): ?>
            <div class="notice notice-warning"><p><strong>Warning:</strong> <code>DISABLE_WP_CRON</code> is set. Configure a real server cron to call <code>wp-cron.php</code> for retries and health checks to run.</p></div>
            <?php endif; ?>
            <?php if ($dead_count > 0): ?>
            <div class="notice notice-error"><p><strong>Dead letter:</strong> <?= $dead_count ?> submission<?= $dead_count !== 1 ? 's' : '' ?> permanently failed. Check your dashboard dead letter log.</p></div>
            <?php endif; ?>

            <!-- Header -->
            <div class="fdash-header">
                <h1>Form Dashboard Bridge <span>v<?= self::VERSION ?></span></h1>

                <?php if ($last_send): ?>
                    <span class="fdash-chip <?= $last_send['ok'] ? 'fdash-chip-ok' : 'fdash-chip-warn' ?>">
                        <?= $last_send['ok'] ? '&#10003;' : '&#9888;' ?>
                        Last send: <?= esc_html(human_time_diff($last_send['time'])) ?> ago
                    </span>
                <?php endif; ?>

                <?php if ($queue_depth > 0): ?>
                    <span class="fdash-chip fdash-chip-warn">&#9203; <?= $queue_depth ?> queued</span>
                <?php endif; ?>

                <span class="fdash-chip fdash-chip-info">&#9993; <?= esc_html($mailer) ?></span>
            </div>

            <!-- Settings form -->
            <form method="post" action="options.php">
                <?php settings_fields('fdash_group'); ?>

                <!-- Connection card -->
                <div class="fdash-card">
                    <div class="fdash-card-head">
                        <div class="fdash-icon fdash-icon-blue">&#128279;</div>
                        <h2>Connection</h2>
                    </div>
                    <div class="fdash-card-body">
                        <div class="fdash-row">
                            <label>Dashboard URL</label>
                            <div>
                                <input type="url" name="<?= self::OPT ?>[endpoint]"
                                    value="<?= esc_attr($opt['endpoint'] ?? '') ?>"
                                    placeholder="https://your-dashboard.example.com/ingest.php">
                                <div class="fdash-desc">Full URL to your dashboard&rsquo;s ingest.php endpoint.</div>
                            </div>
                        </div>
                        <div class="fdash-row">
                            <label>API Key</label>
                            <div>
                                <input type="text" name="<?= self::OPT ?>[api_key]"
                                    value="<?= esc_attr($opt['api_key'] ?? '') ?>"
                                    placeholder="Paste from dashboard">
                            </div>
                        </div>
                        <div class="fdash-row">
                            <label>Secret</label>
                            <div>
                                <input type="password" name="<?= self::OPT ?>[secret]"
                                    value="<?= esc_attr($opt['secret'] ?? '') ?>"
                                    placeholder="Paste from dashboard">
                                <div class="fdash-desc">Both values are shown once when you add the site in the dashboard.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email monitoring card -->
                <div class="fdash-card">
                    <div class="fdash-card-head">
                        <div class="fdash-icon fdash-icon-green">&#9993;</div>
                        <h2>Email Monitoring</h2>
                    </div>
                    <div class="fdash-card-body">
                        <div class="fdash-row">
                            <label>Health checks</label>
                            <label class="fdash-toggle">
                                <input type="checkbox" name="<?= self::OPT ?>[email_monitor]" value="1"
                                    <?= !empty($opt['email_monitor']) ? 'checked' : '' ?>>
                                <span class="fdash-toggle-label">Enable automated email health checks</span>
                            </label>
                        </div>
                        <div class="fdash-row">
                            <label>Test recipient</label>
                            <div>
                                <input type="email" name="<?= self::OPT ?>[email_test_to]"
                                    value="<?= esc_attr($opt['email_test_to'] ?? get_option('admin_email')) ?>"
                                    placeholder="<?= esc_attr(get_option('admin_email')) ?>">
                                <div class="fdash-desc">Detected mailer: <strong><?= esc_html($mailer) ?></strong></div>
                            </div>
                        </div>
                        <div class="fdash-row">
                            <label>Check frequency</label>
                            <select name="<?= self::OPT ?>[email_frequency]">
                                <option value="hourly"    <?= $freq === 'hourly'    ? 'selected' : '' ?>>Every hour</option>
                                <option value="twicedaily"<?= $freq === 'twicedaily'? 'selected' : '' ?>>Twice a day</option>
                                <option value="daily"     <?= $freq === 'daily'     ? 'selected' : '' ?>>Once a day</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Updates card -->
                <div class="fdash-card">
                    <div class="fdash-card-head">
                        <div class="fdash-icon fdash-icon-purple">&#8593;</div>
                        <h2>Updates</h2>
                    </div>
                    <div class="fdash-card-body">
                        <div class="fdash-row">
                            <label>Auto-update</label>
                            <label class="fdash-toggle">
                                <input type="checkbox" name="<?= self::OPT ?>[auto_update]" value="1"
                                    <?= ($opt['auto_update'] ?? '1') !== '0' ? 'checked' : '' ?>>
                                <span class="fdash-toggle-label">Automatically install new versions when released</span>
                            </label>
                        </div>
                    </div>
                    <div class="fdash-save-row">
                        <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                    </div>
                </div>

            </form>

            <!-- Tools -->
            <div class="fdash-section-label">Tools</div>
            <div class="fdash-tools">

                <!-- Test connection -->
                <div class="fdash-tool-card">
                    <h3>&#128272; Connection Test</h3>
                    <p>Send a test ping to verify the dashboard can receive data from this site.</p>
                    <form method="post">
                        <?php wp_nonce_field('fdash_test'); ?>
                        <input type="hidden" name="fdash_test" value="1">
                        <input type="submit" class="button button-secondary" value="Send Test Ping">
                    </form>
                    <?php if ($test !== null): ?>
                        <div class="fdash-tool-result <?= $test['ok'] ? 'ok' : 'err' ?>">
                            <strong>HTTP <?= esc_html($test['code']) ?></strong> &mdash; <?= esc_html($test['body']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Bulk sync -->
                <div class="fdash-tool-card">
                    <h3>&#128257; Bulk Sync</h3>
                    <p>Push existing historical entries from this site to the dashboard. Run once per form plugin.</p>
                    <form method="post">
                        <?php wp_nonce_field('fdash_bulk_sync'); ?>
                        <select name="sync_plugin" required>
                            <option value="">Select plugin&hellip;</option>
                            <?php if (class_exists('Forminator_API')): ?><option value="forminator">Forminator</option><?php endif; ?>
                            <?php if (defined('WPCF7_VERSION')): ?><option value="cf7" disabled>CF7 (no DB storage)</option><?php endif; ?>
                            <?php if (class_exists('GFAPI')): ?><option value="gravity">Gravity Forms</option><?php endif; ?>
                            <?php if (function_exists('wpforms')): ?><option value="wpforms">WPForms</option><?php endif; ?>
                        </select>
                        <input type="hidden" name="fdash_bulk_sync" value="1">
                        <input type="submit" class="button button-secondary" value="Sync Historical Entries">
                    </form>
                    <?php if ($sync_result): ?>
                        <div class="fdash-tool-result info"><?= wp_kses_post($sync_result) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Resync check -->
                <div class="fdash-tool-card">
                    <h3>&#128260; Resync Check</h3>
                    <p>Polls the dashboard to process any pending resync request immediately, without waiting for the hourly heartbeat.</p>
                    <form method="post">
                        <?php wp_nonce_field('fdash_check_resync'); ?>
                        <input type="hidden" name="fdash_check_resync" value="1">
                        <input type="submit" class="button button-secondary" value="Check Now">
                    </form>
                    <?php if ($resync_result !== null): ?>
                        <div class="fdash-tool-result info"><?= esc_html($resync_result) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Email health -->
                <div class="fdash-tool-card">
                    <h3>&#128140; Email Health</h3>
                    <p>Test <code>wp_mail()</code> on this site and report the result to the dashboard.</p>
                    <form method="post">
                        <?php wp_nonce_field('fdash_email_test'); ?>
                        <input type="hidden" name="fdash_email_test" value="1">
                        <input type="submit" class="button button-secondary" value="Test Email Now">
                    </form>
                    <?php if ($email_test !== null): ?>
                        <div class="fdash-tool-result <?= $email_test['mail_ok'] ? 'ok' : 'err' ?>">
                            <strong>wp_mail():</strong> <?= $email_test['mail_ok'] ? '&#10003; Sent' : '&#10007; Failed' ?><br>
                            <?php if (!$email_test['mail_ok'] && $email_test['error']): ?>
                                <?= esc_html($email_test['error']) ?><br>
                            <?php endif; ?>
                            <strong>Report:</strong>
                            <?= $email_test['report_ok']
                                ? '&#10003; HTTP ' . esc_html($email_test['report_code'])
                                : '&#10007; ' . esc_html($email_test['report_body']) ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- .fdash-tools -->
        </div><!-- #fdash-wrap -->
        <?php
    }

    /* ===== Bulk Sync Handlers ===== */

    /**
     * Sends a payload non-blocking (fire-and-forget) for bulk operations.
     * Does not wait for the dashboard to respond, so it can't report per-entry
     * errors, but it avoids PHP timeout on large datasets. The dashboard
     * deduplicates by entry_id so re-running the sync is always safe.
     */
    private static function send_bulk(array $payload): void {
        $opt = get_option(self::OPT, []);
        if (empty($opt['endpoint']) || empty($opt['api_key']) || empty($opt['secret'])) return;

        $body = wp_json_encode($payload);
        $ts   = (string)time();
        $sig  = hash_hmac('sha256', $ts . '.' . $body, $opt['secret']);

        wp_remote_post($opt['endpoint'], [
            'headers'   => [
                'Content-Type'      => 'application/json',
                'X-FDASH-Key'       => $opt['api_key'],
                'X-FDASH-Signature' => $sig,
                'X-FDASH-Timestamp' => $ts,
            ],
            'body'      => $body,
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => true,
        ]);
    }

    public static function handle_bulk_sync($plugin) {
        if (empty($plugin)) return "Please select a plugin.";

        // Give the sync as much runway as the host allows.
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        $count     = 0;
        $page_size = 50;

        try {

        if ($plugin === 'forminator' && class_exists('Forminator_API')) {
            $forms = Forminator_API::get_forms();
            if (empty($forms)) return 'No Forminator forms found.';
            foreach ($forms as $form) {
                $page = 0;
                do {
                    $entries = Forminator_API::get_entries((int)$form->id, 'any', (int)$page, (int)$page_size);
                    if (empty($entries)) break;
                    $page++;
                    foreach ($entries as $entry) {
                        $fields = [];
                        if (!empty($entry->meta_data) && is_array($entry->meta_data)) {
                            foreach ($entry->meta_data as $key => $meta) {
                                $val = $meta['value'] ?? '';
                                $fields[$key] = is_array($val) ? wp_json_encode($val) : (string)$val;
                            }
                        }
                        self::send_bulk([
                            'plugin'       => 'forminator',
                            'form_id'      => (string)$form->id,
                            'form_title'   => $form->settings['formName'] ?? ('Forminator #' . $form->id),
                            'entry_id'     => (string)$entry->entry_id,
                            'submitted_at' => !empty($entry->date_created_sql)
                                ? $entry->date_created_sql
                                : (!empty($entry->date_created) ? $entry->date_created : gmdate('c')),
                            'ip'           => $entry->ip ?? '',
                            'user_agent'   => '',
                            'fields'       => $fields,
                        ]);
                        $count++;
                    }
                } while (is_array($entries) && count($entries) === $page_size);
            }
            return "<strong>Forminator sync queued:</strong> $count entries dispatched to the dashboard. They will appear within a few seconds.";
        }

        if ($plugin === 'gravity' && class_exists('GFAPI')) {
            $offset = 0;
            do {
                $entries = GFAPI::get_entries(0, [], null, ['offset' => $offset, 'page_size' => $page_size]);
                if (empty($entries)) break;
                foreach ($entries as $entry) {
                    $form   = GFAPI::get_form($entry['form_id']);
                    $fields = [];
                    foreach ($form['fields'] as $f) {
                        $label          = $f->label ?: ('field_' . $f->id);
                        $fields[$label] = (string)rgar($entry, (string)$f->id);
                    }
                    self::send_bulk([
                        'plugin'       => 'gravity',
                        'form_id'      => (string)$entry['form_id'],
                        'form_title'   => $form['title'],
                        'entry_id'     => (string)$entry['id'],
                        'submitted_at' => $entry['date_created'],
                        'ip'           => $entry['ip'],
                        'user_agent'   => $entry['user_agent'],
                        'fields'       => $fields,
                    ]);
                    $count++;
                }
                $offset += (int)$page_size;
            } while (is_array($entries) && count($entries) === $page_size);
            return "<strong>Gravity Forms sync queued:</strong> $count entries dispatched to the dashboard.";
        }

        if ($plugin === 'wpforms' && function_exists('wpforms')) {
            $page = 1;
            do {
                $entries = wpforms()->entry->get_entries([
                    'number' => $page_size,
                    'offset' => (int)(((int)$page - 1) * (int)$page_size),
                ]);
                if (empty($entries)) break;
                foreach ($entries as $entry) {
                    $form_data  = wpforms()->form->get((int)$entry->form_id, ['content_only' => true]);
                    $fields_raw = json_decode($entry->fields, true) ?: [];
                    $out = [];
                    foreach ((array)$fields_raw as $f) {
                        $label      = $f['name'] ?? ('field_' . ($f['id'] ?? '?'));
                        $out[$label] = is_array($f['value'] ?? '') ? wp_json_encode($f['value']) : (string)($f['value'] ?? '');
                    }
                    self::send_bulk([
                        'plugin'       => 'wpforms',
                        'form_id'      => (string)$entry->form_id,
                        'form_title'   => $form_data['settings']['form_title'] ?? ('WPForms #' . $entry->form_id),
                        'entry_id'     => (string)$entry->entry_id,
                        'submitted_at' => $entry->date ?? gmdate('c'),
                        'ip'           => $entry->ip_address ?? '',
                        'user_agent'   => $entry->user_agent ?? '',
                        'fields'       => $out,
                    ]);
                    $count++;
                }
                $page++;
            } while (is_array($entries) && count($entries) === $page_size);
            return "<strong>WPForms sync queued:</strong> $count entries dispatched to the dashboard.";
        }

        } catch (\Throwable $e) {
            return 'Sync failed: ' . esc_html($e->getMessage());
        }

        return "Sync logic for this plugin is not yet implemented or the plugin is inactive.";
    }

    /* ===== Submission handlers ===== */

    public static function on_forminator($entry, $form_id) {
        if (!is_object($entry)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[fdash] on_forminator: $entry is not an object for form ' . $form_id);
            return;
        }

        // Guard against double-fire across hook variants
        $lock_key = 'fdash_fi_' . (int)($entry->entry_id ?? 0);
        if ($entry->entry_id && get_transient($lock_key)) return;
        if ($entry->entry_id) set_transient($lock_key, 1, 60);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[fdash] on_forminator fired: form=' . $form_id . ' entry=' . ($entry->entry_id ?? 'no-id'));

        $fields = [];
        if (!empty($entry->meta_data) && is_array($entry->meta_data)) {
            foreach ($entry->meta_data as $key => $meta) {
                $val = $meta['value'] ?? '';
                $fields[$key] = is_array($val) ? wp_json_encode($val) : (string)$val;
            }
        }
        $title = '';
        if (class_exists('Forminator_API') && method_exists('Forminator_API', 'get_form')) {
            try {
                $f = Forminator_API::get_form($form_id);
                if ($f && isset($f->settings['formName'])) $title = $f->settings['formName'];
            } catch (\Throwable $e) {}
        }
        if (!$title) $title = 'Forminator form #' . $form_id;

        self::send([
            'plugin'       => 'forminator',
            'form_id'      => (string)$form_id,
            'form_title'   => $title,
            'entry_id'     => (string)($entry->entry_id ?? ''),
            'submitted_at' => !empty($entry->date_created_sql)
                ? $entry->date_created_sql
                : (!empty($entry->date_created) ? $entry->date_created : gmdate('c')),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fields'       => $fields,
        ]);
    }

    public static function on_forminator_modern($form_id, $response) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[fdash] on_forminator_modern fired: form=' . $form_id . ' response=' . wp_json_encode($response));

        // Resolve entry_id from whatever shape Forminator passes (varies by version)
        $entry_id = 0;
        if (is_array($response)) {
            // Some versions: ['success' => true, 'entry_id' => N]
            // Treat missing 'success' key as success (don't require it)
            if (array_key_exists('success', $response) && !$response['success']) return;
            $entry_id = (int)($response['entry_id'] ?? $response['id'] ?? 0);
        } elseif (is_object($response)) {
            // Object shape: $response->entry_id or $response->id
            if (property_exists($response, 'success') && !$response->success) return;
            $entry_id = (int)($response->entry_id ?? $response->id ?? 0);
        } elseif (is_numeric($response)) {
            // Some versions pass the entry ID directly as an integer
            $entry_id = (int)$response;
        }

        if (!$entry_id) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[fdash] on_forminator_modern: could not resolve entry_id from response');
            return;
        }

        // Check lock before expensive API call
        $lock_key = 'fdash_fi_' . $entry_id;
        if (get_transient($lock_key)) return;

        if (!class_exists('Forminator_API')) return;

        $entry = null;
        try {
            $entry = Forminator_API::get_entry($form_id, $entry_id);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[fdash] Forminator_API::get_entry failed: ' . $e->getMessage());
        }

        // Tier-1 fallback: try Forminator_Form_Entry_Model directly (stable across versions)
        if (!$entry || is_wp_error($entry)) {
            if (class_exists('Forminator_Form_Entry_Model')) {
                try {
                    $entry = Forminator_Form_Entry_Model::get_model($entry_id);
                } catch (\Throwable $e2) {
                    $entry = null;
                }
            }
        }

        // Tier-2 fallback: read entry meta directly from DB (works regardless of API shape)
        if (!$entry || is_wp_error($entry)) {
            global $wpdb;
            $table = $wpdb->prefix . 'frmt_form_entry_meta';
            $rows  = $wpdb->get_results(
                $wpdb->prepare("SELECT meta_key, meta_value FROM {$table} WHERE entry_id = %d", $entry_id),
                ARRAY_A
            );
            if (!empty($rows)) {
                $meta_data = [];
                foreach ($rows as $row) {
                    $meta_data[$row['meta_key']] = ['value' => $row['meta_value']];
                }
                $synthetic            = new \stdClass();
                $synthetic->entry_id  = $entry_id;
                $synthetic->meta_data = $meta_data;
                $synthetic->ip        = $_SERVER['REMOTE_ADDR'] ?? '';
                $entry = $synthetic;
            }
        }

        if ($entry && !is_wp_error($entry)) {
            self::on_forminator($entry, $form_id);
        } else {
            error_log('[fdash] on_forminator_modern: could not load entry ' . $entry_id . ' for form ' . $form_id . ' — submission not sent');
        }
    }

    public static function on_cf7($contact_form) {
        $submission = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
        $data = $submission ? $submission->get_posted_data() : [];
        $fields = [];
        foreach ($data as $k => $v) {
            if (str_starts_with($k, '_')) continue;
            $fields[$k] = is_array($v) ? implode(', ', $v) : (string)$v;
        }
        $payload = [
            'plugin'       => 'cf7',
            'form_id'      => (string)$contact_form->id(),
            'form_title'   => $contact_form->title(),
            'submitted_at' => gmdate('c'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fields'       => $fields,
        ];

        // CF7 doesn't persist submissions to DB, so store in a transient before
        // sending. If the push fails, the retry queue can redeliver from here.
        $transient_key = 'fdash_cf7_' . md5(wp_json_encode($payload));
        set_transient($transient_key, $payload, DAY_IN_SECONDS);

        $r = self::send($payload);
        if ($r['ok']) {
            delete_transient($transient_key);
        }
    }

    public static function on_gravity($entry, $form) {
        $fields = [];
        foreach ($form['fields'] as $f) {
            $label = $f->label ?: ('field_' . $f->id);
            $val = rgar($entry, (string)$f->id);
            if ($val === '' || $val === null) {
                $parts = [];
                foreach ((array)$entry as $k => $v) {
                    if (strpos((string)$k, $f->id . '.') === 0 && $v !== '') $parts[] = $v;
                }
                $val = implode(' ', $parts);
            }
            $fields[$label] = (string)$val;
        }
        self::send([
            'plugin'       => 'gravity',
            'form_id'      => (string)$form['id'],
            'form_title'   => $form['title'],
            'entry_id'     => (string)($entry['id'] ?? ''),
            'submitted_at' => gmdate('c'),
            'ip'           => $entry['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'   => $entry['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'fields'       => $fields,
        ]);
    }

    public static function on_wpforms($fields, $entry, $form_data, $entry_id) {
        $out = [];
        foreach ((array)$fields as $f) {
            $label      = $f['name'] ?? ('field_' . ($f['id'] ?? '?'));
            $out[$label] = is_array($f['value'] ?? '') ? wp_json_encode($f['value']) : (string)($f['value'] ?? '');
        }
        self::send([
            'plugin'       => 'wpforms',
            'form_id'      => (string)($form_data['id'] ?? ''),
            'form_title'   => $form_data['settings']['form_title'] ?? ('WPForms #' . ($form_data['id'] ?? '')),
            'entry_id'     => (string)$entry_id,
            'submitted_at' => gmdate('c'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fields'       => $out,
        ]);
    }

    public static function on_fluent($entryId, $formData, $form) {
        $fields = [];
        foreach ((array)$formData as $k => $v) {
            if (in_array($k, ['__fluent_form_embded_post_id', '_fluentform_form_instance', '_fluentformnonce'], true)) continue;
            $fields[$k] = is_array($v) ? wp_json_encode($v) : (string)$v;
        }
        self::send([
            'plugin'       => 'fluent',
            'form_id'      => (string)($form->id ?? ''),
            'form_title'   => $form->title ?? ('Fluent form #' . ($form->id ?? '')),
            'entry_id'     => (string)$entryId,
            'submitted_at' => gmdate('c'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fields'       => $fields,
        ]);
    }

    public static function on_elementor($record, $handler) {
        $raw_fields = method_exists($record, 'get') ? $record->get('fields') : [];
        $form_name  = method_exists($record, 'get_form_settings')
            ? $record->get_form_settings('form_name') : 'Elementor form';
        $fields = [];
        foreach ((array)$raw_fields as $id => $f) {
            $label = $f['title'] ?? ($f['id'] ?? $id);
            $fields[$label] = (string)($f['value'] ?? '');
        }
        self::send([
            'plugin'       => 'elementor',
            'form_id'      => method_exists($record, 'get_form_settings')
                ? (string)$record->get_form_settings('id') : 'unknown',
            'form_title'   => $form_name ?: 'Elementor form',
            'entry_id'     => method_exists($record, 'get_id') ? (string)$record->get_id() : '',
            'submitted_at' => gmdate('c'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fields'       => $fields,
        ]);
    }

    /* ===== Sender ===== */

    /**
     * Public sender: signs and POSTs the payload, queues on failure.
     * Do NOT call this from process_retries() — use send_raw() there to
     * avoid re-queuing on failure (which would reset the attempt counter).
     */
    public static function send(array $payload): array {
        $opt = get_option(self::OPT, []);
        if (empty($opt['endpoint']) || empty($opt['api_key']) || empty($opt['secret'])) {
            error_log('[fdash] Not configured, skipping send.');
            return ['ok' => false, 'code' => 0, 'body' => 'Not configured'];
        }
        $r = self::send_raw($payload);
        if (!$r['ok']) {
            self::queue_retry($payload);
            error_log('[fdash] Send failed (code ' . $r['code'] . '): ' . $r['body']);
        }
        return $r;
    }

    /**
     * Raw HTTP sender — no retry side effects.
     * Used by process_retries() so it can manage re-queuing itself.
     */
    private static function send_raw(array $payload): array {
        $opt = get_option(self::OPT, []);
        if (empty($opt['endpoint']) || empty($opt['api_key']) || empty($opt['secret'])) {
            return ['ok' => false, 'code' => 0, 'body' => 'Not configured'];
        }
        $body = wp_json_encode($payload);
        $ts   = (string)time();
        $sig  = hash_hmac('sha256', $ts . '.' . $body, $opt['secret']);

        $resp = wp_remote_post($opt['endpoint'], [
            'timeout' => 8,
            'headers' => [
                'Content-Type'      => 'application/json',
                'X-FDASH-Key'       => $opt['api_key'],
                'X-FDASH-Signature' => $sig,
                'X-FDASH-Timestamp' => $ts,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            $result = ['ok' => false, 'code' => 0, 'body' => $resp->get_error_message()];
        } else {
            $code   = (int)wp_remote_retrieve_response_code($resp);
            $result = [
                'ok'   => $code >= 200 && $code < 300,
                'code' => $code,
                'body' => (string)wp_remote_retrieve_body($resp),
            ];
        }

        // Record last send outcome for display in admin settings
        $type = $payload['type'] ?? ($payload['plugin'] ?? 'unknown');
        if ($type !== 'test') {
            update_option('fdash_last_send_result', [
                'time'   => time(),
                'ok'     => $result['ok'],
                'code'   => $result['code'],
                'error'  => $result['ok'] ? '' : $result['body'],
                'plugin' => $type,
            ], false);
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[fdash] send_raw: code=' . $result['code'] . ' ok=' . ($result['ok'] ? 'true' : 'false') . ' type=' . $type);
        }

        return $result;
    }

    private static function queue_retry(array $payload): void {
        $queue = get_option('fdash_retry_queue', []);
        $queue[] = [
            'payload'       => $payload,
            'attempts'      => 0,
            'queued_at'     => time(),
            'next_retry_at' => time() + 300, // first retry after 5 minutes
        ];

        $overflowed = false;
        if (count($queue) > 500) {
            array_shift($queue); // drop oldest to stay under cap
            $overflowed = true;
        }
        update_option('fdash_retry_queue', $queue, false);

        if ($overflowed) {
            // Alert dashboard that the queue is full — submissions are being dropped
            self::send_raw([
                'type'         => 'queue_overflow',
                'plugin'       => 'system',
                'form_id'      => 'queue-overflow',
                'form_title'   => 'Queue Overflow',
                'submitted_at' => gmdate('c'),
                'fields'       => [
                    'queue_depth' => count($queue),
                    'site_url'    => home_url(),
                ],
            ]);
        }

        if (!wp_next_scheduled('fdash_retry')) {
            wp_schedule_event(time() + 300, 'hourly', 'fdash_retry');
        }
    }

    public static function process_retries(): void {
        $queue = get_option('fdash_retry_queue', []);
        if (!$queue) return;

        $remaining = [];
        $dead      = get_option('fdash_dead_letter', []);

        // Backoff schedule: 5m, 15m, 1h, 4h, 24h
        $backoffs = [300, 900, 3600, 14400, 86400];

        foreach ($queue as $item) {
            // Skip items not yet due for their next retry
            if (!empty($item['next_retry_at']) && time() < $item['next_retry_at']) {
                $remaining[] = $item;
                continue;
            }

            // Give up after 5 attempts — move to dead letter
            if ($item['attempts'] >= 5) {
                $item['gave_up_at'] = time();
                $dead[] = $item;

                // Notify dashboard so it can log and alert
                self::send_raw([
                    'type'         => 'dead_letter',
                    'plugin'       => 'system',
                    'form_id'      => 'dead-letter',
                    'form_title'   => 'Dead Letter',
                    'submitted_at' => gmdate('c'),
                    'fields'       => [
                        'attempts'        => $item['attempts'],
                        'first_queued_at' => $item['queued_at'],
                        'payload_preview' => substr(wp_json_encode($item['payload']), 0, 300),
                        'site_url'        => home_url(),
                    ],
                ]);
                continue;
            }

            // Attempt delivery using send_raw (no re-queue side effect)
            $r = self::send_raw($item['payload']);
            if ($r['ok']) {
                // Success — drop from queue by not adding to $remaining
                continue;
            }

            // Still failing — increment attempt counter and reschedule with backoff
            $item['attempts']++;
            $item['next_retry_at'] = time() + ($backoffs[$item['attempts'] - 1] ?? 86400);
            $remaining[] = $item;
        }

        // Cap dead letter store at 200 entries
        if (count($dead) > 200) {
            $dead = array_slice($dead, -200);
        }

        update_option('fdash_retry_queue', $remaining, false);
        update_option('fdash_dead_letter', $dead, false);
    }

    /* ===== Email Health Check ===== */

    public static function detect_mailer(): string {
        if (class_exists('WPMailSMTP\WP')) return 'WP Mail SMTP';
        if (class_exists('PostmanOptions')) return 'Post SMTP';
        if (defined('JEsuspended_PHP_SMTP_DIR')) return 'Easy WP SMTP';
        if (class_exists('FluentMail\App\App')) return 'FluentSMTP';
        if (function_exists('mail')) return 'PHP mail()';
        return 'Unknown';
    }

    public static function check_email_health(bool $report = false, bool $send_email = true): array {
        $mailer    = self::detect_mailer();
        $result    = null;
        $error_msg = null;

        if ($send_email) {
            $opt     = get_option(self::OPT, []);
            $to      = !empty($opt['email_test_to']) ? $opt['email_test_to'] : get_option('admin_email');
            $subject = '[Form Dashboard] Email health check — ' . date('Y-m-d H:i:s');
            $body    = 'Automated email health check from ' . home_url() . "\n"
                     . 'Time: ' . date('Y-m-d H:i:s') . "\n"
                     . 'Sent to: ' . $to . "\n"
                     . 'This email confirms wp_mail() is working.';

            $error_handler = function($wp_error) use (&$error_msg) {
                if (is_wp_error($wp_error)) {
                    $error_msg = $wp_error->get_error_message();
                }
            };
            add_action('wp_mail_failed', $error_handler);
            $result = wp_mail($to, $subject, $body);
            remove_action('wp_mail_failed', $error_handler);
        }

        $status = $send_email ? ($result ? 'ok' : 'fail') : 'config_only';

        $out = [
            'mail_ok'     => $result,
            'error'       => $error_msg,
            'mailer'      => $mailer,
            'report_ok'   => false,
            'report_code' => 0,
            'report_body' => '',
        ];

        if ($report) {
            $r = self::send([
                'type'         => 'email_health',
                'plugin'       => 'system',
                'form_id'      => 'email-health',
                'form_title'   => 'Email Health Check',
                'submitted_at' => gmdate('c'),
                'fields'       => [
                    'status'            => $status,
                    'error'             => $error_msg ?? '',
                    'mailer'            => $mailer,
                    'php_version'       => PHP_VERSION,
                    'wp_version'        => get_bloginfo('version'),
                    'plugin_version'    => self::VERSION,
                    'wp_cron_disabled'  => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no',
                    'retry_queue_depth' => count(get_option('fdash_retry_queue', [])),
                    'dead_letter_count' => count(get_option('fdash_dead_letter', [])),
                ],
            ]);
            $out['report_ok']   = $r['ok'];
            $out['report_code'] = $r['code'];
            $out['report_body'] = $r['body'];
        }

        return $out;
    }

    public static function run_email_health_cron(): void {
        $opt = get_option(self::OPT, []);
        if (empty($opt['email_monitor'])) return;
        self::check_email_health(true, false);
    }

    public static function schedule_email_health(): void {
        $opt = get_option(self::OPT, []);
        $frequency = $opt['email_frequency'] ?? 'daily';

        $ts = wp_next_scheduled('fdash_email_health');
        if ($ts) wp_unschedule_event($ts, 'fdash_email_health');

        if (!empty($opt['email_monitor'])) {
            wp_schedule_event(time() + 60, $frequency, 'fdash_email_health');
        }
    }

    /* ===== Heartbeat ===== */

    public static function send_heartbeat(): void {
        self::send([
            'type'         => 'heartbeat',
            'plugin'       => 'system',
            'form_id'      => 'heartbeat',
            'form_title'   => 'Heartbeat',
            'submitted_at' => gmdate('c'),
            'fields'       => [
                'site_url'          => home_url(),
                'wp_version'        => get_bloginfo('version'),
                'plugin_version'    => self::VERSION,
                'wp_cron_disabled'  => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no',
                'retry_queue_depth' => count(get_option('fdash_retry_queue', [])),
                'dead_letter_count' => count(get_option('fdash_dead_letter', [])),
            ],
        ]);
        self::check_resync_request();
    }

    /**
     * Fires on every WP admin page load. Checks for a pending resync at most once
     * every 5 minutes so we don't hammer sync-status.php on every click.
     * This is the primary trigger — WP-Cron heartbeat is a fallback only.
     */
    public static function maybe_check_resync_on_admin_load(): void {
        if (get_transient('fdash_resync_admin_check')) return;
        set_transient('fdash_resync_admin_check', 1, 5 * MINUTE_IN_SECONDS);
        self::check_resync_request();
    }

    /**
     * Polls the dashboard sync-status endpoint and runs bulk sync if requested.
     * Called on every heartbeat and from the manual admin button.
     */
    public static function check_resync_request(): void {
        $opt = get_option(self::OPT, []);
        if (empty($opt['endpoint']) || empty($opt['api_key'])) return;

        $status_url = rtrim($opt['endpoint'], '/');
        // Derive sync-status URL from ingest endpoint (same base path)
        $status_url = preg_replace('/\/ingest\.php$/i', '/sync-status.php', $status_url);
        if (!str_ends_with($status_url, 'sync-status.php')) {
            $status_url = rtrim(dirname($opt['endpoint']), '/') . '/sync-status.php';
        }
        $status_url .= '?key=' . urlencode($opt['api_key']);

        $resp = wp_remote_get($status_url, ['timeout' => 8, 'sslverify' => true]);
        if (is_wp_error($resp)) {
            error_log('[fdash] check_resync_request: ' . $resp->get_error_message());
            return;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!($body['ok'] ?? false) || !($body['resync_requested'] ?? false)) return;

        if (get_transient('fdash_resync_lock')) {
            error_log('[fdash] Resync already in progress, skipping concurrent run.');
            return;
        }
        set_transient('fdash_resync_lock', 1, 1800);

        error_log('[fdash] Resync requested by dashboard — syncing all available plugins');
        $available = [];
        if (class_exists('Forminator_API')) $available[] = 'forminator';
        if (class_exists('GFAPI'))          $available[] = 'gravity';
        if (function_exists('wpforms'))     $available[] = 'wpforms';

        $results = [];
        foreach ($available as $slug) {
            $res       = self::handle_bulk_sync($slug);
            $results[] = $slug . ': ' . $res;
            error_log('[fdash] Resync result for ' . $slug . ': ' . $res);
        }
        $result = implode(' | ', $results) ?: 'No supported plugins detected.';

        // Signal dashboard that resync is complete
        self::send([
            'type'         => 'resync_complete',
            'plugin'       => 'system',
            'form_id'      => 'resync-complete',
            'form_title'   => 'Resync Complete',
            'submitted_at' => gmdate('c'),
            'fields'       => ['result' => $result],
        ]);
        delete_transient('fdash_resync_lock');
    }

    public static function schedule_heartbeat(): void {
        $ts = wp_next_scheduled('fdash_heartbeat');
        if ($ts) {
            // If already scheduled at the wrong recurrence (e.g. 'daily' from v1.1),
            // clear it so we can re-register at the correct frequency.
            $recurrence = wp_get_schedule('fdash_heartbeat');
            if ($recurrence !== 'hourly') {
                wp_unschedule_event($ts, 'fdash_heartbeat');
                $ts = false;
            }
        }
        if (!$ts) {
            wp_schedule_event(time() + 60, 'hourly', 'fdash_heartbeat');
        }
    }

    /* ===== Auto-Update System ===== */

    public static function register_update_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api',                           [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('auto_update_plugin',                    [__CLASS__, 'maybe_auto_update'], 10, 2);
    }

    public static function check_for_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $update_url = self::UPDATE_JSON_URL;
        $cache_key  = 'fdash_update_check';
        $remote    = get_transient($cache_key);

        if ($remote === false) {
            $response = wp_remote_get($update_url, [
                'timeout'    => 10,
                'sslverify'  => true,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                set_transient($cache_key, ['_error' => true], 30 * MINUTE_IN_SECONDS);
                return $transient;
            }

            $remote = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($remote) || empty($remote['version'])) {
                set_transient($cache_key, ['_error' => true], 30 * MINUTE_IN_SECONDS);
                return $transient;
            }

            set_transient($cache_key, $remote, 6 * HOUR_IN_SECONDS);
        }

        if (!empty($remote['_error'])) {
            return $transient;
        }
        if (!isset($remote['version']) || !version_compare($remote['version'], self::VERSION, '>')) {
            return $transient;
        }

        // Reject any download_url that doesn't point to our own GitHub releases.
        $downloadUrl = $remote['download_url'] ?? '';
        if (!str_starts_with($downloadUrl, 'https://github.com/Osamaislam1/form-dashboard/releases/download/')) {
            return $transient;
        }

        $plugin_slug = 'form-dashboard-bridge/form-dashboard-bridge.php';
        $transient->response[$plugin_slug] = (object)[
            'id'           => $plugin_slug,
            'slug'         => 'form-dashboard-bridge',
            'plugin'       => $plugin_slug,
            'new_version'  => $remote['version'],
            'url'          => $remote['sections']['changelog'] ?? '',
            'package'      => $downloadUrl,
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
            'tested'       => $remote['tested']       ?? '',
            'requires_php' => $remote['requires_php'] ?? '',
            'compatibility'=> new \stdClass(),
        ];

        return $transient;
    }

    public static function plugin_info($result, string $action, object $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'form-dashboard-bridge') {
            return $result;
        }

        $cache_key = 'fdash_update_check';
        $remote    = get_transient($cache_key);

        if (!is_array($remote) || !empty($remote['_error'])) {
            return $result;
        }

        return (object)[
            'name'         => 'Form Dashboard Bridge',
            'slug'         => 'form-dashboard-bridge',
            'version'      => $remote['version'],
            'author'       => 'You',
            'requires'     => $remote['requires']     ?? '5.8',
            'tested'       => $remote['tested']       ?? '',
            'requires_php' => $remote['requires_php'] ?? '8.0',
            'last_updated' => $remote['last_updated'] ?? '',
            'sections'     => [
                'description' => $remote['sections']['description'] ?? '',
                'changelog'   => $remote['sections']['changelog']   ?? '',
            ],
            'download_link'=> $remote['download_url'],
        ];
    }

    public static function maybe_auto_update($update, object $item): ?bool {
        if (isset($item->plugin) && $item->plugin === 'form-dashboard-bridge/form-dashboard-bridge.php') {
            $opt = get_option(self::OPT, []);
            // Default to true on fresh installs (key not yet saved).
            return isset($opt['auto_update']) ? $opt['auto_update'] === '1' : true;
        }
        return $update;
    }
}

add_action('init', ['Form_Dashboard_Bridge', 'init']);
add_action('init', ['Form_Dashboard_Bridge', 'register_update_hooks']);
add_action('fdash_retry',        ['Form_Dashboard_Bridge', 'process_retries']);
add_action('fdash_email_health', ['Form_Dashboard_Bridge', 'run_email_health_cron']);
add_action('fdash_heartbeat',    ['Form_Dashboard_Bridge', 'send_heartbeat']);
add_action('update_option_' . Form_Dashboard_Bridge::OPT, ['Form_Dashboard_Bridge', 'schedule_email_health']);

register_activation_hook(__FILE__, function () {
    Form_Dashboard_Bridge::schedule_heartbeat();
});

register_deactivation_hook(__FILE__, function () {
    foreach (['fdash_retry', 'fdash_email_health', 'fdash_heartbeat'] as $hook) {
        $ts = wp_next_scheduled($hook);
        if ($ts) wp_unschedule_event($ts, $hook);
    }
});
