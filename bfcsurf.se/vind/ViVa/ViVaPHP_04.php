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
 * Matar ut CSS-regler i head/footer en gång för enkel anpassning
 */
function viva_wind_render_styles() {
    if (defined('VIVA_WIND_STYLES_RENDERED')) return;
    define('VIVA_WIND_STYLES_RENDERED', true);
    ?>
    <style type="text/css">
        /* --- DESIGNINSTÄLLNINGAR: ÄNDRA DESSA FÖR ATT ANPASSA UTSEENDET --- */
        :root {
            --viva-bg: #fafafa;
            --viva-border: #e2e8f0;
            --viva-radius: 8px;
            --viva-max-width: 210px;
            
            --viva-title-color: #1e293b;
            --viva-label-color: #64748b;
            --viva-text-dark: #0f172a;
            --viva-gust-color: #dc2626;
            --viva-muted-color: #94a3b8;
        }

        /* Container */
        .viva-wind-widget {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 15px;
            background: var(--viva-bg);
            border-radius: var(--viva-radius);
            max-width: var(--viva-max-width);
            border: 1px solid var(--viva-border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            color: var(--viva-text-dark);
        }

        /* Rubrik / Stationsnamn */
        .viva-wind-widget h3 {
            margin: 0 0 12px 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--viva-title-color);
            border-bottom: 2px solid var(--viva-border);
            padding-bottom: 6px;
        }

        /* Data rader */
        .viva-wind-widget p {
            margin: 6px 0;
            font-size: 14px;
        }
        .viva-wind-widget strong {
            color: var(--viva-label-color);
            font-weight: 600;
        }

        /* Specifika datatyper */
        .viva-wind-widget .viva-gust-row {
            text-align: center;
        }
        .viva-wind-widget .viva-gust-row span {
            color: var(--viva-gust-color);
            font-weight: 600;
        }

        .viva-wind-widget .viva-speed-row {
            text-align: left;
            font-size: 24px;
        }
        .viva-wind-widget .viva-speed-row span {
            font-weight: 600;
        }

        .viva-wind-widget .viva-dir-row {
            text-align: center;
        }
        .viva-wind-widget .viva-dir-row span {
            font-weight: 600;
        }

        /* Status & tidsstämpel */
        .viva-wind-widget .viva-status-msg {
            margin: 0;
            font-size: 13px;
            color: var(--viva-muted-color);
        }
        .viva-wind-widget .viva-timestamp {
            margin-top: 10px;
            font-size: 12px;
            color: var(--viva-muted-color);
            text-align: left;
        }
    </style>
    <?php
}

/**
 * Hämtar och rensar HTML från Sjöfartsverket ViVa API.
 */
function get_viva_clean_html($station_id) {
    $station_id = intval($station_id);
    $transient_key = 'viva_wind_cache_' . $station_id;
    
    $cached_html = get_transient($transient_key);
    if ($cached_html !== false) {
        return $cached_html;
    }

    $url = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
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

        $output .= '<h3>' . esc_html($station_name) . '</h3>';

        if (!empty($samples)) {
            $output .= '<div class="viva-data-container">';
            foreach ($samples as $sample) {
                $s_type = isset($sample['Type']) ? strtolower(trim($sample['Type'])) : '';
                $s_name = isset($sample['Name']) ? strtolower(trim($sample['Name'])) : '';
                
                $val     = esc_html($sample['Value'] ?? '');
                $unit    = esc_html($sample['Unit'] ?? '');
                $heading = esc_html($sample['Heading'] ?? '');

                // Byvind
                if (strpos($s_type, 'windgust') !== false || strpos($s_name, 'byvind') !== false) {
                    $output .= '<p class="viva-gust-row"><strong>Byvind:</strong> <span>' . $val . ' ' . $unit . '</span></p>';
                }
                // Medelvind
                if (strpos($s_type, 'windspeed') !== false || strpos($s_name, 'medelvind') !== false || strpos($s_name, 'hastighet') !== false) {
                    $output .= '<p class="viva-speed-row"><strong>Medelvind:</strong> <span>' . $val . ' ' . $unit . '</span></p>';
                }
                // Riktning
                if (strpos($s_type, 'winddir') !== false || strpos($s_name, 'riktning') !== false) {
                    $output .= '<p class="viva-dir-row"><strong>Riktning:</strong> <span>' . $heading . '° (' . $val . ')</span></p>';
                }
            }
            $output .= '</div>';
        } else {
            $output .= '<p class="viva-status-msg">Ingen data tillgänglig.</p>';
        }
    } else {
        $output .= '<p class="viva-status-msg">Kunde inte hämta vinddata.</p>';
    }
    
    $output .= '<div class="viva-timestamp">Uppdaterad: ' . current_time('H:i:s') . '</div>';
    
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
    $nonce = wp_create_nonce('viva_wind_refresh_nonce');

    // Skriv ut CSS-stilar i dokumentet (körs bara en gång per sidladdning)
    viva_wind_render_styles();

    ob_start();
    
    echo '<div id="' . esc_attr($unique_id) . '" class="viva-wind-widget" data-station="' . $station_id . '">';
    echo get_viva_clean_html($station_id);
    echo '</div>';

    if (!defined('VIVA_WIND_SCRIPT_RENDERED')) {
        define('VIVA_WIND_SCRIPT_RENDERED', true);
        ?>
        <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            setInterval(function() {
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
            }, 120000);
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
    check_ajax_referer('viva_wind_refresh_nonce', 'security');
    $station_id = isset($_POST['station_id']) ? intval($_POST['station_id']) : 220;
    
    echo get_viva_clean_html($station_id);
    wp_die();
}