/**
 * Dynamisk ViVa Vind Data Shortcode - Version 33
 * Uppdatering: Uppskalad typografi (1.8x för namn, 1.2x för övrigt).
 * Usage: [vivavind_v33 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V33' ) ) {

    class Viva_Wind_Widget_V33 {

        const VERSION     = '33';
        const SHORTCODE   = 'vivavind_v33'; 
        const CACHE_TTL   = 90; 
        const REFRESH_MS  = 120000; 

        private static function get_config( $key ) {
            $v = self::VERSION;
            $config = [
                'ajax_action' => "refresh_viva_wind_v{$v}",
                'nonce_name'  => "viva_wind_refresh_nonce_v{$v}",
                'transient'   => "viva_api_raw_v{$v}_",
                'wrapper_cl'  => "viva-wind-widget-wrapper-v{$v}",
                'script_id'   => "VIVA_WIND_SCRIPT_RENDERED_V{$v}"
            ];
            return isset( $config[$key] ) ? $config[$key] : '';
        }

        public static function init() {
            $ajax_action = self::get_config( 'ajax_action' );
            add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
            add_action( "wp_ajax_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
            add_action( "wp_ajax_nopriv_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
        }

        private static function get_api_data( $station_id ) {
            $station_id = intval( $station_id );
            if ( $station_id <= 0 ) return new WP_Error( 'invalid_id', 'Felaktigt ID.' );

            $transient_key = self::get_config( 'transient' ) . $station_id;
            $cached_data   = get_transient( $transient_key );

            if ( $cached_data !== false ) return $cached_data;

            $url  = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
            $response = wp_remote_get( $url, [ 'timeout' => 4 ] );

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return new WP_Error( 'api_error', 'Kunde inte hämta data.' );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $data ) || ! isset( $data['GetSingleStationResult'] ) ) {
                return new WP_Error( 'api_invalid_json', 'Felaktigt svar.' );
            }

            $result = $data['GetSingleStationResult'];
            set_transient( $transient_key, $result, self::CACHE_TTL );
            return $result;
        }

        private static function format_data( $api_data ) {
            $formatted = [ 'name' => $api_data['Name'] ?? 'Okänd', 'heading' => 'N/A', 'updated' => 'Unknown', 'features' => [] ];
            $samples = $api_data['Samples'] ?? [];

            if ( ! empty( $samples ) ) {
                $first = $samples[0];
                $formatted['updated'] = $first['Updated'] ?? 'Unknown';
                
                $deg = $first['Heading'] ?? null;
                preg_match_all( '/[a-zA-ZÅÄÖåäö]+/', strval($first['Value'] ?? ''), $matches );
                $dir = implode( '', $matches[0] ?? [] );
                
                if ( $deg !== null ) $formatted['heading'] = $deg . '°' . ($dir ? ' (' . $dir . ')' : '');
                elseif ( $dir ) $formatted['heading'] = $dir;

                foreach ( $samples as $s ) {
                    $name = trim( $s['Name'] ?? 'Unknown' );
                    $val  = preg_replace( '/[^0-9.,-]/', '', strval( $s['Value'] ?? '' ) );
                    if ( $val !== '' ) $formatted['features'][$name] = $val . ' ' . ($s['Unit'] ?? '');
                }
            }
            return $formatted;
        }

        private static function render_html( $data ) {
            $bywind_val = ''; $medelwind_val = '';
            foreach ( $data['features'] as $key => $val ) {
                $k = mb_strtolower( $key );
                if ( strpos($k, 'by') !== false || strpos($k, 'gust') !== false ) $bywind_val = 'By: ' . $val;
                if ( strpos($k, 'medel') !== false || strpos($k, 'hastighet') !== false || strpos($k, 'speed') !== false ) $medelwind_val = $val;
            }
            if ( empty( $medelwind_val ) && ! empty( $data['features'] ) ) {
                $v = array_values( $data['features'] );
                $medelwind_val = $v[0];
                if ( empty( $bywind_val ) && isset( $v[1] ) ) $bywind_val = 'By: ' . $v[1];
            }

            ob_start();
            ?>
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 180px; height: 180px; border: 2px solid #cbd5e1; border-radius: 10px; padding: 10px; background: #f8fafc; font-family: sans-serif; text-align: center; box-sizing: border-box;">
                <h4 style="margin: 0; width: 100%; font-size: clamp(22px, 15%, 36px); color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo esc_html( $data['name'] ); ?></h4>
                
                <div style="flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <?php if ( $bywind_val ) : ?><div style="font-size: 13px; color: #64748b;"><?php echo esc_html( $bywind_val ); ?></div><?php endif; ?>
                    <div style="font-size: 26px; font-weight: bold; color: #0f172a; margin: 4px 0;"><?php echo esc_html( $medelwind_val ?: 'Ingen data' ); ?></div>
                    <?php if ( $data['heading'] !== 'N/A' ) : ?><div style="font-size: 13px; color: #475569;"><?php echo esc_html( $data['heading'] ); ?></div><?php endif; ?>
                </div>

                <?php if ( $data['updated'] !== 'Unknown' ) : ?><div style="font-size: 11px; color: #94a3b8;"><?php echo esc_html( $data['updated'] ); ?></div><?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }

        public static function get_widget_content( $station_id ) {
            $data = self::get_api_data( $station_id );
            if ( is_wp_error( $data ) ) return '<div style="display:flex; justify-content:center; align-items:center; width:180px; height:180px; border:2px solid #cbd5e1; border-radius:10px; padding:10px; color: #ef4444; font-size: 14px; font-family: sans-serif; text-align: center; box-sizing: border-box;">Fel vid hämtning</div>';
            return self::render_html( self::format_data( $data ) );
        }

        public static function render_shortcode( $atts ) {
            $atts = shortcode_atts( [ 'id' => '220' ], $atts, self::SHORTCODE );
            $station_id = intval( $atts['id'] );
            $wrapper_cl = self::get_config( 'wrapper_cl' );
            $unique_id  = "viva-container-v" . self::VERSION . "-" . $station_id;

            ob_start();
            echo '<div id="' . esc_attr( $unique_id ) . '" class="' . esc_attr( $wrapper_cl ) . '" data-station="' . $station_id . '" style="display: inline-block; vertical-align: top; margin: 4px;">';
            echo self::get_widget_content( $station_id );
            echo '</div>';

            if ( ! defined( self::get_config( 'script_id' ) ) ) {
                define( self::get_config( 'script_id' ), true );
                ?>
                <script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    setInterval(function() {
                        document.querySelectorAll('.<?php echo esc_js( $wrapper_cl ); ?>').forEach(function(container) {
                            var stationId = container.getAttribute('data-station');
                            var formData = new FormData();
                            formData.append('action', '<?php echo esc_js( self::get_config( 'ajax_action' ) ); ?>');
                            formData.append('station_id', stationId);
                            formData.append('security', '<?php echo esc_js( wp_create_nonce( self::get_config( 'nonce_name' ) ) ); ?>');

                            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: formData })
                            .then(r => r.text())
                            .then(html => { if (html.trim() !== '') container.innerHTML = html; });
                        });
                    }, <?php echo intval( self::REFRESH_MS ); ?>);
                });
                </script>
                <?php
            }
            return ob_get_clean();
        }

        public static function ajax_handler() {
            check_ajax_referer( self::get_config( 'nonce_name' ), 'security' );
            echo self::get_widget_content( intval( $_POST['station_id'] ?? 220 ) );
            wp_die();
        }
    }
    Viva_Wind_Widget_V33::init();
}