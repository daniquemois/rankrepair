<?php
/**
 * Settings Class
 * Handles plugin settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Settings {

    /** API-key option names that must be encrypted at rest */
    private static $key_fields = [
        'rr_pagespeed_api_key',
        'rr_seranking_api_key',
        'rr_gemini_api_key',
    ];

    public function render() {
        $saved = false;
        if (isset($_POST['rr_save_settings']) && check_admin_referer('rr_settings_nonce')) {
            $this->save_settings();
            $saved = true;
        }

        // Decrypt for display
        $pagespeed_key = rr_decrypt_key(get_option('rr_pagespeed_api_key', ''));
        $seranking_key = rr_decrypt_key(get_option('rr_seranking_api_key', ''));
        $gemini_key    = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        $gemini_prompt = get_option('rr_gemini_prompt', '');
        $ai_provider   = get_option('rr_ai_provider', 'google');
        $ai_model      = get_option('rr_ai_model', '');

        ?>
        <div class="wrap rr-wrap">
            <div class="rr-header">
                <div class="rr-header-content">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair')); ?>" class="rr-back-link">
                        <span class="dashicons dashicons-arrow-left-alt2"></span> <?php _e('Terug naar Dashboard', 'rankrepair'); ?>
                    </a>
                    <h1><span class="dashicons dashicons-admin-generic"></span> <?php _e('Instellingen', 'rankrepair'); ?></h1>
                    <p class="rr-subtitle"><?php _e('Configureer je RankRepair plugin', 'rankrepair'); ?></p>
                </div>
            </div>

            <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0 0"><p><?php _e('Instellingen opgeslagen!', 'rankrepair'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('rr_settings_nonce'); ?>

                <!-- API Koppelingen -->
                <div class="rr-card">
                    <div class="rr-card-header">
                        <h2><span class="dashicons dashicons-admin-network"></span> <?php _e('API Koppelingen', 'rankrepair'); ?></h2>
                    </div>
                    <div class="rr-card-body">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="rr_pagespeed_api_key"><?php _e('Google PageSpeed API Key', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="rr_pagespeed_api_key" name="rr_pagespeed_api_key"
                                           value="<?php echo esc_attr($pagespeed_key); ?>" class="regular-text"
                                           placeholder="<?php echo empty($pagespeed_key) ? 'AIza...' : ''; ?>"
                                           autocomplete="new-password">
                                    <?php if (!empty($pagespeed_key)): ?>
                                    <span class="description" style="margin-left:8px;color:#059669">&#x2713; <?php _e('Sleutel opgeslagen (versleuteld)', 'rankrepair'); ?></span>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php _e('Verkrijg je API key via de', 'rankrepair'); ?>
                                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.
                                        <?php _e('Activeer de PageSpeed Insights API.', 'rankrepair'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="rr_seranking_api_key"><?php _e('SE Ranking API Key', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="rr_seranking_api_key" name="rr_seranking_api_key"
                                           value="<?php echo esc_attr($seranking_key); ?>" class="regular-text"
                                           placeholder="<?php echo empty($seranking_key) ? __('Jouw SE Ranking API key', 'rankrepair') : ''; ?>"
                                           autocomplete="new-password">
                                    <?php if (!empty($seranking_key)): ?>
                                    <span class="description" style="margin-left:8px;color:#059669">&#x2713; <?php _e('Sleutel opgeslagen (versleuteld)', 'rankrepair'); ?></span>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php _e('Optioneel. Wordt gebruikt voor het importeren van SEO data in de Meta Titels & Beschrijvingen add-on.', 'rankrepair'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="rr_ai_provider"><?php _e('AI Provider', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <select id="rr_ai_provider" name="rr_ai_provider">
                                        <option value="google" <?php selected($ai_provider, 'google'); ?>>Google AI Studio</option>
                                        <option value="openrouter" <?php selected($ai_provider, 'openrouter'); ?>>OpenRouter</option>
                                    </select>
                                    <p class="description">
                                        <strong>Google AI Studio:</strong> gratis API key via <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a>.<br>
                                        <strong>OpenRouter:</strong> via <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai</a> — geeft toegang tot honderden modellen.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="rr_gemini_api_key"><?php _e('AI API Key', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="rr_gemini_api_key" name="rr_gemini_api_key"
                                           value="<?php echo esc_attr($gemini_key); ?>" class="regular-text"
                                           placeholder="<?php echo empty($gemini_key) ? ($ai_provider === 'openrouter' ? 'sk-or-v1-...' : 'AIza...') : ''; ?>"
                                           autocomplete="new-password">
                                    <?php if (!empty($gemini_key)): ?>
                                    <span class="description" style="margin-left:8px;color:#059669">&#x2713; <?php _e('Sleutel opgeslagen (versleuteld)', 'rankrepair'); ?></span>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php _e('Wordt gebruikt om automatisch meta titels en beschrijvingen te genereren.', 'rankrepair'); ?>
                                        <?php _e('Laat leeg om de bestaande sleutel te bewaren.', 'rankrepair'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="rr_ai_model"><?php _e('AI Model', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="rr_ai_model" name="rr_ai_model"
                                           value="<?php echo esc_attr($ai_model); ?>" class="regular-text"
                                           placeholder="<?php echo esc_attr($ai_provider === 'openrouter' ? 'google/gemini-2.0-flash-001' : 'gemini-1.5-flash'); ?>">
                                    <p class="description">
                                        <?php _e('Laat leeg voor de standaard. OpenRouter voorbeelden: ', 'rankrepair'); ?>
                                        <code>google/gemini-2.0-flash-001</code>, <code>google/gemini-2.5-pro-exp-03-25</code>.<br>
                                        <?php _e('Google AI Studio voorbeelden: ', 'rankrepair'); ?>
                                        <code>gemini-1.5-flash</code>, <code>gemini-1.5-pro</code>.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="rr_gemini_prompt"><?php _e('AI Prompt', 'rankrepair'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    $default_template = rr_get_default_prompt_template();
                                    $prompt_value     = !empty($gemini_prompt) ? $gemini_prompt : $default_template;
                                    ?>
                                    <textarea id="rr_gemini_prompt" name="rr_gemini_prompt" class="large-text" rows="22"><?php echo esc_textarea($prompt_value); ?></textarea>
                                    <p class="description">
                                        <?php _e('Het volledige AI-prompt. Pas de sectie <strong>TOON &amp; STIJL</strong> aan voor jouw tone of voice.', 'rankrepair'); ?><br>
                                        <a href="#" id="rr-reset-prompt"><?php _e('↺ Reset naar standaard', 'rankrepair'); ?></a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="rr_save_settings" class="button-primary rr-btn-primary"
                           value="<?php _e('Instellingen Opslaan', 'rankrepair'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    private function save_settings() {
        // Non-key fields saved as plain text
        $plain_fields = ['rr_ai_provider', 'rr_ai_model', 'rr_gemini_prompt'];
        foreach ($plain_fields as $field) {
            if (isset($_POST[$field])) {
                $cb = ($field === 'rr_gemini_prompt') ? 'sanitize_textarea_field' : 'sanitize_text_field';
                update_option($field, call_user_func($cb, $_POST[$field]));
            }
        }

        // Key fields: encrypt if provided; keep existing value when field is left empty
        foreach (self::$key_fields as $field) {
            if (!isset($_POST[$field])) continue;
            $value = sanitize_text_field($_POST[$field]);
            if ($value === '') continue; // keep existing encrypted value
            update_option($field, rr_encrypt_key($value));
        }
    }
}
