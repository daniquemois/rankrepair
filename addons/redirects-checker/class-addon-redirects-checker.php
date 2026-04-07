<?php
/**
 * Redirects Checker Add-on
 * Import redirects, check for errors, and fix broken redirects
 * Unlike the Redirection plugin, this focuses ONLY on errors
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Redirects_Checker extends RR_Addon_Base {

    protected function init() {
        $this->slug = 'redirects-checker';
        $this->name = __('Redirects Checker', 'rankrepair');
        $this->description = __('Importeer redirects, controleer op fouten en los ze op. Toont alleen de problemen, niet alle correcte redirects.', 'rankrepair');
        $this->icon = 'dashicons-randomize';
    }

    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_redirects';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return ['total' => 0, 'issues' => 0];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $errors = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_error = 1");

        return [
            'total'  => $total,
            'issues' => $errors,
        ];
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'rankrepair-redirects-checker') === false) return;

        wp_enqueue_style(
            'rr-redirects-checker',
            RR_PLUGIN_URL . 'addons/redirects-checker/redirects-checker.css',
            ['rr-admin-style'],
            RR_VERSION
        );

        wp_enqueue_script(
            'rr-redirects-checker',
            RR_PLUGIN_URL . 'addons/redirects-checker/redirects-checker.js',
            ['jquery', 'rr-admin-script'],
            RR_VERSION,
            true
        );
    }

    public function render_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_redirects';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'errors';

        $where = '1=1';
        if ($filter === 'errors') {
            $where .= ' AND is_error = 1';
        } elseif ($filter === 'unchecked') {
            $where .= ' AND last_checked IS NULL';
        }

        $items = $table_exists ? $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY is_error DESC, last_checked DESC", ARRAY_A) : [];
        $stats = $this->get_stats();

        $this->render_header(null, __('Importeer redirects, controleer op fouten en los ze op met één klik. Focust alleen op problemen.', 'rankrepair'));
        ?>

        <!-- Stats -->
        <div class="rr-stats-row">
            <div class="rr-stat-card">
                <div class="rr-stat-number"><?php echo esc_html($stats['total']); ?></div>
                <div class="rr-stat-label"><?php _e('Totaal Redirects', 'rankrepair'); ?></div>
            </div>
            <div class="rr-stat-card rr-stat-danger">
                <div class="rr-stat-number"><?php echo esc_html($stats['issues']); ?></div>
                <div class="rr-stat-label"><?php _e('Fouten', 'rankrepair'); ?></div>
            </div>
            <div class="rr-stat-card rr-stat-success">
                <div class="rr-stat-number"><?php echo esc_html($stats['total'] - $stats['issues']); ?></div>
                <div class="rr-stat-label"><?php _e('Correct', 'rankrepair'); ?></div>
            </div>
        </div>

        <!-- Import & Actions -->
        <div class="rr-card">
            <div class="rr-card-header">
                <h2><span class="dashicons dashicons-upload"></span> <?php _e('Importeer & Controleer', 'rankrepair'); ?></h2>
            </div>
            <div class="rr-card-body">
                <p><?php _e('Upload een CSV/Excel bestand met kolommen: source (van), target (naar), type (301/302). Of voeg handmatig een redirect toe.', 'rankrepair'); ?></p>
                
                <div class="rr-action-row">
                    <form id="rr-redirects-import-form" enctype="multipart/form-data" class="rr-inline-form">
                        <input type="file" id="rr-redirects-csv-file" name="csv_file" accept=".csv,.xlsx,.xls">
                        <button type="submit" class="button rr-btn-primary">
                            <span class="dashicons dashicons-upload"></span> <?php _e('Importeer', 'rankrepair'); ?>
                        </button>
                    </form>

                    <button id="rr-check-all-redirects" class="button rr-btn-secondary">
                        <span class="dashicons dashicons-search"></span> <?php _e('Controleer Alle Redirects', 'rankrepair'); ?>
                    </button>
                </div>

                <!-- Manual Add -->
                <div class="rr-manual-add" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                    <h3><?php _e('Handmatig Toevoegen', 'rankrepair'); ?></h3>
                    <form id="rr-add-redirect-form" class="rr-inline-form">
                        <input type="url" id="rr-redirect-source" placeholder="<?php _e('Bron URL (van)', 'rankrepair'); ?>" class="regular-text" required>
                        <input type="url" id="rr-redirect-target" placeholder="<?php _e('Doel URL (naar)', 'rankrepair'); ?>" class="regular-text" required>
                        <select id="rr-redirect-type">
                            <option value="301">301 (Permanent)</option>
                            <option value="302">302 (Tijdelijk)</option>
                            <option value="307">307 (Tijdelijk)</option>
                        </select>
                        <button type="submit" class="button rr-btn-primary">
                            <span class="dashicons dashicons-plus-alt2"></span> <?php _e('Toevoegen', 'rankrepair'); ?>
                        </button>
                    </form>
                </div>

                <div id="rr-redirects-status" class="rr-import-status"></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="rr-filters">
            <a href="?page=rankrepair-redirects-checker&filter=errors" class="rr-filter-btn <?php echo $filter === 'errors' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-warning"></span> <?php _e('Alleen Fouten', 'rankrepair'); ?>
                <span class="count">(<?php echo esc_html($stats['issues']); ?>)</span>
            </a>
            <a href="?page=rankrepair-redirects-checker&filter=all" class="rr-filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <?php _e('Alles', 'rankrepair'); ?>
                <span class="count">(<?php echo esc_html($stats['total']); ?>)</span>
            </a>
            <a href="?page=rankrepair-redirects-checker&filter=unchecked" class="rr-filter-btn <?php echo $filter === 'unchecked' ? 'active' : ''; ?>">
                <?php _e('Niet Gecontroleerd', 'rankrepair'); ?>
            </a>
        </div>

        <!-- Results Table -->
        <?php if (!empty($items)): ?>
        <div class="rr-card">
            <div class="rr-card-body rr-table-responsive">
                <table class="rr-table rr-redirects-table">
                    <thead>
                        <tr>
                            <th><?php _e('Status', 'rankrepair'); ?></th>
                            <th><?php _e('Bron URL', 'rankrepair'); ?></th>
                            <th><?php _e('Doel URL', 'rankrepair'); ?></th>
                            <th><?php _e('Type', 'rankrepair'); ?></th>
                            <th><?php _e('HTTP Code', 'rankrepair'); ?></th>
                            <th><?php _e('Foutmelding', 'rankrepair'); ?></th>
                            <th><?php _e('Laatst Gecontroleerd', 'rankrepair'); ?></th>
                            <th><?php _e('Acties', 'rankrepair'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr class="<?php echo $item['is_error'] ? 'rr-row-error' : 'rr-row-ok'; ?>" data-id="<?php echo esc_attr($item['id']); ?>">
                                <td>
                                    <?php if ($item['is_error']): ?>
                                        <span class="rr-status-icon rr-status-error"><span class="dashicons dashicons-no"></span></span>
                                    <?php elseif ($item['last_checked']): ?>
                                        <span class="rr-status-icon rr-status-ok"><span class="dashicons dashicons-yes"></span></span>
                                    <?php else: ?>
                                        <span class="rr-status-icon rr-status-unknown"><span class="dashicons dashicons-minus"></span></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($item['source_url']); ?>" target="_blank" class="rr-url-link">
                                        <?php echo esc_html($item['source_url']); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($item['target_url']); ?>" target="_blank" class="rr-url-link">
                                        <?php echo esc_html($item['target_url']); ?>
                                    </a>
                                </td>
                                <td><span class="rr-badge rr-badge-neutral"><?php echo esc_html($item['redirect_type']); ?></span></td>
                                <td>
                                    <?php if ($item['status_code']): ?>
                                        <span class="rr-http-code <?php echo $item['status_code'] >= 400 ? 'rr-http-error' : ($item['status_code'] >= 300 ? 'rr-http-redirect' : 'rr-http-ok'); ?>">
                                            <?php echo esc_html($item['status_code']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rr-badge rr-badge-neutral">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="rr-error-message">
                                    <?php echo esc_html($item['error_message']); ?>
                                </td>
                                <td>
                                    <?php echo $item['last_checked'] ? esc_html(date('d-m-Y H:i', strtotime($item['last_checked']))) : '-'; ?>
                                </td>
                                <td class="rr-col-actions">
                                    <?php if ($item['is_error']): ?>
                                        <button class="button rr-btn-sm rr-btn-fix-redirect rr-btn-primary-sm" data-id="<?php echo esc_attr($item['id']); ?>" title="<?php _e('Redirect Activeren', 'rankrepair'); ?>">
                                            <span class="dashicons dashicons-admin-tools"></span> <?php _e('Fix', 'rankrepair'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button class="button rr-btn-sm rr-btn-delete-redirect rr-btn-danger-sm" data-id="<?php echo esc_attr($item['id']); ?>" title="<?php _e('Verwijderen', 'rankrepair'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($filter === 'errors'): ?>
            <div class="rr-empty-state rr-empty-state-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3><?php _e('Geen fouten gevonden!', 'rankrepair'); ?></h3>
                <p><?php _e('Alle gecontroleerde redirects werken correct.', 'rankrepair'); ?></p>
            </div>
        <?php else: ?>
            <div class="rr-empty-state">
                <span class="dashicons dashicons-randomize"></span>
                <h3><?php _e('Nog geen redirects geïmporteerd', 'rankrepair'); ?></h3>
                <p><?php _e('Upload een CSV bestand of voeg handmatig redirects toe.', 'rankrepair'); ?></p>
            </div>
        <?php endif; ?>

        <?php
        $this->render_footer();
    }
}

new RR_Addon_Redirects_Checker();
