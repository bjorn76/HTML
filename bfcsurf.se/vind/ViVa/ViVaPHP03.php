<?php
/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh (Varannan minut)
 * Usage: [viva_wind id="220"]
 */

// 1. Registrera shortcoden för frontend
add_shortcode('viva_wind', 'render_dynamic_viva_wind_widget');

// 2. Koppla AJAX-åtgärder
add_action('wp_ajax_refresh_viva_wind', 'viva_wind_ajax_handler');
add_action('wp_ajax_nopriv_refresh_viva_wind', 'viva_wind_ajax_handler');

/**
 * Hämtar och rensar HTML från Sjöfartsverket ViVa API.
 * Använder Transients för att skydda serverns prestanda.
 */
function get_viva_clean_html($station_id) {
    $station_id = intval($station_id);
    $transient_key = 'viva_wind_cache_' . $station_id;
    
    // Försök hämta cachad HTML först
    $cached_html = get_transient($transient_key);
    if ($cached_html !== false) {
        return $cached_html;
    }

    $url = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;

    // Ersätter manuell stream_context med infödda WordPress-funktioner
    $response = wp_remote_get($url, array(
        'timeout'   => 4,
        'headers'   => array(
            'Accept'     => 'application/json',
            'User-Agent' => 'WordPress-ViVa-Widget/1.0',
        )
    ));

    $output = '';

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $station_data = $data['GetSingleStationResult'] ?? null;
        $samples      = $station_data['Samples'] ?? array();
        $station_name = $station_data['Name'] ?? "Station " . $station_id;

        $output .= '<h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px;">' . esc_html($station_name) . '</h3>';

        if (!empty($samples)) {
            $output .= '<div style="display: block;">';
            foreach ($samples as $sample) {
                $s_type = isset($sample['Type']) ? strtolower(trim($sample['Type'])) : '';
                $s_name = isset($sample['Name']) ? strtolower(trim($sample['Name'])) : '';
                
                $val     = esc_html($sample['Value'] ?? '');
                $unit    = esc_html($sample['Unit'] ?? '');
                $heading = esc_html($sample['Heading'] ?? '');

                // Byvind
                if (strpos($s_type, 'windgust') !== false || strpos($s_name, 'byvind') !== false) {
                    $output .= '<p style="margin: 6px 0; font-size: 14px; text-align: center;"><strong style="color: #64748b;">Byvind:</strong> <span style="font-weight: 600; color: #dc2626;">' . $val . ' ' . $unit . '</span></p>';
                }
                // Medelvind (Fixat tomma starka taggar)
                if (strpos($s_type, 'windspeed') !== false || strpos($s_name, 'medelvind') !== false || strpos($s_name, 'hastighet') !== false) {
                    $output .= '<p style="margin: 6px 0; font-size: 24px; text-align: left;"><strong style="color: #64748b;">Medelvind:</strong> <span style="font-weight: 600; color: #0f172a;">' . $val . ' ' . $unit . '</span></p>';
                }
                // Riktning
                if (strpos($s_type, 'winddir') !== false || strpos($s_name, 'riktning') !== false) {
                    $output .= '<p style="margin: 6px 0; font-size: 14px; text-align: center;"><strong style="color: #64748b;">Riktning:</strong> <span style="font-weight: 600; color: #0f172a;">' . $heading . '° (' . $val . ')</span></p>';
                }
            }
            $output .= '</div>';
        } else {
            $output .= '<p style="margin: 0; font-size: 13px; color: #94a3b8;">Ingen data tillgänglig.</p>';
        }
    } else {
        $output .= '<p style="margin: 0; font-size: 13px; color: #94a3b8;">Kunde inte hämta vinddata.</p>';
    }
    
    $output .= '<div style="margin-top: 10px; font-size: 12px; color: #94a3b8; text-align: left;">Uppdaterad: ' . current_time('H:i:s') . '</div>';
    
    // Spara i cache i 90 sekunder så API inte överbelastas
    set_transient($transient_key, $output, 90);

    return $output;
}

/**
 * Huvudfunktion för shortcoden
 */
function render_dynamic_viva_wind_widget($atts) {
    $atts = shortcode_atts(array('id' => '220'), $atts, 'viva_wind');
    $station_id = intval($atts['id']);
    
    $unique_id = "viva-container-" . $station_id;
    $ajax_url = admin_url('admin-ajax.php');
    
    // Generera en engångsnyckel (Nonce) för säkerhet
    $nonce = wp_create_nonce('viva_wind_refresh_nonce');

    ob_start();
    
    echo '<div id="' . esc_attr($unique_id) . '" class="viva-wind-widget" data-station="' . $station_id . '" style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; padding: 15px; background: #fafafa; border-radius: 8px; max-width: 210px; color: #2c3e50; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
    echo get_viva_clean_html($station_id);
    echo '</div>';

    // Förhindrar krockar om skriptet matas ut flera gånger på samma sida
    if (!defined('VIVA_WIND_SCRIPT_RENDERED')) {
        define('VIVA_WIND_SCRIPT_RENDERED', true);
        ?>
        <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            setInterval(function() {
                // Hitta ALLA viva-widgets på sidan och uppdatera dem i en och samma loop
                var widgets = document.querySelectorAll('.viva-wind-widget');
                
                widgets.forEach(function(container) {
                    var stationId = container.getAttribute('data-station');
                    if (!stationId) return;

                    var formData = new FormData();
                    formData.append('action', 'refresh_viva_wind');
                    formData.append('station_id', stationId);
                    formData.append('security', '<?php echo $nonce; ?>');

                    fetch('<?php echo esc_url($ajax_url); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        if(html.trim() !== '') {
                            container.innerHTML = html;
                        }
                    })
                    .catch(error => console.error('ViVa Refresh Error:', error));
                });
            }, 120000); // 2 minuter
        });
        </script>
        <?php
    }

    return ob_get_clean();
}

/**
 * Säkrad AJAX-hanterare
 */
function viva_wind_ajax_handler() {
    // Verifiera säkerhets-nonce innan något behandlas
    check_ajax_referer('viva_wind_refresh_nonce', 'security');

    $station_id = isset($_POST['station_id']) ? intval($_POST['station_id']) : 220;
    
    echo get_viva_clean_html($station_id);
    wp_die();
}