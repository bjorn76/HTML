/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh - Version 13
 * En helt objektorienterad och strukturerad arkitektur.
 * Usage: [vivavind_v13 id="220"]
 */

// Hindra direktåtkomst till filen av säkerhetsskäl
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V13' ) ) {

    class Viva_Wind_Widget_V13 {

        /**
         * Initierar shortcode och WordPress AJAX-hookar.
         */
        public static function init() {
            add_shortcode( 'vivavind_v13', [ __CLASS__, 'render_shortcode' ] );
            add_action( 'wp_ajax_refresh_viva_wind_v13', [ __CLASS__, 'ajax_handler' ] );
            add_action( 'wp_ajax_nopriv_refresh_viva_wind_v13', [ __CLASS__, 'ajax_handler' ] );
        }

        /**
         * Sido-ansvar 1: Hämtar rådata från Sjöfartsverkets API (med Transient Cache).
         */
        private static function get_api_data( $station_id ) {
            $station_id = intval( $station_id );
            if ( $station_id <= 0 ) {
                return new WP_Error( 'invalid_id', 'Felaktigt stations-ID.' );
            }

            // Unik transient-nyckel för v13
            $transient_key = 'viva_api_raw_v13_' . $station_id;
            $cached_data   = get_transient( $transient_key );

            if ( $cached_data !== false ) {
                return $cached_data;
            }

            $url  = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
            $args = [
                'timeout' => 4,
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'WordPress-ViVa-Plugin/13.0',
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

            // Kontrollera om API:et rapporterar ett internt fel för stationen
            if ( ! empty( $station_result['Felmeddelande'] ) ) {
                return new WP_Error( 'api_internal_error', $station_result['Felmeddelande'] );
            }

            set_transient( $transient_key, $station_result, 90 );

            return $station_result;
        }

        /**
         * Sido-ansvar 2: Datatvätt och omstrukturering.
         */
        private static function format_data( $api_data ) {
            $formatted = [
                'name'      => isset( $api_data['Name'] ) ? $api_data['Name'] : 'Okänd station',
                'medelvind' => null,
                'bywind'    => null,
                'riktning'  => null,
            ];

            $samples = isset( $api_data['Samples'] ) ? $api_data['Samples'] : [];

            foreach ( $samples as $sample ) {
                $name = isset( $sample['Name'] ) ? strtolower( trim( $sample['Name'] ) ) : '';
                $val  = isset( $sample['Value'] ) ? trim( $sample['Value'] ) : '';
                $unit = isset( $sample['Unit'] ) ? trim( $sample['Unit'] ) : 'm/s';

                preg_match( '/[0-9]+[.,]?[0-9]*/', $val, $matches );
                $clean_value = isset( $matches[0] ) ? $matches[0] : null;

                if ( null !== $clean_value ) {
                    $data_package = [ 'value' => $clean_value, 'unit' => $unit ];

                    if ( false !== strpos( $name, 'medelvind' ) || false !== strpos( $name, 'hastighet' ) ) {
                        $formatted['medelvind'] = $data_package;
                    } elseif ( false !== strpos( $name, 'bywind' ) ) {
                        $formatted['bywind'] = $data_package;
                    }
                }

                if ( ! empty( $sample['Heading'] ) && null === $formatted['riktning'] ) {
                    preg_match( '/[A-ZÅÄÖa-zåäö]+/u', $val, $text_matches );
                    $formatted['riktning'] = [
                        'degrees' => intval( $sample['Heading'] ),
                        'text'    => isset( $text_matches[0] ) ? trim( $text_matches[0] ) : '',
                    ];
                }
            }

            return $formatted;
        }

        /**
         * Sido-ansvar 3: HTML/CSS-presentation (Kvadrat 180x180px med svart ram).
         */
        private static function render_html( $data ) {
            $name      = esc_html( $data['name'] );
            $medelwind = isset( $data['medelvind'] ) ? esc_html( $data['medelvind']['value'] . ' ' . $data['medelvind']['unit'] ) : 'Ingen data';
            $bywind    = isset( $data['bywind'] ) ? 'By: ' . esc_html( $data['bywind']['value'] ) : '';
            
            $riktning = '';
            if ( isset( $data['riktning'] ) ) {
                $riktning = intval( $data['riktning']['degrees'] ) . '°';
                if ( ! empty( $data['riktning']['text'] ) ) {
                    $riktning .= ' (' . esc_html( $data['riktning']['text'] ) . ')';
                }
            }

            ob_start();
            ?>
            <div style="display: inline-flex; flex-direction: column; justify-content: center; align-items: center; vertical-align: top; width: 180px; height: 180px; box-sizing: border-box; border: 2px solid #000000; padding: 10px; margin: 5px; background-color: #f8fafc; font-family: sans-serif; text-align: center;">
                <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #334155;"><?php echo $name; ?></h4>
                <div style="font-size: 22px; font-weight: bold; margin-bottom: 4px;"><?php echo $medelwind; ?></div>
                <div style="font-size: 12px; color: #64748b; line-height: 1.4;">
                    <?php if ( $bywind ) echo $bywind . '<br>'; ?>
                    <?php if ( $riktning ) echo $riktning; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Hjälpmetod för att slå ihop API, formatering och HTML (Minskar kodupprepning).
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
         * Kortkods-hanterare [vivavind_v13 id="..."]
         */
        public static function render_shortcode( $atts ) {
            $atts       = shortcode_atts( [ 'id' => '220' ], $atts, 'viva_wind_v13' );
            $station_id = intval( $atts['id'] );
            
            $unique_id = "viva-container-v13-" . $station_id;
            $ajax_url  = admin_url( 'admin-ajax.php' );
            $nonce     = wp_create_nonce( 'viva_wind_refresh_nonce_v13' );

            ob_start();
            
            echo '<div id="' . esc_attr( $unique_id ) . '" class="viva-wind-widget-wrapper-v13" data-station="' . $station_id . '">';
            echo self::get_widget_content( $station_id );
            echo '</div>';

            if ( ! defined( 'VIVA_WIND_SCRIPT_RENDERED_V13' ) ) {
                define( 'VIVA_WIND_SCRIPT_RENDERED_V13', true );
                ?>
                <script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    setInterval(function() {
                        var widgets = document.querySelectorAll('.viva-wind-widget-wrapper-v13');
                        widgets.forEach(function(container) {
                            var stationId = container.getAttribute('data-station');
                            if (!stationId) return;

                            var formData = new FormData();
                            formData.append('action', 'refresh_viva_wind_v13'); // Unikt v13-anrop
                            formData.append('station_id', stationId);
                            formData.append('security', '<?php echo $nonce; ?>');

                            fetch('<?php echo esc_url( $ajax_url ); ?>', { method: 'POST', body: formData })
                            .then(response => response.text())
                            .then(html => { if (html.trim() !== '') container.innerHTML = html; })
                            .catch(error => console.error('ViVa v13 Error:', error));
                        });
                    }, 120000); // 2 minuter
                });
                </script>
                <?php
            }

            return ob_get_clean();
        }

        /**
         * AJAX-hanterare för bakgrundsuppdateringar (v13).
         */
        public static function ajax_handler() {
            check_ajax_referer( 'viva_wind_refresh_nonce_v13', 'security' );
            $station_id = isset( $_POST['station_id'] ) ? intval( $_POST['station_id'] ) : 220;
            
            echo self::get_widget_content( $station_id );
            wp_die();
        }
    }

    // Starta klassen och registrera allt i WordPress
    Viva_Wind_Widget_V13::init();
}