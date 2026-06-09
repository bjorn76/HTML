/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh - Version 16
 * Robustare datamatchning mot 'Type' och isolerad regex-tvätt för byvind.
 * Usage: [vivavind_v16 id="220"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V16' ) ) {

    class Viva_Wind_Widget_V16 {

        // =========================================================================
        // CENTRALISERAD KONFIGURATION - ÄNDRA ENDAST HÄR VID NY VERSION
        // =========================================================================
        const VERSION     = '16';
        const SHORTCODE   = 'vivavind_v16'; 
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
         * Förbättrad Datatvätt (v16): Trffsäkrare matchning via fasta typer (Type).
         */
        private static function format_data( $api_data ) {
            $formatted = [
                'name'      => isset( $api_data['Name'] ) ? $api_data['Name'] : 'Okänd station',
                'medelvind' => null,
                'bywind'    => null,
                'riktning'  => null,
                'updated'   => '',
            ];

            $samples = isset( $api_data['Samples'] ) ? $api_data['Samples'] : [];

            foreach ( $samples as $sample ) {
                $type = isset( $sample['Type'] ) ? strtolower( trim( $sample['Type'] ) ) : '';
                $name = isset( $sample['Name'] ) ? strtolower( trim( $sample['Name'] ) ) : '';
                $val  = isset( $sample['Value'] ) ? trim( $sample['Value'] ) : '';
                $unit = isset( $sample['Unit'] ) ? trim( $sample['Unit'] ) : 'm/s';

                // Dra ut enbart siffran från Value (t.ex "SO 5.4" blir "5.4")
                preg_match( '/[0-9]+[.,]?[0-9]*/', $val, $matches );
                $clean_value = isset( $matches[0] ) ? $matches[0] : null;

                if ( null !== $clean_value ) {
                    $data_package = [ 'value' => $clean_value, 'unit' => $unit ];

                    // Matcha primärt mot hårdkodad API-typ, sekundärt mot strängnamn
                    if ( 'windspeed' === $type || false !== strpos( $name, 'medelvind' ) || false !== strpos( $name, 'hastighet' ) ) {
                        // Spara bara om vi inte redan fyllt den med ett säkrare 'Type'-matchat värde
                        if ( null === $formatted['medelvind'] || 'windspeed' === $type ) {
                            $formatted['medelvind'] = $data_package;
                        }
                    } 
                    
                    if ( 'windgust' === $type || false !== strpos( $name, 'bywind' ) || false !== strpos( $name, 'vindby' ) ) {
                        if ( null === $formatted['bywind'] || 'windgust' === $type ) {
                            $formatted['bywind'] = $data_package;
                        }
                    }
                }

                // Hantera vindriktning separat utan att störa vindstyrkorna
                if ( 'winddir' === $type || false !== strpos( $name, 'riktning' ) ) {
                    preg_match( '/[A-ZÅÄÖa-zåäö]+/u', $val, $text_matches );
                    $formatted['riktning'] = [
                        'degrees' => isset( $sample['Heading'] ) ? intval( $sample['Heading'] ) : intval( $clean_value ),
                        'text'    => isset( $text_matches[0] ) ? trim( $text_matches[0] ) : '',
                    ];
                }

                if ( ! empty( $sample['Updated'] ) && empty( $formatted['updated'] ) ) {
                    $formatted['updated'] = $sample['Updated'];
                }
            }

            return $formatted;
        }

        /**
         * HTML/CSS-presentation.
         */
        private static function render_html( $data ) {
            $name      = esc_html( $data['name'] );
            $medelwind = isset( $data['medelvind'] ) ? esc_html( $data['medelvind']['value'] . ' ' . $data['medelvind']['unit'] ) : 'Ingen data';
            $bywind    = isset( $data['bywind'] ) ? 'Byvind: ' . esc_html( $data['bywind']['value'] . ' ' . $data['bywind']['unit'] ) : '';
            $updated   = ! empty( $data['updated'] ) ? esc_html( $data['updated'] ) : '';
            
            $riktning = '';
            if ( isset( $data['riktning'] ) ) {
                $riktning = intval( $data['riktning']['degrees'] ) . '°';
                if ( ! empty( $data['riktning']['text'] ) ) {
                    $riktning .= ' (' . esc_html( $data['riktning']['text'] ) . ')';
                }
            }

            ob_start();
            ?>
            <div style="display: inline-flex; flex-direction: column; justify-content: space-between; align-items: center; vertical-align: top; width: 180px; height: 180px; box-sizing: border-box; border: 2px solid #000000; padding: 12px 10px; margin: 5px; background-color: #f8fafc; font-family: sans-serif; text-align: center;">
                
                <h4 style="margin: 0; font-size: 13px; color: #334155; font-weight: 600; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo $name; ?></h4>
                
                <div style="display: flex; flex-direction: column; gap: 2px; justify-content: center; flex-grow: 1; margin-top: 4px;">
                    <?php if ( $bywind ) : ?>
                        <div style="font-size: 12px; color: #64748b; font-weight: 500;"><?php echo $bywind; ?></div>
                    <?php else : ?>
                        <div style="font-size: 12px; visibility: hidden;">&nbsp;</div>
                    <?php endif; ?>

                    <div style="font-size: 24px; font-weight: bold; color: #0f172a; line-height: 1.1;"><?php echo $medelwind; ?></div>
                    
                    <?php if ( $riktning ) : ?>
                        <div style="font-size: 12px; color: #475569; margin-top: 2px; font-weight: 500;"><?php echo $riktning; ?></div>
                    <?php endif; ?>
                </div>

                <?php if ( $updated ) : ?>
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
         * Kortkods-hanterare [vivavind_v16 id="..."]
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
    Viva_Wind_Widget_V16::init();
}