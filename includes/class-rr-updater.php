<?php
/**
 * GitHub Auto-Updater voor RankRepair
 *
 * Controleert GitHub Releases op nieuwe versies en toont
 * update-notificaties in het WordPress dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Updater {

    private string $github_user  = 'daniquemois';
    private string $github_repo  = 'rankrepair';
    private string $plugin_file;
    private string $plugin_slug;
    private string $current_version;
    private ?object $release_cache = null;

    public function __construct( string $plugin_file, string $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->current_version = $current_version;
    }

    public function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
    }

    /**
     * Haal de laatste GitHub release op (gecached 12 uur).
     */
    private function get_latest_release(): ?object {
        if ( $this->release_cache !== null ) {
            return $this->release_cache;
        }

        $cache_key = 'rr_github_release';
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            $this->release_cache = $cached;
            return $cached;
        }

        $url      = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $response = wp_remote_get( $url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $release->tag_name ) ) {
            return null;
        }

        set_transient( $cache_key, $release, HOUR_IN_SECONDS );
        $this->release_cache = $release;

        return $release;
    }

    /**
     * Geeft het versienummer terug zonder 'v'-prefix (bijv. "v1.3.0" → "1.3.0").
     */
    private function clean_version( string $tag ): string {
        return ltrim( $tag, 'vV' );
    }

    /**
     * Geeft de download-URL van de ZIP in de release terug.
     * Zoekt eerst naar een asset genaamd rankrepair.zip, daarna zipball.
     */
    private function get_download_url( object $release ): string {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( str_ends_with( $asset->name, '.zip' ) ) {
                    return $asset->browser_download_url;
                }
            }
        }

        return $release->zipball_url ?? '';
    }

    /**
     * Injecteert update-informatie in de WordPress update-transient.
     */
    public function check_for_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( $release === null ) {
            return $transient;
        }

        $latest_version = $this->clean_version( $release->tag_name );

        if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $latest_version,
                'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'     => $this->get_download_url( $release ),
                'icons'       => [],
                'banners'     => [],
                'tested'      => '',
                'requires_php'=> '7.4',
            ];
        }

        return $transient;
    }

    /**
     * Vult de plugin-informatiepop-up in WordPress in.
     */
    public function plugin_info( mixed $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ( $args->slug ?? '' ) !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( $release === null ) {
            return $result;
        }

        $latest_version = $this->clean_version( $release->tag_name );

        return (object) [
            'name'          => 'RankRepair',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $latest_version,
            'author'        => '<a href="https://rankingmasters.nl">Danique</a>',
            'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'download_link' => $this->get_download_url( $release ),
            'sections'      => [
                'description' => $release->body ?? 'RankRepair – SEO & performance plugin.',
                'changelog'   => $release->body ?? '',
            ],
            'last_updated'  => $release->published_at ?? '',
            'requires'      => '5.8',
            'requires_php'  => '7.4',
        ];
    }

    /**
     * Hernoem de uitgepakte map naar de juiste plugin-slug na installatie.
     */
    public function after_install( $response, array $hook_extra, array $result ) {
        global $wp_filesystem;

        if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
            return $result;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );
        $source     = isset( $result['destination'] ) ? (string) $result['destination'] : '';

        // Alleen verplaatsen als WP de plugin in een andere map heeft uitgepakt
        // (bv. zipball met 'daniquemois-rankrepair-<hash>/' als top-level dir).
        // Als de zip al 'rankrepair/' als root heeft, is dit overbodig en
        // destructief (oude folder zou geforced-delete worden voordat de nieuwe
        // op zijn plek staat → plugin verdwijnt bij fout).
        if ( $source && rtrim( $source, '/' ) !== rtrim( $plugin_dir, '/' ) && $wp_filesystem->exists( $source ) ) {
            // Alleen oude folder weghalen NA succesvolle move naar tijdelijke locatie.
            $tmp_target = $plugin_dir . '.new-' . substr( md5( uniqid( '', true ) ), 0, 8 );
            $moved = $wp_filesystem->move( $source, $tmp_target, false );
            if ( $moved ) {
                if ( $wp_filesystem->exists( $plugin_dir ) ) {
                    $wp_filesystem->delete( $plugin_dir, true );
                }
                $wp_filesystem->move( $tmp_target, $plugin_dir, false );
            }
            $result['destination'] = $plugin_dir;
        }

        // Re-activate alleen als WP de plugin onderweg heeft uitgezet.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active( $this->plugin_slug ) ) {
            activate_plugin( $this->plugin_slug, '', false, /* silent */ true );
        }

        return $result;
    }
}
