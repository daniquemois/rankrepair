<?php
/**
 * Form Tester Add-on
 * Scan pages for forms, test them, and send diagnosis emails
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Form_Tester extends RR_Addon_Base {

    protected function init() {
        $this->slug = 'form-tester';
        $this->name = __('Formulieren Tester', 'rankrepair');
        $this->description = __('Scan pagina\'s op formulieren, test ze automatisch en ontvang een diagnose per e-mail.', 'rankrepair');
        $this->icon = 'dashicons-feedback';
    }

    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_form_tests';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return ['total' => 0, 'issues' => 0];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE test_status = 'failed'");

        return [
            'total'  => $total,
            'issues' => $failed,
        ];
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'rankrepair-form-tester') === false) return;

        wp_enqueue_style(
            'rr-form-tester',
            RR_PLUGIN_URL . 'addons/form-tester/form-tester.css',
            ['rr-admin-style'],
            RR_VERSION
        );

        wp_enqueue_script(
            'rr-form-tester',
            RR_PLUGIN_URL . 'addons/form-tester/form-tester.js',
            ['jquery', 'rr-admin-script'],
            RR_VERSION,
            true
        );
    }

    public function render_page() {
        $stats = $this->get_stats();
        $notification_email = get_option('rr_notification_email', get_option('admin_email'));

        global $wpdb;
        $table = $wpdb->prefix . 'rr_form_tests';
        $history = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $history = $wpdb->get_results("SELECT * FROM $table ORDER BY tested_at DESC LIMIT 20", ARRAY_A);
        }

        $this->render_header(null, __('Scan pagina\'s op formulieren, test ze en ontvang een diagnose rapport per e-mail.', 'rankrepair'));
        ?>

        <!-- Stats -->
        <div class="rr-stats-row">
            <div class="rr-stat-card">
                <div class="rr-stat-number"><?php echo esc_html($stats['total']); ?></div>
                <div class="rr-stat-label"><?php _e('Tests Uitgevoerd', 'rankrepair'); ?></div>
            </div>
            <div class="rr-stat-card rr-stat-danger">
                <div class="rr-stat-number"><?php echo esc_html($stats['issues']); ?></div>
                <div class="rr-stat-label"><?php _e('Mislukt', 'rankrepair'); ?></div>
            </div>
            <div class="rr-stat-card rr-stat-success">
                <div class="rr-stat-number"><?php echo esc_html(max(0, $stats['total'] - $stats['issues'])); ?></div>
                <div class="rr-stat-label"><?php _e('Geslaagd', 'rankrepair'); ?></div>
            </div>
        </div>

        <!-- Scan Section -->
        <div class="rr-card">
            <div class="rr-card-header">
                <h2><span class="dashicons dashicons-search"></span> <?php _e('Formulieren Scannen', 'rankrepair'); ?></h2>
            </div>
            <div class="rr-card-body">
                <p><?php _e('Voer een URL in om alle formulieren op die pagina te detecteren en te testen.', 'rankrepair'); ?></p>
                <div class="rr-scan-form-row">
                    <input type="url" id="rr-form-scan-url" class="regular-text rr-url-input-large"
                           placeholder="<?php echo esc_attr(home_url('/')); ?>"
                           value="<?php echo esc_attr(home_url('/')); ?>">
                    <button id="rr-scan-forms-btn" class="button rr-btn-primary rr-btn-lg">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Scan Formulieren', 'rankrepair'); ?>
                    </button>
                </div>

                <!-- Quick scan common pages -->
                <div class="rr-quick-scan" style="margin-top: 10px;">
                    <span class="rr-quick-label"><?php _e('Snel scannen:', 'rankrepair'); ?></span>
                    <?php
                    $pages_with_forms = get_pages(['number' => 10]);
                    foreach ($pages_with_forms as $page) {
                        $url = get_permalink($page->ID);
                        echo '<a href="#" class="rr-quick-scan-link" data-url="' . esc_attr($url) . '">' . esc_html($page->post_title) . '</a> ';
                    }
                    ?>
                    <a href="#" class="rr-quick-scan-link" data-url="<?php echo esc_attr(home_url('/contact')); ?>">Contact</a>
                </div>

                <div id="rr-form-scan-status" class="rr-import-status"></div>
            </div>
        </div>

        <!-- Detected Forms -->
        <div id="rr-detected-forms" class="rr-card" style="display:none;">
            <div class="rr-card-header">
                <h2><span class="dashicons dashicons-feedback"></span> <?php _e('Gevonden Formulieren', 'rankrepair'); ?></h2>
            </div>
            <div class="rr-card-body">
                <div id="rr-forms-list" class="rr-forms-grid">
                    <!-- Filled by JavaScript -->
                </div>
            </div>
        </div>

        <!-- Test Results -->
        <div id="rr-test-results" class="rr-card" style="display:none;">
            <div class="rr-card-header">
                <h2><span class="dashicons dashicons-clipboard"></span> <?php _e('Test Resultaten', 'rankrepair'); ?></h2>
            </div>
            <div class="rr-card-body">
                <div id="rr-test-results-content">
                    <!-- Filled by JavaScript -->
                </div>
            </div>
        </div>

        <!-- Test History -->
        <?php if (!empty($history)): ?>
        <div class="rr-card">
            <div class="rr-card-header">
                <h2><span class="dashicons dashicons-backup"></span> <?php _e('Test Geschiedenis', 'rankrepair'); ?></h2>
            </div>
            <div class="rr-card-body rr-table-responsive">
                <table class="rr-table">
                    <thead>
                        <tr>
                            <th><?php _e('Status', 'rankrepair'); ?></th>
                            <th><?php _e('Pagina', 'rankrepair'); ?></th>
                            <th><?php _e('Formulier', 'rankrepair'); ?></th>
                            <th><?php _e('Diagnose', 'rankrepair'); ?></th>
                            <th><?php _e('E-mail Verzonden', 'rankrepair'); ?></th>
                            <th><?php _e('Datum', 'rankrepair'); ?></th>
                            <th><?php _e('Acties', 'rankrepair'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $test): ?>
                            <tr class="<?php echo $test['test_status'] === 'failed' ? 'rr-row-error' : ($test['test_status'] === 'passed' ? 'rr-row-ok' : ''); ?>">
                                <td>
                                    <?php if ($test['test_status'] === 'passed'): ?>
                                        <span class="rr-status-icon rr-status-ok"><span class="dashicons dashicons-yes"></span></span>
                                    <?php elseif ($test['test_status'] === 'failed'): ?>
                                        <span class="rr-status-icon rr-status-error"><span class="dashicons dashicons-no"></span></span>
                                    <?php else: ?>
                                        <span class="rr-status-icon rr-status-unknown"><span class="dashicons dashicons-clock"></span></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($test['page_url']); ?>" target="_blank">
                                        <?php echo esc_html(wp_trim_words($test['page_url'], 4)); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($test['form_name'] ?: $test['form_id'] ?: '-'); ?></td>
                                <td class="rr-diagnosis-cell">
                                    <?php echo esc_html(wp_trim_words($test['diagnosis'], 15)); ?>
                                </td>
                                <td>
                                    <?php if ($test['email_received']): ?>
                                        <span class="rr-badge rr-badge-success"><?php _e('Ja', 'rankrepair'); ?></span>
                                    <?php else: ?>
                                        <span class="rr-badge rr-badge-neutral"><?php _e('Nee', 'rankrepair'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('d-m-Y H:i', strtotime($test['tested_at']))); ?></td>
                                <td>
                                    <?php if (!$test['email_received']): ?>
                                        <button class="button rr-btn-sm rr-btn-send-diagnosis" data-test-id="<?php echo esc_attr($test['id']); ?>">
                                            <span class="dashicons dashicons-email"></span> <?php _e('Verstuur', 'rankrepair'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Email Info -->
        <div class="rr-card">
            <div class="rr-card-body">
                <p>
                    <span class="dashicons dashicons-email"></span>
                    <?php printf(
                        __('Diagnose rapporten worden verzonden naar: <strong>%s</strong>. Wijzig dit in de %sinstellingen%s.', 'rankrepair'),
                        esc_html($notification_email),
                        '<a href="' . admin_url('admin.php?page=rankrepair-settings') . '">',
                        '</a>'
                    ); ?>
                </p>
            </div>
        </div>

        <?php
        $this->render_footer();
    }
}

new RR_Addon_Form_Tester();
