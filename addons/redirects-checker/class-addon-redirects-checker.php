<?php
/**
 * Redirects Checker Add-on
 * Validates redirect CSV files before importing to the Redirection plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Addon_Redirects_Checker extends RR_Addon_Base {

    private array $redirects = [];

    protected function init(): void {
        $this->slug        = 'redirects-checker';
        $this->name        = 'Redirects Checker';
        $this->description = 'Valideer redirects vóór ze live gaan → stuur door naar Redirection plugin.';
        $this->icon        = 'dashicons-randomize';
    }

    public function get_stats(): array {
        return [ 'total' => 0, 'issues' => 0 ];
    }

    public function enqueue_assets( $hook ): void {
        if ( strpos( $hook, 'rankrepair-redirects-checker' ) === false ) return;

        $css_ver = filemtime( RR_PLUGIN_DIR . 'addons/redirects-checker/redirects-checker.css' ) ?: RR_VERSION;
        wp_enqueue_style(
            'rr-redirects-checker',
            RR_PLUGIN_URL . 'addons/redirects-checker/redirects-checker.css',
            [ 'rr-admin-style' ],
            $css_ver
        );
        wp_enqueue_script(
            'rr-redirects-checker',
            RR_PLUGIN_URL . 'addons/redirects-checker/redirects-checker.js',
            [ 'jquery' ],
            RR_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function render_page(): void {
        $step          = 1;
        $results       = null;
        $filename      = '';
        $elapsed       = 0;
        $import_result = null;
        $upload_error  = '';

        if ( isset( $_POST['rr_import_to_redirection'] ) && check_admin_referer( 'rr_redirects_import' ) ) {
            $import_result = $this->import_to_redirection();

        } elseif ( isset( $_POST['rr_validate_csv'] ) && check_admin_referer( 'rr_redirects_checker' ) ) {
            $start = microtime( true );
            if ( $this->process_csv( $upload_error ) ) {
                $results  = $this->redirects;
                $filename = sanitize_file_name( $_FILES['redirect_csv']['name'] ?? 'redirects.csv' );
                $elapsed  = round( microtime( true ) - $start, 1 );
                $step     = 2;
            }
        }

        $valid_count = $warning_count = $error_count = 0;
        if ( $results ) {
            foreach ( $results as $r ) {
                if ( $r['status'] === 'error' )        $error_count++;
                elseif ( $r['status'] === 'warning' )  $warning_count++;
                else                                    $valid_count++;
            }
        }

        $redirection_active = class_exists( 'Red_Item' );
        ?>
        <div class="wrap rr-rc-page">

            <!-- ===== HEADER ===== -->
            <div class="rr-rc-header">
                <div class="rr-rc-header-left">
                    <img src="<?php echo esc_url( RR_PLUGIN_URL . 'assets/images/logoRankrepair.svg' ); ?>" alt="RankRepair" class="rr-rc-logo-img">
                <div class="rr-rc-header-divider"></div>
                    <div>
                        <div class="rr-rc-title-row">
                            <span class="rr-rc-badge-green">Redirect Checker</span>
                        </div>
                        <p class="rr-rc-subtitle">Valideer redirects vóór ze live gaan → stuur door naar Redirection plugin</p>
                    </div>
                </div>
                <div class="rr-rc-plugin-status <?php echo $redirection_active ? 'is-connected' : 'is-disconnected'; ?>">
                    <span class="dashicons dashicons-<?php echo $redirection_active ? 'yes-alt' : 'warning'; ?>"></span>
                    Redirection plugin: <?php echo $redirection_active ? 'verbonden' : 'niet actief'; ?>
                </div>
            </div>

            <!-- ===== STEPS ===== -->
            <?php $this->render_steps( $step, (bool) $results ); ?>

            <!-- ===== IMPORT RESULT ===== -->
            <?php if ( $import_result ): ?>
                <div class="rr-rc-card rr-rc-import-result">
                    <?php if ( $import_result['success'] ): ?>
                        <h2>✅ Import voltooid!</h2>
                        <ul>
                            <li><strong><?php echo $import_result['imported']; ?></strong> redirects geïmporteerd</li>
                            <?php if ( $import_result['disabled'] > 0 ): ?>
                                <li><strong><?php echo $import_result['disabled']; ?></strong> geïmporteerd als uitgeschakeld (hadden fouten)</li>
                            <?php endif; ?>
                        </ul>
                        <a href="<?php echo admin_url( 'tools.php?page=redirection.php' ); ?>" class="rr-rc-btn rr-rc-btn-primary">
                            Bekijk in Redirection plugin →
                        </a>
                        &nbsp;
                        <a href="<?php echo admin_url( 'admin.php?page=rankrepair-redirects-checker' ); ?>" class="rr-rc-btn rr-rc-btn-secondary">
                            Nieuwe CSV uploaden
                        </a>
                    <?php else: ?>
                        <div class="rr-rc-error-notice"><?php echo esc_html( $import_result['message'] ); ?></div>
                    <?php endif; ?>
                </div>

            <!-- ===== UPLOAD FORM ===== -->
            <?php elseif ( ! $results ): ?>
                <div class="rr-rc-card">
                    <?php if ( $upload_error ): ?>
                        <div class="rr-rc-error-notice"><?php echo esc_html( $upload_error ); ?></div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'rr_redirects_checker' ); ?>
                        <div class="rr-rc-dropzone" id="rr-dropzone">
                            <div class="rr-rc-dropzone-icon">📄</div>
                            <p class="rr-rc-dropzone-title">Sleep je CSV hier naartoe of <label for="rr-csv-file" class="rr-rc-browse-link">klik om te bladeren</label></p>
                            <p class="rr-rc-dropzone-hint">Formaat: Van,Naar &nbsp;|&nbsp; Optioneel: Van,Naar,Type (301/302)</p>
                            <p class="rr-rc-dropzone-filename" id="rr-filename-display"></p>
                            <input type="file" name="redirect_csv" id="rr-csv-file" accept=".csv" required style="display:none">
                        </div>
                        <div class="rr-rc-upload-actions">
                            <button type="submit" name="rr_validate_csv" class="rr-rc-btn rr-rc-btn-primary">
                                <span class="dashicons dashicons-search"></span> Valideer Redirects
                            </button>
                        </div>
                    </form>
                </div>

            <!-- ===== RESULTS ===== -->
            <?php else: ?>
                <div class="rr-rc-card rr-rc-results-card">
                    <div class="rr-rc-results-header">
                        <div>
                            <div class="rr-rc-results-title">
                                Validatieresultaten — <?php echo esc_html( $filename ); ?>
                            </div>
                            <div class="rr-rc-results-meta">
                                <?php echo count( $results ); ?> redirects geïmporteerd · Scan voltooid in <?php echo $elapsed; ?>s
                            </div>
                        </div>
                        <div class="rr-rc-filter-tabs" id="rr-filter-tabs">
                            <button class="rr-rc-filter active" data-filter="all">Alle (<?php echo count( $results ); ?>)</button>
                            <button class="rr-rc-filter rr-filter-ok" data-filter="ok">✓ OK (<?php echo $valid_count; ?>)</button>
                            <button class="rr-rc-filter rr-filter-warning" data-filter="warning">⚠ Waarschuwing (<?php echo $warning_count; ?>)</button>
                            <button class="rr-rc-filter rr-filter-error" data-filter="error">✕ Fout (<?php echo $error_count; ?>)</button>
                        </div>
                    </div>

                    <div class="rr-rc-table-wrap">
                        <table class="rr-rc-table" id="rr-results-table">
                            <thead>
                                <tr>
                                    <th class="rr-col-cb"><input type="checkbox" id="rr-check-all"></th>
                                    <th>Van URL</th>
                                    <th>Naar URL</th>
                                    <th>Type</th>
                                    <th>HTTP check</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $results as $r ) : ?>
                                    <tr class="rr-result-row rr-row-<?php echo esc_attr( $r['status'] ); ?>"
                                        data-status="<?php echo esc_attr( $r['status'] ); ?>">
                                        <td><input type="checkbox" class="rr-row-cb"></td>
                                        <td class="rr-url-cell rr-url-source"><?php echo esc_html( $r['source'] ); ?></td>
                                        <td class="rr-url-cell rr-url-target"><?php echo esc_html( $r['display_target'] ?? $r['target'] ); ?></td>
                                        <td><?php echo $this->type_badge( $r['type'] ); ?></td>
                                        <td><?php echo $this->http_check_cell( $r ); ?></td>
                                        <td><?php echo $this->status_cell( $r ); ?></td>
                                        <td><?php echo $this->action_btn( $r ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- FOOTER -->
                <div class="rr-rc-footer">
                    <div class="rr-rc-footer-stats">
                        <span><span class="rr-dot rr-dot-green"></span><?php echo $valid_count; ?> geldig</span>
                        <span><span class="rr-dot rr-dot-yellow"></span><?php echo $warning_count; ?> waarschuwingen</span>
                        <span class="rr-footer-errors"><span class="rr-dot rr-dot-red"></span><?php echo $error_count; ?> fouten — worden overgeslagen</span>
                    </div>
                    <div class="rr-rc-footer-actions">
                        <a href="<?php echo admin_url( 'admin.php?page=rankrepair-redirects-checker' ); ?>"
                           class="rr-rc-btn rr-rc-btn-secondary">CSV opnieuw uploaden</a>
                        <button class="rr-rc-btn rr-rc-btn-secondary" id="rr-export-btn"
                                data-results="<?php echo esc_attr( json_encode( $results ) ); ?>">
                            Exporteer rapport
                        </button>
                        <?php if ( $valid_count > 0 ) : ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'rr_redirects_import' ); ?>
                                <input type="hidden" name="rr_validated_data"
                                       value="<?php echo esc_attr( json_encode( $results ) ); ?>">
                                <button type="submit" name="rr_import_to_redirection" class="rr-rc-btn rr-rc-btn-green">
                                    <span class="dashicons dashicons-migrate"></span>
                                    Stuur <?php echo $valid_count; ?> naar Redirection plugin
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Step indicator
    // -------------------------------------------------------------------------

    private function render_steps( int $step, bool $has_results ): void {
        $steps = [
            [ 'icon' => '📄', 'title' => '1. CSV importeren',        'sub' => 'Upload jouw redirect bestand',        'state' => $has_results ? 'done' : 'active' ],
            [ 'icon' => '🔍', 'title' => '2. Validatie check',       'sub' => 'RankRepair controleert alles',        'state' => $has_results ? 'active' : 'waiting' ],
            [ 'icon' => '🔧', 'title' => '3. Fouten herstellen',     'sub' => 'Optioneel, corrigeer problemen',      'state' => 'waiting' ],
            [ 'icon' => '🚀', 'title' => '4. Stuur naar Redirection','sub' => 'Goedgekeurde redirects live zetten',  'state' => 'waiting' ],
        ];
        $labels = [ 'done' => 'Gereed', 'active' => 'Actieve stap', 'waiting' => 'Wacht' ];
        ?>
        <div class="rr-rc-steps">
            <?php foreach ( $steps as $i => $s ) :
                if ( $i > 0 ) echo '<div class="rr-step-divider"></div>';
            ?>
                <div class="rr-step rr-step-<?php echo $s['state']; ?>">
                    <div class="rr-step-icon"><?php echo $s['icon']; ?></div>
                    <div class="rr-step-title"><?php echo esc_html( $s['title'] ); ?></div>
                    <div class="rr-step-sub"><?php echo esc_html( $s['sub'] ); ?></div>
                    <span class="rr-step-label rr-step-label-<?php echo $s['state']; ?>"><?php echo $labels[ $s['state'] ]; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Cell renderers
    // -------------------------------------------------------------------------

    private function type_badge( string $type ): string {
        $class = $type === '302' ? 'rr-badge-amber' : 'rr-badge-blue';
        return '<span class="rr-rc-badge-type ' . $class . '">' . esc_html( $type ) . '</span>';
    }

    private function http_check_cell( array $r ): string {
        $code = $r['http_code'] ?? '';

        if ( $r['reason'] === 'loop' || $r['reason'] === 'identical' ) {
            return '<span class="rr-http rr-http-error">Loop</span>';
        }
        if ( $r['reason'] === 'chain' ) {
            return '<span class="rr-http rr-http-warning">301 → 301</span>';
        }
        if ( $r['reason'] === 'invalid_url' ) {
            return '<span class="rr-http rr-http-neutral">—</span>';
        }
        if ( ! $code ) {
            return '<span class="rr-http rr-http-neutral">—</span>';
        }
        if ( $code >= 400 ) {
            return '<span class="rr-http rr-http-error">' . $code . ' ' . $this->http_label( $code ) . '</span>';
        }
        if ( $code >= 300 ) {
            return '<span class="rr-http rr-http-warning">' . $code . '</span>';
        }
        return '<span class="rr-http rr-http-ok">' . $code . ' OK</span>';
    }

    private function http_label( int $code ): string {
        return match( $code ) {
            404 => 'Not Found',
            403 => 'Forbidden',
            500 => 'Server Error',
            default => '',
        };
    }

    private function status_cell( array $r ): string {
        return match( $r['reason'] ) {
            'loop', 'identical' => '
                <div class="rr-status-badge rr-status-error">✕ Redirect loop</div>
                <div class="rr-status-detail rr-detail-error">✕ Van en naar URL zijn identiek</div>',
            'chain' => '
                <div class="rr-status-badge rr-status-warning">⚠ Redirect chain</div>
                <div class="rr-status-detail rr-detail-warning">⚠ Redirect chain gedetecteerd</div>',
            'duplicate' => '
                <div class="rr-status-badge rr-status-warning">⚠ Duplicaat</div>
                <div class="rr-status-detail rr-detail-warning">⚠ Bron URL komt meerdere keren voor</div>',
            'invalid_url' => '
                <div class="rr-status-badge rr-status-error">✕ Ongeldige URL</div>
                <div class="rr-status-detail rr-detail-error">✕ URL formaat is onjuist</div>',
            'http_404' => '
                <div class="rr-status-badge rr-status-error">✕ Doel bestaat niet</div>
                <div class="rr-status-detail rr-detail-error">✕ Bestemmings-URL geeft 404 terug</div>',
            '302' => '
                <div class="rr-status-badge rr-status-warning">⚠ Tijdelijk</div>
                <div class="rr-status-detail rr-detail-warning">⚠ 302 tijdelijk — gebruik 301 voor SEO</div>',
            default => '<div class="rr-status-badge rr-status-ok">✓ Geldig</div>',
        };
    }

    private function action_btn( array $r ): string {
        if ( $r['status'] === 'error' ) {
            return '<button class="rr-rc-btn-action rr-btn-herstel-error">Herstel</button>';
        }
        if ( $r['status'] === 'warning' ) {
            return '<button class="rr-rc-btn-action rr-btn-herstel-warning">Herstel</button>';
        }
        return '<button class="rr-rc-btn-action rr-btn-bewerken">Bewerken</button>';
    }

    // -------------------------------------------------------------------------
    // CSV processing
    // -------------------------------------------------------------------------

    private function process_csv( string &$error ): bool {
        if ( ! isset( $_FILES['redirect_csv'] ) || $_FILES['redirect_csv']['error'] !== UPLOAD_ERR_OK ) {
            $error = 'Fout bij uploaden van het bestand.';
            return false;
        }

        $file = $_FILES['redirect_csv']['tmp_name'];
        if ( ! $this->parse_csv( $file, $error ) ) return false;

        $this->check_loops();
        $this->check_chains();
        $this->check_duplicates();
        $this->check_url_validity();
        $this->check_302();
        $this->check_http();

        return true;
    }

    private function parse_csv( string $file, string &$error ): bool {
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            $error = 'Kon het CSV-bestand niet openen.';
            return false;
        }

        // Detect BOM + skip header
        $first = fgetcsv( $handle );
        if ( $first && isset( $first[0] ) ) {
            $first[0] = ltrim( $first[0], "\xEF\xBB\xBF" );
            $lower    = strtolower( trim( $first[0] ) );
            // If not a header row, treat as data
            if ( ! in_array( $lower, [ 'van', 'source', 'from', 'url' ], true ) ) {
                $this->add_row( $first );
            }
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $this->add_row( $row );
        }
        fclose( $handle );

        if ( empty( $this->redirects ) ) {
            $error = 'Geen geldige rijen gevonden in het CSV-bestand.';
            return false;
        }
        return true;
    }

    private function add_row( array $row ): void {
        if ( count( $row ) < 2 ) return;

        $source = trim( $row[0] );
        $target = trim( $row[1] );
        $type   = isset( $row[2] ) ? trim( $row[2] ) : '301';

        if ( $source === '' || $target === '' ) return;

        // Normalise relative paths: keep as-is, full URLs also ok
        $this->redirects[] = [
            'source'         => $source,
            'target'         => $target,
            'display_target' => $target,
            'type'           => in_array( $type, [ '301', '302', '307' ], true ) ? $type : '301',
            'status'         => 'ok',
            'reason'         => '',
            'http_code'      => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Validation checks
    // -------------------------------------------------------------------------

    private function check_loops(): void {
        foreach ( $this->redirects as &$r ) {
            if ( $this->normalise( $r['source'] ) === $this->normalise( $r['target'] ) ) {
                $r['status'] = 'error';
                $r['reason'] = 'loop';
            }
        }
        unset( $r );

        // True loops via graph traversal
        $map = [];
        foreach ( $this->redirects as $r ) {
            $map[ $this->normalise( $r['source'] ) ] = $this->normalise( $r['target'] );
        }

        foreach ( $this->redirects as &$r ) {
            if ( $r['status'] === 'error' ) continue;
            $visited = [];
            $cur     = $this->normalise( $r['source'] );
            for ( $i = 0; $i < 20; $i++ ) {
                if ( in_array( $cur, $visited, true ) ) {
                    $r['status'] = 'error';
                    $r['reason'] = 'loop';
                    break;
                }
                $visited[] = $cur;
                $cur = $map[ $cur ] ?? null;
                if ( $cur === null ) break;
            }
        }
        unset( $r );
    }

    private function check_chains(): void {
        $map = [];
        foreach ( $this->redirects as $r ) {
            $map[ $this->normalise( $r['source'] ) ] = $r['target'];
        }

        foreach ( $this->redirects as &$r ) {
            if ( $r['status'] === 'error' ) continue;
            $chain  = [ $r['source'] ];
            $cur    = $this->normalise( $r['target'] );

            while ( isset( $map[ $cur ] ) && count( $chain ) < 10 ) {
                $chain[] = $r['target'];
                $cur     = $this->normalise( $map[ $cur ] );
            }

            if ( count( $chain ) > 1 ) {
                $r['status']         = 'warning';
                $r['reason']         = 'chain';
                $r['display_target'] = implode( ' → ', array_slice( $chain, 0, 3 ) );
            }
        }
        unset( $r );
    }

    private function check_duplicates(): void {
        $seen = [];
        foreach ( $this->redirects as &$r ) {
            if ( $r['status'] === 'error' ) continue;
            $key = $this->normalise( $r['source'] );
            if ( isset( $seen[ $key ] ) ) {
                $r['status'] = 'warning';
                $r['reason'] = 'duplicate';
            } else {
                $seen[ $key ] = true;
            }
        }
        unset( $r );
    }

    private function check_url_validity(): void {
        foreach ( $this->redirects as &$r ) {
            if ( $r['status'] === 'error' ) continue;
            // Only validate full URLs (starting with http/https); paths are always ok
            if ( str_starts_with( $r['source'], 'http' ) && ! filter_var( $r['source'], FILTER_VALIDATE_URL ) ) {
                $r['status'] = 'error';
                $r['reason'] = 'invalid_url';
            }
            if ( str_starts_with( $r['target'], 'http' ) && ! filter_var( $r['target'], FILTER_VALIDATE_URL ) ) {
                $r['status'] = 'error';
                $r['reason'] = 'invalid_url';
            }
        }
        unset( $r );
    }

    private function check_302(): void {
        foreach ( $this->redirects as &$r ) {
            if ( $r['status'] === 'ok' && $r['type'] === '302' ) {
                $r['status'] = 'warning';
                $r['reason'] = '302';
            }
        }
        unset( $r );
    }

    /**
     * HTTP check: only checks the target URL for valid/warning redirects.
     * Resolves full URLs only. Skips relative paths and limits to 80 checks.
     */
    private function check_http(): void {
        $checked = 0;
        foreach ( $this->redirects as &$r ) {
            if ( $r['reason'] === 'loop' || $r['reason'] === 'chain' || $r['reason'] === 'invalid_url' ) continue;
            if ( ! str_starts_with( $r['target'], 'http' ) ) continue;
            if ( $checked >= 80 ) break;

            $response = wp_remote_head( $r['target'], [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => false,
            ] );

            if ( is_wp_error( $response ) ) continue;

            $code          = wp_remote_retrieve_response_code( $response );
            $r['http_code'] = (int) $code;

            if ( (int) $code === 404 ) {
                $r['status'] = 'error';
                $r['reason'] = 'http_404';
            }
            $checked++;
        }
        unset( $r );
    }

    private function normalise( string $url ): string {
        return strtolower( rtrim( $url, '/' ) );
    }

    // -------------------------------------------------------------------------
    // Import to Redirection plugin
    // -------------------------------------------------------------------------

    private function import_to_redirection(): array {
        if ( ! isset( $_POST['rr_validated_data'] ) ) {
            return [ 'success' => false, 'message' => 'Geen data gevonden.' ];
        }

        $redirects = json_decode( stripslashes( $_POST['rr_validated_data'] ), true );
        if ( ! is_array( $redirects ) ) {
            return [ 'success' => false, 'message' => 'Ongeldige data.' ];
        }

        if ( ! class_exists( 'Red_Item' ) ) {
            return [ 'success' => false, 'message' => 'Redirection plugin is niet actief.' ];
        }

        $imported = 0;
        $disabled = 0;

        foreach ( $redirects as $r ) {
            $enabled = ( $r['status'] !== 'error' );
            $source  = parse_url( $r['source'], PHP_URL_PATH ) ?: $r['source'];
            if ( isset( parse_url( $r['source'] )['query'] ) ) {
                $source .= '?' . parse_url( $r['source'], PHP_URL_QUERY );
            }

            try {
                Red_Item::create( [
                    'url'         => $source,
                    'action_data' => [ 'url' => $r['target'] ],
                    'match_type'  => 'url',
                    'action_type' => 'url',
                    'action_code' => (int) ( $r['type'] ?? 301 ),
                    'group_id'    => 1,
                    'enabled'     => $enabled,
                    'title'       => $enabled ? '' : 'AUTO-DISABLED: ' . ( $r['reason'] ?? 'fout' ),
                ] );
                $imported++;
                if ( ! $enabled ) $disabled++;
            } catch ( \Exception $e ) {
                // skip
            }
        }

        return [ 'success' => true, 'imported' => $imported, 'disabled' => $disabled ];
    }
}

new RR_Addon_Redirects_Checker();
