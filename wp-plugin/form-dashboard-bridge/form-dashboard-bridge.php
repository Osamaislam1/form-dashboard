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

        $queue_depth = count(get_option('fdash_retry_queue', []));
        $dead_count  = count(get_option('fdash_dead_letter', []));
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        ?>
        <div class="wrap">
            <h1>Form Dashboard Bridge <span style="font-size:13px;font-weight:normal;color:#888;">v<?= self::VERSION ?></span></h1>

            <?php if ($cron_disabled): ?>
            <div class="notice notice-warning"><p><strong>Warning:</strong> <code>DISABLE_WP_CRON</code> is set. Automatic retries and email health checks will not run. Configure a real server cron to call <code>wp-cron.php</code>.</p></div>
            <?php endif; ?>

            <?php if ($queue_depth > 0): ?>
            <div class="notice notice-warning"><p><strong>Retry queue:</strong> <?= $queue_depth ?> pending item<?= $queue_depth !== 1 ? 's' : '' ?>.</p></div>
            <?php endif; ?>

            <?php if ($dead_count > 0): ?>
            <div class="notice notice-error"><p><strong>Dead letter queue:</strong> <?= $dead_count ?> submission<?= $dead_count !== 1 ? 's' : '' ?> permanently failed and were reported to the dashboard. Check your dashboard dead letter log.</p></div>
            <?php endif; ?>

            <?php
            $last_send = get_option('fdash_last_send_result', null);
            if ($last_send): $ago = human_time_diff($last_send['time']); ?>
            <div class="notice notice-<?= $last_send['ok'] ? 'success' : 'warning' ?> is-dismissible">
                <p><strong>Last send:</strong> <?= esc_html($ago) ?> ago &mdash;
                <?php if ($last_send['ok']): ?>
                    HTTP <?= esc_html($last_send['code']) ?> OK (<?= esc_html($last_send['plugin']) ?>)
                <?php else: ?>
                    Failed (code <?= esc_html($last_send['code']) ?>): <?= esc_html($last_send['error']) ?>
                <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('fdash_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Ingest endpoint</th>
                        <td><input type="url" name="<?= self::OPT ?>[endpoint]"
                            value="<?= esc_attr($opt['endpoint'] ?? '') ?>" class="regular-text"
                            placeholder="https://your-dashboard.example.com/ingest.php"></td>
                    </tr>
                    <tr>
                        <th>API key</th>
                        <td><input type="text" name="<?= self::OPT ?>[api_key]"
                            value="<?= esc_attr($opt['api_key'] ?? '') ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Email monitoring</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPT ?>[email_monitor]" value="1"
                                    <?= !empty($opt['email_monitor']) ? 'checked' : '' ?>>
                                Enable email health checks
                            </label>
                            <p class="description">Detected mailer: <strong><?= esc_html(self::detect_mailer()) ?></strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Test email recipient</th>
                        <td>
                            <input type="email" name="<?= self::OPT ?>[email_test_to]"
                                value="<?= esc_attr($opt['email_test_to'] ?? get_option('admin_email')) ?>" class="regular-text"
                                placeholder="<?= esc_attr(get_option('admin_email')) ?>">
                            <p class="description">Where to send the test email. Defaults to admin email if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Check frequency</th>
                        <td>
                            <?php $freq = $opt['email_frequency'] ?? 'daily'; ?>
                            <select name="<?= self::OPT ?>[email_frequency]">
                                <option value="hourly" <?= $freq === 'hourly' ? 'selected' : '' ?>>Every hour</option>
                                <option value="twicedaily" <?= $freq === 'twicedaily' ? 'selected' : '' ?>>Twice a day</option>
                                <option value="daily" <?= $freq === 'daily' ? 'selected' : '' ?>>Once a day</option>
                            </select>
                            <p class="description">How often to run the automated email health check.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Auto-updates</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPT ?>[auto_update]" value="1"
                                    <?= !empty($opt['auto_update']) ? 'checked' : '' ?>>
                                Automatically install plugin updates when a new version is released
                            </label>
                            <p class="description">When enabled, WordPress silently updates this plugin without any admin action. When disabled, updates appear in the Plugins screen but require a manual click.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Secret</th>
                        <td><input type="password" name="<?= self::OPT ?>[secret]"
                            value="<?= esc_attr($opt['secret'] ?? '') ?>" class="regular-text">
                            <p class="description">Both values come from the dashboard when you add the site.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>

            <div style="display: flex; gap: 40px;">
                <div style="flex: 1;">
                    <h2>Connection test</h2>
                    <form method="post">
                        <?php wp_nonce_field('fdash_test'); ?>
                        <input type="hidden" name="fdash_test" value="1">
                        <?php submit_button('Send test ping', 'secondary'); ?>
                    </form>
                    <?php if ($test !== null): ?>
                        <div class="notice notice-<?= $test['ok'] ? 'success' : 'error' ?> inline">
                            <p><strong>HTTP <?= esc_html($test['code']) ?></strong>: <?= esc_html($test['body']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="flex: 1; border-left: 1px solid #ccc; padding-left: 40px;">
                    <h2>Bulk Sync Historical Data</h2>
                    <p class="description">Push existing entries from your local database to the dashboard. This only needs to be done once per plugin.</p>

                    <form method="post">
                        <?php wp_nonce_field('fdash_bulk_sync'); ?>
                        <select name="sync_plugin" required>
                            <option value="">-- Select Plugin --</option>
                            <?php if (class_exists('Forminator_API')): ?><option value="forminator">Forminator</option><?php endif; ?>
                            <?php if (defined('WPCF7_VERSION')): ?><option value="cf7" disabled>Contact Form 7 (Not stored in DB)</option><?php endif; ?>
                            <?php if (class_exists('GFAPI')): ?><option value="gravity">Gravity Forms</option><?php endif; ?>
                            <?php if (function_exists('wpforms')): ?><option value="wpforms">WPForms</option><?php endif; ?>
                        </select>
                        <input type="hidden" name="fdash_bulk_sync" value="1">
                        <?php submit_button('Sync Historical Entries', 'secondary', 'submit', false); ?>
                    </form>

                    <?php if ($sync_result): ?>
                        <div class="notice notice-info inline" style="margin-top: 15px;">
                            <p><?= wp_kses_post($sync_result) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="flex: 1; border-left: 1px solid #ccc; padding-left: 40px;">
                    <h2>Check for Resync Request</h2>
                    <p class="description">Polls the dashboard to see if a resync was requested. The daily heartbeat does this automatically; use this button to check immediately.</p>
                    <form method="post">
                        <?php wp_nonce_field('fdash_check_resync'); ?>
                        <input type="hidden" name="fdash_check_resync" value="1">
                        <?php submit_button('Check Now', 'secondary', 'submit', false); ?>
                    </form>
                    <?php if ($resync_result !== null): ?>
                        <div class="notice notice-info inline" style="margin-top: 15px;">
                            <p><?= esc_html($resync_result) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="flex: 1; border-left: 1px solid #ccc; padding-left: 40px;">
                    <h2>Email Health Check</h2>
                    <p class="description">Test whether <code>wp_mail()</code> is working on this site and report to the dashboard.</p>
                    <p class="description">Detected mailer: <strong><?= esc_html(self::detect_mailer()) ?></strong></p>

                    <form method="post">
                        <?php wp_nonce_field('fdash_email_test'); ?>
                        <input type="hidden" name="fdash_email_test" value="1">
                        <?php submit_button('Test Email Now', 'secondary', 'submit', false); ?>
                    </form>

                    <?php if ($email_test !== null): ?>
                        <div class="notice notice-<?= $email_test['mail_ok'] ? 'success' : 'error' ?> inline" style="margin-top: 15px;">
                            <p>
                                <strong>wp_mail():</strong> <?= $email_test['mail_ok'] ? '&#10003; Sent successfully' : '&#10007; Failed' ?><br>
                                <?php if (!$email_test['mail_ok'] && $email_test['error']): ?>
                                    <strong>Error:</strong> <?= esc_html($email_test['error']) ?><br>
                                <?php endif; ?>
                                <strong>Dashboard report:</strong>
                                <?php if ($email_test['report_ok']): ?>
                                    &#10003; HTTP <?= esc_html($email_test['report_code']) ?>
                                <?php else: ?>
                                    &#10007; <?= esc_html($email_test['report_body']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ===== Bulk Sync Handlers ===== */

    public static function handle_bulk_sync($plugin) {
        if (empty($plugin)) return "Please select a plugin.";

        $count = 0;
        $errors = 0;

        if ($plugin === 'forminator' && class_exists('Forminator_API')) {
            $forms = Forminator_API::get_forms();
            foreach ($forms as $form) {
                $page      = 0;
                $page_size = 100;
                do {
                    $entries = Forminator_API::get_entries($form->id, 'any', $page, $page_size);
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

                        $res = self::send([
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

                        if ($res['ok']) $count++; else $errors++;
                    }
                } while (count($entries) === $page_size);
            }
            return "<strong>Forminator Sync Complete:</strong> Successfully pushed $count entries. ($errors failed)";
        }

        if ($plugin === 'gravity' && class_exists('GFAPI')) {
            $page_size = 100;
            $offset    = 0;
            do {
                $paging  = ['offset' => $offset, 'page_size' => $page_size];
                $entries = GFAPI::get_entries(0, [], null, $paging);
                foreach ($entries as $entry) {
                    $form = GFAPI::get_form($entry['form_id']);
                    $fields = [];
                    foreach ($form['fields'] as $f) {
                        $label = $f->label ?: ('field_' . $f->id);
                        $val = rgar($entry, (string)$f->id);
                        $fields[$label] = (string)$val;
                    }
                    $res = self::send([
                        'plugin'       => 'gravity',
                        'form_id'      => (string)$entry['form_id'],
                        'form_title'   => $form['title'],
                        'entry_id'     => (string)$entry['id'],
                        'submitted_at' => $entry['date_created'],
                        'ip'           => $entry['ip'],
                        'user_agent'   => $entry['user_agent'],
                        'fields'       => $fields,
                    ]);
                    if ($res['ok']) $count++; else $errors++;
                }
                $offset += $page_size;
            } while (count($entries) === $page_size);
            return "<strong>Gravity Forms Sync Complete:</strong> Successfully pushed $count entries. ($errors failed)";
        }

        if ($plugin === 'wpforms' && function_exists('wpforms')) {
            $page     = 1;
            $per_page = 100;
            do {
                $entries = wpforms()->entry->get_entries([
                    'number' => $per_page,
                    'offset' => ($page - 1) * $per_page,
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
                    $res = self::send([
                        'plugin'       => 'wpforms',
                        'form_id'      => (string)$entry->form_id,
                        'form_title'   => $form_data['settings']['form_title'] ?? ('WPForms #' . $entry->form_id),
                        'entry_id'     => (string)$entry->entry_id,
                        'submitted_at' => $entry->date ?? gmdate('c'),
                        'ip'           => $entry->ip_address ?? '',
                        'user_agent'   => $entry->user_agent ?? '',
                        'fields'       => $out,
                    ]);
                    if ($res['ok']) $count++; else $errors++;
                }
                $page++;
            } while (count($entries) === $per_page);
            return "<strong>WPForms Sync Complete:</strong> Successfully pushed $count entries. ($errors failed)";
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

        $resp = wp_remote_get($status_url, ['timeout' => 8]);
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

        $plugin_slug = 'form-dashboard-bridge/form-dashboard-bridge.php';
        $transient->response[$plugin_slug] = (object)[
            'id'           => $plugin_slug,
            'slug'         => 'form-dashboard-bridge',
            'plugin'       => $plugin_slug,
            'new_version'  => $remote['version'],
            'url'          => $remote['sections']['changelog'] ?? '',
            'package'      => $remote['download_url'],
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
            return !empty($opt['auto_update']);
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
