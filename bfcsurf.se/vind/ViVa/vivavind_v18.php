/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh - Version 18
 * Korrigerad strängmatchning (w/v) och fallback för mätvärden.
 * Usage: [vivavind_v18 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V18' ) ) {

    class Viva_Wind_Widget_V18 {

        // =========================================================================
        // CENTRALISERAD KONFIGURATION - ÄNDRA ENDAST HÄR VID NY VERSION
        // =========================================================================
        const VERSION     = '18';
        const SHORTCODE   = 'vivavind_v18'; 
        const CACHE_TTL   = 90; 
        const REFRESH_MS  = 120000; 

        /**
         * Returnerar unika WordPress-nycklar baserade på den centrala versionen.
         */
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

        /**
         * Initierar shortcode och WordPress AJAX-hookar dynamiskt.
         */
        public static function init() {
            $ajax_action = self::get_config( 'ajax_action' );
            
            add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );
            add_action( "wp_ajax_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
            add_action( "wp_ajax_nopriv_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
        }

        /**
         * Hämtar rådata från Sjöfartsverkets API.
         */
        private static function get_api_data( $station_id ) {
            $station_id = intval( $station_id );
            if ( $station_id <= 0 ) {
                return new WP_Error( 'invalid_id', 'Felaktigt stations-ID.' );
            }

            $transient_key = self::get_config( 'transient' ) . $station_id;
            $cached_data   = get_transient( $transient_key );

            if ( $cached_data !== false ) {
                return $cached_data;
            }

            $url  = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
            $args = [
                'timeout' => 4,
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'WordPress-ViVa-Plugin/v' . self::VERSION,
                ],
            ];

            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return new WP_Error( 'api_http_error', 'Kunde inte ansluta till Sjöfartsverket.' );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $data ) || ! isset( $data['GetSingleStationResult'] ) ) {
                return new WP_Error( 'api_invalid_json', 'Felaktigt svar från API.' );
            }

            $station_result = $data['GetSingleStationResult'];

            if ( ! empty( $station_result['Felmeddelande'] ) ) {
                return new WP_Error( 'api_internal_error', $station_result['Felmeddelande'] );
            }

            set_transient( $transient_key, $station_result, self::CACHE_TTL );

            return $station_result;
        }

        /**
         * Datatvätt och strukturering - Med breddad strängmatchning för V och W.
         */
        private static function format_data( $api_data ) {
            $formatted = [
                'name'     => isset( $api_data['Name'] ) ? $api_data['Name'] : 'Okänd station',
                'heading'  => 'N/A',
                'updated'  => 'Unknown',
                'features' => []
            ];

            $samples = isset( $api_data['Samples'] ) ? $api_data['Samples'] : [];

            if ( ! empty( $samples ) ) {
                // 1. Metadata från första elementet
                $first_sample = $samples[0];
                $raw_value    = isset( $first_sample['Value'] ) ? strval( $first_sample['Value'] ) : '';
                $heading_deg  = isset( $first_sample['Heading'] ) ? $first_sample['Heading'] : null;

                preg_match_all( '/[a-zA-ZÅÄÖåäö]+/', $raw_value, $matches );
                $direction_letters = isset( $matches[0] ) ? trim( implode( '', $matches[0] ) ) : '';

                if ( null !== $heading_deg && ! empty( $direction_letters ) ) {
                    $formatted['heading'] = $heading_deg . '° (' . $direction_letters . ')';
                } elseif ( null !== $heading_deg ) {
                    $formatted['heading'] = $heading_deg . '°';
                } elseif ( ! empty( $direction_letters ) ) {
                    $formatted['heading'] = $direction_letters;
                }

                if ( ! empty( $first_sample['Updated'] ) ) {
                    $formatted['updated'] = $first_sample['Updated'];
                }

                // 2. Loopa och tvätta alla mätvärden (Säkerställer rensning via preg_replace)
                foreach ( $samples as $sample ) {
                    $name    = isset( $sample['Name'] ) ? trim( $sample['Name'] ) : 'Unknown';
                    $raw_val = isset( $sample['Value'] ) ? strval( $sample['Value'] ) : '';
                    $unit    = isset( $sample['Unit'] ) ? trim( $sample['Unit'] ) : '';

                    // Exakt samma rensning som Python-skriptets re.sub
                    $clean_value = preg_replace( '/[^0-9.,-]/', '', $raw_val );
                    $clean_value = trim( $clean_value );

                    if ( $clean_value !== '' ) {
                        $value_with_unit = ! empty( $unit ) ? $clean_value . ' ' . $unit : $clean_value;
                        $formatted['features'][$name] = $value_with_unit;
                    }
                }
            }

            return $formatted;
        }

        /**
         * HTML/CSS-presentation.
         */
        private static function render_html( $data ) {
            $name    = esc_html( $data['name'] );
            $heading = esc_html( $data['heading'] );
            $updated = esc_html( $data['updated'] );

            $bywind_val    = '';
            $medelwind_val = '';

            // Matcha nycklar oavsett om Sjöfartsverket kör engelska (w) eller svenska (v)
            foreach ( $data['features'] as $key => $val ) {
                $low_key = mb_strtolower( $key, 'UTF-8' );
                
                if ( false !== strpos( $low_key, 'bywind' ) || false !== strpos( $low_key, 'vindby' ) || false !== strpos( $low_key, 'byvind' ) || false !== strpos( $low_key, 'gust' ) ) {
                    $bywind_val = 'Byvind: ' . $val;
                }
                
                if ( false !== strpos( $low_key, 'medelwind' ) || false !== strpos( $low_key, 'hastighet' ) || false !== strpos( $low_key, 'medelvind' ) || false !== strpos( $low_key, 'speed' ) ) {
                    $medelwind_val = $val;
                }
            }

            // SMART FALLBACK: Om API:et använder helt unika namn (t.ex. bara "Vind"), ta första bästa mätvärden
            if ( empty( $medelwind_val ) && ! empty( $data['features'] ) ) {
                $all_values = array_values( $data['features'] );
                $medelwind_val = $all_values[0]; // Sätt första mätvärdet som medelvind
                if ( isset( $all_values[1] ) && empty( $bywind_val ) ) {
                    $bywind_val = 'Byvind: ' . $all_values[1];
                }
            }

            if ( empty( $medelwind_val ) ) {
                $medelwind_val = 'Ingen data';
            }

            ob_start();
            ?>
            <div style="display: inline-flex; flex-direction: column; justify-content: space-between; align-items: center; vertical-align: top; width: 180px; height: 180px; box-sizing: border-box; border: 2px solid #000000; padding: 12px 10px; margin: 5px; background-color: #f8fafc; font-family: sans-serif; text-align: center;">
                
                <h4 style="margin: 0; font-size: 13px; color: #334155; font-weight: 600; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo $name; ?></h4>
                
                <div style="display: flex; flex-direction: column; gap: 2px; justify-content: center; flex-grow: 1; margin-top: 4px;">
                    <?php if ( $bywind_val ) : ?>
                        <div style="font-size: 12px; color: #64748b; font-weight: 500;"><?php echo esc_html( $bywind_val ); ?></div>
                    <?php else : ?>
                        <div style="font-size: 12px; visibility: hidden;">&nbsp;</div>
                    <?php endif; ?>

                    <div style="font-size: 24px; font-weight: bold; color: #0f172a; line-height: 1.1;"><?php echo esc_html( $medelwind_val ); ?></div>
                    
                    <?php if ( 'N/A' !== $heading ) : ?>
                        <div style="font-size: 12px; color: #475569; margin-top: 2px; font-weight: 500;"><?php echo $heading; ?></div>
                    <?php endif; ?>
                </div>

                <?php if ( 'Unknown' !== $updated ) : ?>
                    <div style="font-size: 10px; color: #94a3b8; width: 100%; margin-top: 4px;"><?php echo $updated; ?></div>
                <?php endif; ?>

            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Hjälpmetod för att hämta färdig HTML eller felmeddelande.
         */
        private static function get_widget_content( $station_id ) {
            $raw_data = self::get_api_data( $station_id );

            if ( is_wp_error( $raw_data ) ) {
                return '<div style="display: inline-flex; justify-content: center; align-items: center; width: 180px; height: 180px; border: 2px solid #000000; padding: 10px; margin: 5px; box-sizing: border-box; font-size: 12px; color: #ef4444; text-align: center; font-family: sans-serif;">' . esc_html( $raw_data->get_error_message() ) . '</div>';
            }

            $clean_data = self::format_data( $raw_data );
            return self::render_html( $clean_data );
        }

        /**
         * Kortkods-hanterare [vivavind_v18 id="..."]
         */
        public static function render_shortcode( $atts ) {
            $atts       = shortcode_atts( [ 'id' => '220' ], $atts, self::SHORTCODE );
            $station_id = intval( $atts['id'] );
            
            $unique_id   = "viva-container-v" . self::VERSION . "-" . $station_id;
            $ajax_url    = admin_url( 'admin-ajax.php' );
            $nonce       = wp_create_nonce( self::get_config( 'nonce_name' ) );
            $wrapper_cl  = self::get_config( 'wrapper_cl' );
            $script_id   = self::get_config( 'script_id' );

            ob_start();
            
            echo '<div id="' . esc_attr( $unique_id ) . '" class="' . esc_attr( $wrapper_cl ) . '" data-station="' . $station_id . '">';
            echo self::get_widget_content( $station_id );
            echo '</div>';

            if ( ! defined( $script_id ) ) {
                define( $script_id, true );
                ?>
                <script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    setInterval(function() {
                        var widgets = document.querySelectorAll('.<?php echo esc_js( $wrapper_cl ); ?>');
                        widgets.forEach(function(container) {
                            var stationId = container.getAttribute('data-station');
                            if (!stationId) return;

                            var formData = new FormData();
                            formData.append('action', '<?php echo esc_js( self::get_config( 'ajax_action' ) ); ?>');
                            formData.append('station_id', stationId);
                            formData.append('security', '<?php echo esc_js( $nonce ); ?>');

                            fetch('<?php echo esc_url( $ajax_url ); ?>', { method: 'POST', body: formData })
                            .then(response => response.text())
                            .then(html => { if (html.trim() !== '') container.innerHTML = html; })
                            .catch(error => console.error('ViVa API Error:', error));
                        });
                    }, <?php echo intval( self::REFRESH_MS ); ?>);
                });
                </script>
                <?php
            }

            return ob_get_clean();
        }

        /**
         * AJAX-hanterare för bakgrundsuppdateringar.
         */
        public static function ajax_handler() {
            check_ajax_referer( self::get_config( 'nonce_name' ), 'security' );
            $station_id = isset( $_POST['station_id'] ) ? intval( $_POST['station_id'] ) : 220;
            
            echo self::get_widget_content( $station_id );
            wp_die();
        }
    }

    // Starta klassen och registrera allt i WordPress
    Viva_Wind_Widget_V18::init();
}