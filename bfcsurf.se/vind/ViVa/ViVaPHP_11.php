/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh (Varannan minut) - Version 11
 * Presentation: Strukturerad Flexbox-layout med lättjusterad CSS.
 * Usage: [vivavind_v11 id="220"]
 */

// 1. Registrera shortcoden för frontend (Använder redan vivavind_v11)
add_shortcode('vivavind_v11', 'render_dynamic_viva_wind_widget_v11');

// 2. Koppla AJAX-åtgärder för bakgrundsuppdateringar med unika v11-namn
add_action('wp_ajax_refresh_viva_wind_v11', 'viva_wind_ajax_handler_v11');
add_action('wp_ajax_nopriv_refresh_viva_wind_v11', 'viva_wind_ajax_handler_v11');

/**
 * Matar ut CSS-regler i head/footer en gång för enkel anpassning.
 * Optimerad för ett fast, kvadratiskt 1:1 format (180px x 180px).
 * Uppdaterad till inline-flex för naturligt sid-vid-sid-flöde.
 */
function viva_wind_render_styles_v11() {
    if (defined('VIVA_WIND_STYLES_RENDERED_V11')) return;
    define('VIVA_WIND_STYLES_RENDERED_V11', true);
    ?>
    <style type="text/css">
        :root {
            --viva-bg-v11: #e3f9fa;
            --viva-border-v11: #e2e8f0;
            --viva-text-dark-v11: #0f172a;
            --viva-muted-color-v11: #94a3b8;
        }

        /* Fast storlek med exakt 1:1 kvadratiskt bildförhållande */
        .viva-wind-widget-v11 {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            box-sizing: border-box;
            background: var(--viva-bg-v11);
            border: 1px solid var(--viva-border-v11);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            color: var(--viva-text-dark-v11);
            text-align: center;
            padding: 15px;
            
            /* Sätter den kvadratiska formen */
            width: 180px;
            aspect-ratio: 1 / 1;
            
            /* GÖR SÅ ATT WIDGETS FLYTER SIDA VID SIDA OCH WRAPPAR */
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            vertical-align: top; /* Hindrar rutorna från att hoppa ojämnt i höjdled */
            margin: 8px;         /* Skapar luft runt varje widget */
            
            gap: 12px;
        }

        .viva-wind-widget-v11 h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            border-bottom: 2px solid var(--viva-border-v11);
            padding-bottom: 6px;
        }

        .viva-data-container-v11 {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .viva-row-v11 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            padding-bottom: 4px;
            border-bottom: 1px dashed #f1f5f9;
        }

        .viva-row-v11 strong {
            color: #64748b;
            font-weight: 500;
        }

        .viva-row-v11 span {
            font-weight: 600;
        }

        /* Medelvind i mitten utan border */
        .viva-wind-widget-v11 .viva-speed-row-v11 {
            justify-content: center;
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .viva-wind-widget-v11 .viva-speed-row-v11 span {
            font-size: 24px;
        }

        .viva-wind-widget-v11 .viva-status-msg-v11 {
            margin: 0;
            font-size: 13px;
            color: var(--viva-muted-color-v11);
        }

        .viva-wind-widget-v11 .viva-timestamp-v11 {
            font-size: 11px;
            color: var(--viva-muted-color-v11);
            margin: 0;
        }
    </style>
    <?php
}

/**
 * Hämtar rådata från Sjöfartsverket och strukturerar upp HTML-innehållet.
 * Unikt cache-transient-namn för v11.
 */
function get_viva_clean_html_v11($station_id) {
    $station_id = intval($station_id);
    $transient_key = 'viva_wind_cache_v11_' . $station_id; // Unik nyckel
    
    $cached_html = get_transient($transient_key);
    if ($cached_html !== false) {
        return $cached_html;
    }

    $url = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . $station_id;
    $response = wp_remote_get($url, array(
        'timeout'   => 4,
        'headers'   => array(
            'Accept'     => 'application/json',
            'User-Agent' => 'WordPress-ViVa-Widget-v11/1.0',
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
            $medelvind = null;
            $bywind    = null;
            $riktning  = null;

            foreach ($samples as $sample) {
                $s_type = isset($sample['Type']) ? strtolower(trim($sample['Type'])) : '';
                $s_name = isset($sample['Name']) ? strtolower(trim($sample['Name'])) : '';
                
                $val         = esc_html($sample['Value'] ?? '');
                $unit        = esc_html($sample['Unit'] ?? '');
                $heading_deg = isset($sample['Heading']) ? trim($sample['Heading']) : '';

                preg_match('/[0-9]+[.,]?[0-9]*/', $val, $number_matches);
                $clean_speed_val = isset($number_matches[0]) ? $number_matches[0] : $val;

                if (strpos($s_type, 'windspeed') !== false || strpos($s_name, 'medelvind') !== false || strpos($s_name, 'hastighet') !== false) {
                    $medelwind = array('val' => $clean_speed_val, 'unit' => $unit);
                    
                    if (!empty($heading_deg)) {
                        $riktning = array('heading' => esc_html($heading_deg), 'raw_text' => $val);
                    }
                }
                
                if (strpos($s_type, 'windgust') !== false || strpos($s_name, 'bywind') !== false) {
                    $bywind = array('val' => $clean_speed_val, 'unit' => $unit);
                    
                    if (!$riktning && !empty($heading_deg)) {
                        $riktning = array('heading' => esc_html($heading_deg), 'raw_text' => $val);
                    }
                }

                if (strpos($s_type, 'winddir') !== false || strpos($s_name, 'riktning') !== false) {
                    $riktning = array('heading' => $heading_deg ? esc_html($heading_deg) : $val, 'raw_text' => $val);
                }
            }

            $output .= '<div class="viva-data-container-v11">';
            
            if ($medelwind) {
                $output .= '<div class="viva-row-v11 viva-speed-row-v11">';
                $output .= '<span>' . $medelwind['val'] . ' ' . $medelwind['unit'] . '</span>';
                $output .= '</div>';
            }

            if ($bywind) {
                $output .= '<div class="viva-row-v11 viva-gust-row-v11">';
                $output .= '<strong>By:</strong>';
                $output .= '<span>' . $bywind['val'] . ' ' . $bywind['unit'] . '</span>';
                $output .= '</div>';
            }

            if ($riktning && !empty($riktning['heading'])) {
                $output .= '<div class="viva-row-v11 viva-dir-row-v11">';
                $output .= '<strong>Riktning:</strong>';
                
                $visnings_text = $riktning['heading'] . '°';
                
                if (!empty($riktning['raw_text'])) {
                    preg_match('/[A-ZÅÄÖa-zåäö]+/u', $riktning['raw_text'], $letter_matches);
                    $bokstaver = isset($letter_matches[0]) ? trim($letter_matches[0]) : '';
                    
                    if (!empty($bokstaver) && $bokstaver !== $riktning['heading']) {
                        $visnings_text .= ' (' . $bokstaver . ')';
                    }
                }
                
                $output .= '<span>' . $visnings_text . '</span>';
                $output .= '</div>';
            }

            $output .= '</div>';
        } else {
            $output .= '<p class="viva-status-msg-v11">Ingen data tillgänglig.</p>';
        }
    } else {
        $output .= '<p class="viva-status-msg-v11">Kunde inte hämta vinddata.</p>';
    }
    
    $output .= '<div class="viva-timestamp-v11">Uppdaterad: ' . current_time('H:i:s') . '</div>';
    
    set_transient($transient_key, $output, 90);

    return $output;
}

/**
 * Huvudfunktion för shortcoden som körs vid sidladdning.
 */
function render_dynamic_viva_wind_widget_v11($atts) {
    $atts = shortcode_atts(array('id' => '220'), $atts, 'viva_wind');
    $station_id = intval($atts['id']);
    
    $unique_id = "viva-container-v11-" . $station_id;
    $ajax_url  = admin_url('admin-ajax.php');
    
    // Unik säkerhetstoken (nonce) för v11
    $nonce = wp_create_nonce('viva_wind_refresh_nonce_v11');

    viva_wind_render_styles_v11();

    ob_start();
    
    echo '<div id="' . esc_attr($unique_id) . '" class="viva-wind-widget-v11" data-station="' . $station_id . '">';
    echo get_viva_clean_html_v11($station_id);
    echo '</div>';

    if (!defined('VIVA_WIND_SCRIPT_RENDERED_V11')) {
        define('VIVA_WIND_SCRIPT_RENDERED_V11', true);
        ?>
        <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            setInterval(function() {
                var widgets = document.querySelectorAll('.viva-wind-widget-v11');
                
                widgets.forEach(function(container) {
                    var stationId = container.getAttribute('data-station');
                    if (!stationId) return;

                    var formData = new FormData();
                    formData.append('action', 'refresh_viva_wind_v11'); // Unik AJAX-action
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
                    .catch(error => console.error('ViVa v11 Refresh Error:', error));
                });
            }, 120000);
        });
        </script>
        <?php
    }

    return ob_get_clean();
}

/**
 * Säkrad AJAX-hanterare med v11-suffix
 */
function viva_wind_ajax_handler_v11() {
    check_ajax_referer('viva_wind_refresh_nonce_v11', 'security');

    $station_id = isset($_POST['station_id']) ? intval($_POST['station_id']) : 220;
    
    echo get_viva_clean_html_v11($station_id);
    wp_die();
}