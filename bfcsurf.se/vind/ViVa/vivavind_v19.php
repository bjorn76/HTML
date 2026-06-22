/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh - Version 19
 * Ny modern design med centraliserade CSS-klasser, runda hörn och avskiljare.
 * Usage: [vivavind_v19 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V19' ) ) {

    class Viva_Wind_Widget_V19 {

        // =========================================================================
        // CENTRALISERAD KONFIGURATION - ÄNDRA ENDAST HÄR VID NY VERSION
        // =========================================================================
        const VERSION     = '19';
        const SHORTCODE   = 'vivavind_v19'; 
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
         * Datatvätt och strukturering.
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

                foreach ( $samples as $sample ) {
                    $name    = isset( $sample['Name'] ) ? trim( $sample['Name'] ) : 'Unknown';
                    $raw_val = isset( $sample['Value'] ) ? strval( $sample['Value'] ) : '';
                    $unit    = isset( $sample['Unit'] ) ? trim( $sample['Unit'] ) : '';

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
         * HTML-presentation med renodlade CSS-klasser (Ingen hårdkodad inline-styling).
         */
        private static function render_html( $data ) {
            $name    = esc_html( $data['name'] );
            $heading = esc_html( $data['heading'] );
            $updated = esc_html( $data['updated'] );

            $bywind_val    = '';
            $medelwind_val = '';

            foreach ( $data['features'] as $key => $val ) {
                $low_key = mb_strtolower( $key, 'UTF-8' );
                if ( false !== strpos( $low_key, 'bywind' ) || false !== strpos( $low_key, 'vindby' ) || false !== strpos( $low_key, 'byvind' ) || false !== strpos( $low_key, 'gust' ) ) {
                    $bywind_val = 'Byvind: ' . $val;
                }
                if ( false !== strpos( $low_key, 'medelwind' ) || false !== strpos( $low_key, 'hastighet' ) || false !== strpos( $low_key, 'medelvind' ) || false !== strpos( $low_key, 'speed' ) ) {
                    $medelwind_val = $val;
                }
            }

            if ( empty( $medelwind_val ) && ! empty( $data['features'] ) ) {
                $all_values = array_values( $data['features'] );
                $medelwind_val = $all_values[0];
                if ( isset( $all_values[1] ) && empty( $bywind_val ) ) {
                    $bywind_val = 'Byvind: ' . $all_values[1];
                }
            }

            if ( empty( $medelwind_val ) ) {
                $medelwind_val = 'Ingen data';
            }

            ob_start();
            ?>
            <div class="viva-card-v<?php echo self::VERSION; ?>">
                
                <div class="viva-header-v<?php echo self::VERSION; ?>">
                    <h4><?php echo $name; ?></h4>
                </div>
                
                <div class="viva-body-v<?php echo self::VERSION; ?>">
                    <?php if ( $bywind_val ) : ?>
                        <div class="viva-bywind-v<?php echo self::VERSION; ?>"><?php echo esc_html( $bywind_val ); ?></div>
                    <?php else : ?>
                        <div class="viva-bywind-v<?php echo self::VERSION; ?>" style="visibility: hidden;">&nbsp;</div>
                    <?php endif; ?>

                    <div class="viva-speed-v<?php echo self::VERSION; ?>"><?php echo esc_html( $medelwind_val ); ?></div>
                    
                    <?php if ( 'N/A' !== $heading ) : ?>
                        <div class="viva-dir-v<?php echo self::VERSION; ?>"><?php echo $heading; ?></div>
                    <?php endif; ?>
                </div>

                <?php if ( 'Unknown' !== $updated ) : ?>
                    <div class="viva-footer-v<?php echo self::VERSION; ?>"><?php echo $updated; ?></div>
                <?php endif; ?>

            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Hjälpmetod för innehåll.
         */
        private static function get_widget_content( $station_id ) {
            $raw_data = self::get_api_data( $station_id );

            if ( is_wp_error( $raw_data ) ) {
                return '<div class="viva-card-v' . self::VERSION . ' viva-error-v' . self::VERSION . '">' . esc_html( $raw_data->get_error_message() ) . '</div>';
            }

            $clean_data = self::format_data( $raw_data );
            return self::render_html( $clean_data );
        }

        /**
         * Kortkods-hanterare [vivavind_v19 id="..."]
         */
        public static function render_shortcode( $atts ) {
            $atts       = shortcode_atts( [ 'id' => '220' ], $atts, self::SHORTCODE );
            $station_id = intval( $atts['id'] );
            
            $unique_id   = "viva-container-v" . self::VERSION . "-" . $station_id;
            $ajax_url    = admin_url( 'admin-ajax.php' );
            $nonce       = wp_create_nonce( self::get_config( 'nonce_name' ) );
            $wrapper_cl  = self::get_config( 'wrapper_cl' );
            $script_id   = self::get_config( 'script_id' );
            $v           = self::VERSION;

            ob_start();
            
            // CSS-KLASSER (Skrivs bara ut en gång på sidan för optimal prestanda)
            if ( ! defined( "VIVA_WIND_CSS_RENDERED_V{$v}" ) ) {
                define( "VIVA_WIND_CSS_RENDERED_V{$v}", true );
                ?>
                <style type="text/css">
                    .viva-card-v<?php echo $v; ?> {
                        display: inline-flex;
                        flex-direction: column;
                        justify-content: space-between;
                        align-items: center;
                        vertical-align: top;
                        width: 180px;
                        height: 180px;
                        box-sizing: border-box;
                        border: 1px solid #e2e8f0; /* Ljusgrå linje */
                        border-radius: 10px;       /* 10px runda hörn */
                        padding: 12px 10px;
                        margin: 5px;
                        background-color: #BFEEF2; /* Ljusblå bakgrund */
                        font-family: sans-serif;
                        text-align: center;
                    }
                    .viva-header-v<?php echo $v; ?> {
                        width: 100%;
                        border-bottom: 1px solid #cbd5e1; /* Ljusgrått streck under namnet */
                        padding-bottom: 6px;
                        margin-bottom: 4px;
                    }
                    .viva-header-v<?php echo $v; ?> h4 {
                        margin: 0;
                        font-family: "Arial Narrow", "Helvetica Neue", sans-serif; /* Narrow font */
                        font-size: 16px; /* Större storlek */
                        font-weight: 600;
                        color: #1e293b;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    .viva-body-v<?php echo $v; ?> {
                        display: flex;
                        flex-direction: column;
                        gap: 2px;
                        justify-content: center;
                        flex-grow: 1;
                    }
                    .viva-bywind-v<?php echo $v; ?> {
                        font-size: 12px;
                        color: #475569;
                        font-weight: 500;
                    }
                    .viva-speed-v<?php echo $v; ?> {
                        font-size: 24px;
                        font-weight: bold;
                        color: #0f172a;
                        line-height: 1.1;
                    }
                    .viva-dir-v<?php echo $v; ?> {
                        font-size: 12px;
                        color: #334155;
                        font-weight: 500;
                        margin-top: 2px;
                    }
                    .viva-footer-v<?php echo $v; ?> {
                        font-size: 10px;
                        color: #64748b;
                        width: 100%;
                        margin-top: 4px;
                    }
                    .viva-error-v<?php echo $v; ?> {
                        justify-content: center;
                        font-size: 12px;
                        color: #ef4444;
                        background-color: #fef2f2;
                        border-color: #fca5a5;
                    }
                </style>
                <?php
            }

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
    Viva_Wind_Widget_V19::init();
}