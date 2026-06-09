/**
 * Dynamisk ViVa Vind Data - Version 24
 * Fullständig klass-struktur. Ingen wrapper krävs.
 * Användning: [vivavind_v24 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V24' ) ) {

    class Viva_Wind_Widget_V24 {

        const VERSION           = '24';
        const SHORTCODE         = 'vivavind_v24'; 
        const CACHE_TTL         = 90; 
        const REFRESH_MS        = 120000; 

        private static function get_config( $key ) {
            $config = [
                'ajax_action' => 'refresh_viva_wind_v24',
                'nonce_name'  => 'viva_wind_nonce_v24',
                'transient'   => 'viva_api_raw_v24_',
            ];
            return isset( $config[$key] ) ? $config[$key] : '';
        }

        public static function init() {
            add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
            add_action( 'wp_ajax_' . self::get_config('ajax_action'), [ __CLASS__, 'ajax_handler' ] );
            add_action( 'wp_ajax_nopriv_' . self::get_config('ajax_action'), [ __CLASS__, 'ajax_handler' ] );
        }

        public static function render_shortcode( $atts ) {
            $atts = shortcode_atts( [ 'id' => '220' ], $atts, self::SHORTCODE );
            $station_id = intval( $atts['id'] );
            $v = self::VERSION;

            ob_start();

            // CSS för Inline-flex layout (Sida vid sida-logiken)
            if ( ! defined( "VIVA_CSS_RENDERED_V{$v}" ) ) {
                define( "VIVA_CSS_RENDERED_V{$v}", true );
                echo '<style type="text/css">
                    .viva-card-v'.$v.' {
                        display: inline-flex !important;
                        flex-direction: column !important;
                        justify-content: space-between !important;
                        align-items: center !important;
                        width: 180px !important;
                        height: 180px !important;
                        margin: 6px !important;
                        padding: 12px 10px !important;
                        background-color: #BFEEF2 !important;
                        border: 1px solid #e2e8f0 !important;
                        border-radius: 10px !important;
                        box-sizing: border-box !important;
                        vertical-align: top !important;
                        font-family: sans-serif !important;
                        text-align: center !important;
                    }
                    .viva-card-v'.$v.' h4 {
                        margin: 0 0 6px 0 !important;
                        font-size: 22px !important;
                        font-weight: 600 !important;
                        border-bottom: 1px solid #cbd5e1 !important;
                        padding-bottom: 4px !important;
                        width: 100% !important;
                        white-space: nowrap !important;
                        overflow: hidden !important;
                        text-overflow: ellipsis !important;
                    }
                    .viva-body-v'.$v.' { display: flex; flex-direction: column; justify-content: center; flex-grow: 1; }
                    .viva-speed-v'.$v.' { font-size: 26px !important; font-weight: bold !important; color: #0f172a !important; }
                    .viva-footer-v'.$v.' { font-size: 10px !important; color: #64748b !important; margin-top: auto !important; }
                </style>';
            }

            $unique_id = "viva-container-v{$v}-{$station_id}";
            echo '<div id="' . esc_attr( $unique_id ) . '" class="viva-card-v' . $v . '" data-station="' . $station_id . '">';
            echo self::get_widget_content( $station_id );
            echo '</div>';
            
            // Auto-refresh script
            ?>
            <script type="text/javascript">
            setInterval(function() {
                var el = document.getElementById('<?php echo esc_js($unique_id); ?>');
                if(!el) return;
                var formData = new FormData();
                formData.append('action', '<?php echo self::get_config('ajax_action'); ?>');
                formData.append('station_id', '<?php echo $station_id; ?>');
                formData.append('security', '<?php echo wp_create_nonce(self::get_config('nonce_name')); ?>');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
                .then(r => r.text()).then(html => { if(html.trim()) el.innerHTML = html; });
            }, <?php echo self::REFRESH_MS; ?>);
            </script>
            <?php
            return ob_get_clean();
        }

        private static function get_widget_content( $station_id ) {
            $transient_key = self::get_config('transient') . $station_id;
            $data = get_transient( $transient_key );

            if ( false === $data ) {
                $response = wp_remote_get("https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id);
                $json = json_decode(wp_remote_retrieve_body($response), true);
                $data = $json['GetSingleStationResult'] ?? ['Name' => 'Error', 'Samples' => []];
                set_transient( $transient_key, $data, self::CACHE_TTL );
            }

            $name  = esc_html( $data['Name'] ?? 'Station' );
            $speed = $data['Samples'][0]['Value'] ?? '--';
            
            $html = '<h4>' . $name . '</h4>';
            $html .= '<div class="viva-body-v'.self::VERSION.'"><div class="viva-speed-v'.self::VERSION.'">' . esc_html($speed) . '</div></div>';
            $html .= '<div class="viva-footer-v'.self::VERSION.'">Uppdaterad: ' . current_time('H:i') . '</div>';
            return $html;
        }

        public static function ajax_handler() {
            check_ajax_referer( self::get_config('nonce_name'), 'security' );
            echo self::get_widget_content( intval( $_POST['station_id'] ) );
            wp_die();
        }
    }
    Viva_Wind_Widget_V24::init();
}