<?php
/**
 * Settings Class
 * Accordeon-gebaseerde settings page. Add-ons registreren hun eigen secties
 * via de `rr_settings_sections` filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Settings {

    /** Veldtypes die versleuteld opgeslagen worden (API keys). */
    private static $encrypted_types = ['apikey'];

    public function render() {
        $saved = false;
        if (isset($_POST['rr_save_settings']) && check_admin_referer('rr_settings_nonce')) {
            $this->save_settings();
            $saved = true;
        }

        $update_cleared = false;
        if (isset($_POST['rr_check_updates']) && check_admin_referer('rr_settings_nonce')) {
            delete_transient('rr_github_release');
            delete_site_transient('update_plugins');
            $update_cleared = true;
        }

        $sections = $this->get_sections();
        ?>
        <div class="wrap rr-wrap">
            <div class="rr-header">
                <div class="rr-header-content">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair')); ?>" class="rr-back-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <?php _e('Terug naar Dashboard', 'rankrepair'); ?>
                    </a>
                    <h1><?php _e('Instellingen', 'rankrepair'); ?></h1>
                    <p class="rr-subtitle"><?php _e('Configureer je RankRepair plugin', 'rankrepair'); ?></p>
                </div>
            </div>

            <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0 0"><p><?php _e('Instellingen opgeslagen!', 'rankrepair'); ?></p></div>
            <?php endif; ?>
            <?php if ($update_cleared): ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0 0"><p><?php printf(
                wp_kses(__('Update-cache gewist. Ga naar <a href="%s">Plugins</a> om de update te zien.', 'rankrepair'), ['a' => ['href' => []]]),
                esc_url(admin_url('plugins.php'))
            ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="" class="rr-settings-form">
                <?php wp_nonce_field('rr_settings_nonce'); ?>

                <div class="rr-accordion">
                    <?php foreach ($sections as $idx => $section): ?>
                    <?php $open = $idx === 0; ?>
                    <details class="rr-accordion-item" <?php echo $open ? 'open' : ''; ?>>
                        <summary class="rr-accordion-head">
                            <span class="rr-accordion-icon"><?php echo $this->render_icon($section['icon'] ?? ''); ?></span>
                            <span class="rr-accordion-title"><?php echo esc_html($section['title']); ?></span>
                            <?php if (!empty($section['description'])): ?>
                            <span class="rr-accordion-desc"><?php echo esc_html($section['description']); ?></span>
                            <?php endif; ?>
                            <svg class="rr-accordion-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </summary>
                        <div class="rr-accordion-body">
                            <?php $this->render_fields($section['fields'] ?? []); ?>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>

                <p class="submit">
                    <input type="submit" name="rr_save_settings" class="button-primary rr-btn-primary"
                           value="<?php _e('Instellingen Opslaan', 'rankrepair'); ?>">
                    <input type="submit" name="rr_check_updates" class="button-secondary"
                           value="<?php _e('Controleer op updates', 'rankrepair'); ?>"
                           style="margin-left:8px;">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Collect settings sections. Core registers "Algemeen", add-ons register hun eigen secties
     * via de `rr_settings_sections` filter.
     */
    private function get_sections(): array {
        $sections = [
            [
                'id'       => 'general',
                'title'    => __('Algemeen', 'rankrepair'),
                'icon'     => 'general',
                'priority' => 10,
                'description' => __('API koppelingen en globale plugin instellingen', 'rankrepair'),
                'fields'   => [
                    [
                        'name'        => 'rr_pagespeed_api_key',
                        'label'       => __('Google PageSpeed API Key', 'rankrepair'),
                        'type'        => 'apikey',
                        'placeholder' => 'AIza...',
                        'description' => __('Verkrijg je API key via de <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>. Activeer de PageSpeed Insights API.', 'rankrepair'),
                    ],
                    [
                        'name'        => 'rr_seranking_api_key',
                        'label'       => __('SE Ranking API Key', 'rankrepair'),
                        'type'        => 'apikey',
                        'placeholder' => __('Jouw SE Ranking API key', 'rankrepair'),
                        'description' => __('Optioneel. Wordt gebruikt voor het importeren van SEO data in de Meta Titels & Beschrijvingen add-on.', 'rankrepair'),
                    ],
                ],
            ],
        ];

        $sections = apply_filters('rr_settings_sections', $sections);

        // Sort by priority (lower = first). Default priority = 50.
        usort($sections, fn($a, $b) => ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50));
        return $sections;
    }

    /**
     * Render een icoon voor een sectie — kiest een mooi SVG-icoon op basis van de section id.
     */
    private function render_icon(string $icon): string {
        $icons = [
            'general'         => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'meta-manager'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
            'redirects'       => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
            'images'          => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            'forms'           => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
            'structured-data' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        ];
        return $icons[$icon] ?? $icons['general'];
    }

    /**
     * Render een lijst velden als WP form-table.
     */
    private function render_fields(array $fields): void {
        if (empty($fields)) {
            echo '<p class="rr-no-fields">' . esc_html__('Geen instellingen voor deze sectie.', 'rankrepair') . '</p>';
            return;
        }
        echo '<table class="form-table">';
        foreach ($fields as $field) {
            $this->render_field($field);
        }
        echo '</table>';
    }

    private function render_field(array $f): void {
        $name    = $f['name'] ?? '';
        if (empty($name)) return;

        $type    = $f['type'] ?? 'text';
        $label   = $f['label'] ?? '';
        $desc    = $f['description'] ?? '';
        $default = $f['default'] ?? '';
        $value   = get_option($name, $default);

        // API keys decrypten voor display
        if ($type === 'apikey') {
            $value = rr_decrypt_key($value);
        }

        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td>';

        switch ($type) {
            case 'apikey':
                $placeholder = empty($value) ? ($f['placeholder'] ?? '') : '';
                echo '<input type="password" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="new-password">';
                if (!empty($value)) {
                    echo ' <span class="description" style="margin-left:8px;color:#059669">&#x2713; ' . esc_html__('Sleutel opgeslagen (versleuteld)', 'rankrepair') . '</span>';
                }
                break;

            case 'text':
                echo '<input type="text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr($f['placeholder'] ?? '') . '">';
                break;

            case 'textarea':
                $rows = (int) ($f['rows'] ?? 8);
                echo '<textarea id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" class="large-text" rows="' . $rows . '">' . esc_textarea($value) . '</textarea>';
                break;

            case 'select':
                echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
                foreach (($f['options'] ?? []) as $val => $ol) {
                    echo '<option value="' . esc_attr($val) . '"' . selected($value, $val, false) . '>' . esc_html($ol) . '</option>';
                }
                echo '</select>';
                break;

            case 'radio':
                foreach (($f['options'] ?? []) as $val => $ol) {
                    echo '<label style="margin-right:16px"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '"' . checked($value, $val, false) . '> <code>' . esc_html($val) . '</code> ' . esc_html($ol) . '</label>';
                }
                break;

            case 'html':
                // Raw HTML — addon is verantwoordelijk voor veilige output.
                // Reden: wp_kses_post strip <input>, <fieldset> etc., wat we hier wel nodig hebben.
                echo $f['html'] ?? '';
                break;

            case 'checkbox_group':
                echo '<div class="rr-field-checkgroup">';
                foreach (($f['options'] ?? []) as $opt_name => $entry) {
                    $opt_label = is_array($entry) ? ($entry[0] ?? $opt_name) : $entry;
                    $opt_desc  = is_array($entry) ? ($entry[1] ?? '')         : '';
                    $opt_val   = get_option($opt_name, '1');
                    echo '<input type="hidden" name="' . esc_attr($opt_name) . '" value="0">';
                    echo '<label class="rr-field-check">';
                    echo '<input type="checkbox" name="' . esc_attr($opt_name) . '" value="1" ' . checked($opt_val, '1', false) . '>';
                    echo '<span class="rr-field-check-body"><strong>' . esc_html($opt_label) . '</strong>';
                    if ($opt_desc) echo '<em>' . esc_html($opt_desc) . '</em>';
                    echo '</span>';
                    echo '</label>';
                }
                echo '</div>';
                break;
        }

        if (!empty($desc)) {
            echo '<p class="description">' . wp_kses_post($desc) . '</p>';
        }

        // Per-field extra content (bv. reset-knop onder prompt)
        if (!empty($f['after'])) {
            echo wp_kses_post($f['after']);
        }

        echo '</td></tr>';
    }

    /**
     * Save alle velden van alle secties.
     */
    private function save_settings(): void {
        foreach ($this->get_sections() as $section) {
            foreach (($section['fields'] ?? []) as $f) {
                $name = $f['name'] ?? '';
                $type = $f['type'] ?? 'text';
                if (empty($name) || !isset($_POST[$name])) continue;

                if ($type === 'apikey') {
                    $val = sanitize_text_field($_POST[$name]);
                    if ($val === '') continue; // keep existing encrypted value
                    update_option($name, rr_encrypt_key($val));
                    continue;
                }

                if ($type === 'textarea') {
                    update_option($name, sanitize_textarea_field(wp_unslash($_POST[$name])));
                    continue;
                }

                if ($type === 'html') {
                    continue;
                }

                if ($type === 'checkbox_group') {
                    foreach (($f['options'] ?? []) as $opt_name => $_) {
                        $val = isset($_POST[$opt_name]) ? sanitize_text_field($_POST[$opt_name]) : '0';
                        update_option($opt_name, $val === '1' ? '1' : '0');
                    }
                    continue;
                }

                update_option($name, sanitize_text_field($_POST[$name]));
            }
        }
    }
}
