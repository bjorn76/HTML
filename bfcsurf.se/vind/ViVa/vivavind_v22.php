/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh - Version 22
 * Korrigerad Flexbox-wrapper som dödar WordPress radbrytningar och
 * fixar AJAX-containerns block-beteende.
 * * Usage: 
 * [vivavind_wrapper_v22]
 * [vivavind_v22 id="220"]
 * [vivavind_v22 id="88"]
 * [/vivavind_wrapper_v22]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Viva_Wind_Widget_V22' ) ) {

    class Viva_Wind_Widget_V22 {

        const VERSION           = '22';
        const SHORTCODE         = 'vivavind_v22'; 
        const WRAPPER_SHORTCODE = 'vivavind_wrapper_v22'; 
        const CACHE_TTL         = 90; 
        const REFRESH_MS        = 120000; 

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
            add_shortcode( self::WRAPPER_SHORTCODE, [ __CLASS__, 'render_wrapper_shortcode' ] );
            add_action( "wp_ajax_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
            add_action( "wp_ajax_nopriv_{$ajax_action}", [ __CLASS__, 'ajax_handler' ] );
        }

        /**
         * Flexbox-wrappern: Tvättar bort WordPress "wpautop"-skräp (radbrytningar).
         */
        public static function render_wrapper_shortcode( $atts, $content = null ) {
            // shortcode_unautop hjälper till att städa upp, CSS löser resten.
            $clean_content = do_shortcode( shortcode_unautop( $content ) );
            return '<div class="viva-flexbox-v' . self::VERSION . '">' . $clean_content . '</div>';
        }

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
                return new WP_Error( 'api_http_error', 'Kunde inte ansluta.' );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $data ) || ! isset( $data['GetSingleStationResult'] ) ) {
                return new WP_Error( 'api_invalid_json', 'Felaktigt svar.' );
            }

            $station_result = $data['GetSingleStationResult'];

            if ( ! empty( $station_result['Felmeddelande'] ) ) {
                return new WP_Error( 'api_internal_error', $station_result['Felmeddelande'] );
            }

            set_transient( $transient_key, $station_result, self::CACHE_TTL );

            return $station_result;
        }

        private static function format_data( $api_data ) {
            $formatted = [
                'name'     => isset( $api_data['Name'] ) ? $api_data['Name'] : 'Okänd',
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

        private static function get_widget_content( $station_id ) {
            $raw_data = self::get_api_data( $station_id );

            if ( is_wp_error( $raw_data ) ) {
                return '<div class="viva-card-v' . self::VERSION . ' viva-error-v' . self::VERSION . '">' . esc_html( $raw_data->get_error_message() ) . '</div>';
            }

            $clean_data = self::format_data( $raw_data );
            return self::render_html( $clean_data );
        }

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
            
            if ( ! defined( "VIVA_WIND_CSS_RENDERED_V{$v}" ) ) {
                define( "VIVA_WIND_CSS_RENDERED_V{$v}", true );
                ?>
                <style type="text/css">
                    /* Flexbox wrappern */
                    .viva-flexbox-v<?php echo $v; ?> {
                        display: flex !important;
                        flex-direction: row !important;
                        flex-wrap: wrap !important;
                        gap: 10px !important;
                        align-items: flex-start !important;
                        width: 100% !important;
                    }
                    /* Eliminera dolda radbrytningar som WordPress petar in mellan shortcodes */
                    .viva-flexbox-v<?php echo $v; ?> > p,
                    .viva-flexbox-v<?php echo $v; ?> > br {
                        display: none !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }

                    /* AJAX-behållaren är vår sanna flexbox-item! Nu inline-block istället för block */
                    .<?php echo $wrapper_cl; ?> {
                        display: inline-block !important;
                        width: 180px !important;
                        height: 180px !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }

                    /* Själva kortet fyller ut AJAX-behållaren till 100% */
                    .viva-card-v<?php echo $v; ?> {
                        display: flex !important;
                        flex-direction: column !important;
                        justify-content: space-between !important;
                        align-items: center !important;
                        width: 100% !important;
                        height: 100% !important;
                        box-sizing: border-box !important;
                        border: 1px solid #e2e8f0 !important;
                        border-radius: 10px !important;
                        padding: 12px 10px !important;
                        margin: 0 !important;
                        background-color: #BFEEF2 !important;
                        font-family: sans-serif !important;
                        text-align: center !important;
                    }
                    
                    .viva-header-v<?php echo $v; ?> {
                        width: 100% !important;
                        border-bottom: 1px solid #cbd5e1 !important;
                        padding-bottom: 6px !important;
                        margin-bottom: 4px !important;
                    }
                    .viva-header-v<?php echo $v; ?> h4 {
                        margin: 0 !important;
                        font-family: "Arial Narrow", "Helvetica Neue", sans-serif !important;
                        font-size: 16px !important;
                        font-weight: 600 !important;
                        color: #1e293b !important;
                        white-space: nowrap !important;
                        overflow: hidden !important;
                        text-overflow: ellipsis !important;
                    }
                    .viva-body-v<?php echo $v; ?> {
                        display: flex !important;
                        flex-direction: column !important;
                        gap: 2px !important;
                        justify-content: center !important;
                        flex-grow: 1 !important;
                    }
                    .viva-bywind-v<?php echo $v; ?> {
                        font-size: 12px !important;
                        color: #475569 !important;
                        font-weight: 500 !important;
                    }
                    .viva-speed-v<?php echo $v; ?> {
                        font-size: 24px !important;
                        font-weight: bold !important;
                        color: #0f172a !important;
                        line-height: 1.1 !important;
                    }
                    .viva-dir-v<?php echo $v; ?> {
                        font-size: 12px !important;
                        color: #334155 !important;
                        font-weight: 500 !important;
                        margin-top: 2px !important;
                    }
                    .viva-footer-v<?php echo $v; ?> {
                        font-size: 10px !important;
                        color: #64748b !important;
                        width: 100% !important;
                        margin-top: 4px !important;
                    }
                    .viva-error-v<?php echo $v; ?> {
                        justify-content: center !important;
                        font-size: 12px !important;
                        color: #ef4444 !important;
                        background-color: #fef2f2 !important;
                        border-color: #fca5a5 !important;
                    }
                </style>
                <?php
            }

            // Detta är AJAX-containern som nu är display: inline-block !important
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

        public static function ajax_handler() {
            check_ajax_referer( self::get_config( 'nonce_name' ), 'security' );
            $station_id = isset( $_POST['station_id'] ) ? intval( $_POST['station_id'] ) : 220;
            
            echo self::get_widget_content( $station_id );
            wp_die();
        }
    }

    Viva_Wind_Widget_V22::init();
}