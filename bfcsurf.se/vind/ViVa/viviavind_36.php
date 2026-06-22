/**
 * Dynamisk ViVa Vind Data Shortcode - Version 36
 * Refactor: Migrerad till WordPress REST API för blixtsnabb bakgrundsuppdatering.
 * Fix: Begränsad DOM-skanning för auto-fit skriptet.
 * Usage: [vivavind_v36 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V36' ) ) {

    class Viva_Wind_Widget_V36 {

        const VERSION        = '36';
        const SHORTCODE      = 'vivavind_v36'; 
        const CACHE_TTL      = 90; 
        const REFRESH_MS     = 120000; 
        const REST_NAMESPACE = 'viva-wind/v1';
        const REST_ROUTE     = '/refresh';

        private static function get_config( $key ) {
            $v = self::VERSION;
            $config = [
                'transient'   => "viva_api_raw_v{$v}_",
                'wrapper_cl'  => "viva-wind-widget-wrapper-v{$v}",
                'script_id'   => "VIVA_WIND_SCRIPT_RENDERED_V{$v}"
            ];
            return isset( $config[$key] ) ? $config[$key] : '';
        }

        public static function init() {
            add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
            add_action( 'rest_api_init', [ __CLASS__, 'register_rest_endpoint' ] );
        }

        public static function register_rest_endpoint() {
            register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, [
                'methods'             => \WP_REST_Server::READABLE, // GET-request
                'callback'            => [ __CLASS__, 'rest_handler' ],
                'permission_callback' => '__return_true', // Offentlig endpoint (läsbehörighet för alla)
                'args'                => [
                    'station_id' => [
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        }
                    ]
                ]
            ]);
        }

        private static function get_api_data( $station_id ) {
            $station_id = intval( $station_id );
            if ( $station_id <= 0 ) return new WP_Error( 'invalid_id', 'Felaktigt ID.' );

            $transient_key = self::get_config( 'transient' ) . $station_id;
            $cached_data   = get_transient( $transient_key );
            if ( $cached_data !== false ) return $cached_data;

            $url      = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
            $response = wp_remote_get( $url, [ 'timeout' => 5 ] ); // Ökad timeout till 5 sek

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
            $formatted = [
                'name'     => $api_data['Name'] ?? 'Okänd',
                'heading'  => 'N/A',
                'updated'  => 'Unknown',
                'features' => []
            ];

            $samples = $api_data['Samples'] ?? [];

            if ( ! empty( $samples ) ) {
                $firstWind = null;
                foreach ( $samples as $s ) {
                    if ( ( $s['Type'] ?? '' ) === 'wind' ) {
                        $firstWind = $s;
                        break;
                    }
                }

                if ( $firstWind ) {
                    $formatted['updated'] = $firstWind['Updated'] ?? 'Unknown';
                    $deg = $firstWind['Heading'] ?? null;

                    preg_match_all( '/[a-zA-ZÅÄÖåäö]+/', strval( $firstWind['Value'] ?? '' ), $matches );
                    $dir = implode( '', $matches[0] ?? [] );

                    if ( $deg !== null ) {
                        $formatted['heading'] = $deg . '°' . ( $dir ? " ($dir)" : '' );
                    } elseif ( $dir ) {
                        $formatted['heading'] = $dir;
                    }
                }

                foreach ( $samples as $s ) {
                    $name = trim( $s['Name'] ?? 'Unknown' );
                    $val  = preg_replace( '/[^0-9.,-]/', '', strval( $s['Value'] ?? '' ) );
                    if ( $val !== '' ) {
                        $formatted['features'][$name] = $val . ' ' . ( $s['Unit'] ?? '' );
                    }
                }
            }

            return $formatted;
        }

        private static function render_html( $data ) {
            $bywind_val = ''; $medelwind_val = '';
            foreach ( $data['features'] as $key => $val ) {
                $k = mb_strtolower( $key );
                if ( strpos( $k, 'by' ) !== false || strpos( $k, 'gust' ) !== false ) $bywind_val = 'By: ' . $val;
                if ( strpos( $k, 'medel' ) !== false || strpos( $k, 'hastighet' ) !== false || strpos( $k, 'speed' ) !== false ) $medelwind_val = $val;
            }
            if ( empty( $medelwind_val ) && ! empty( $data['features'] ) ) {
                $v = array_values( $data['features'] );
                $medelwind_val = $v[0];
                if ( empty( $bywind_val ) && isset( $v[1] ) ) $bywind_val = 'By: ' . $v[1];
            }

            ob_start();
            ?>
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 180px; height: 180px; border: 2px solid #cbd5e1; border-radius: 10px; padding: 10px; background: #f8fafc; font-family: sans-serif; text-align: center; box-sizing: border-box;">
                <div class="viva-station-name" style="width: 100%; font-size: 36px; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden;"><?php echo esc_html( $data['name'] ); ?></div>
                
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
                
                // Bygg REST URL för JavaScriptet
                $rest_url = esc_url( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
                ?>
                <script type="text/javascript">
                // Uppdaterad funktion som tar emot en specifik container som kontext
                function resizeVivaNames(context = document) {
                    context.querySelectorAll('.viva-station-name').forEach(function(el) {
                        el.style.fontSize = '36px'; // Reset
                        let size = 36;
                        while (el.scrollWidth > el.clientWidth && size > 15) {
                            size--;
                            el.style.fontSize = size + 'px';
                        }
                    });
                }
                
                document.addEventListener("DOMContentLoaded", function() {
                    resizeVivaNames(); // Kör på hela dokumentet initialt
                    
                    setInterval(function() {
                        document.querySelectorAll('.<?php echo esc_js( $wrapper_cl ); ?>').forEach(function(container) {
                            var stationId = container.getAttribute('data-station');
                            
                            // Använder GET istället för POST eftersom vi bara hämtar data
                            fetch('<?php echo $rest_url; ?>?station_id=' + stationId)
                            .then(r => r.json())
                            .then(data => { 
                                if (data && data.html && data.html.trim() !== '') {
                                    container.innerHTML = data.html;
                                    resizeVivaNames(container); // Begränsar DOM-operationen till denna widget
                                }
                            })
                            .catch(err => console.error('Viva API Refresh Error:', err));
                        });
                    }, <?php echo intval( self::REFRESH_MS ); ?>);
                });
                </script>
                <?php
            }
            return ob_get_clean();
        }

        public static function rest_handler( \WP_REST_Request $request ) {
            $station_id = intval( $request->get_param( 'station_id' ) );
            $html = self::get_widget_content( $station_id );
            
            // REST API förväntar sig data som JSON, så vi skickar tillbaka en array.
            return new \WP_REST_Response( [ 'html' => $html ], 200 );
        }
    }
    
    Viva_Wind_Widget_V36::init();
}