/**
 * Dynamisk ViVa Vind Data Shortcode med Auto-Refresh (Varannan minut)
 * Usage: [viva_wind id="220"]
 */

// 1. Registrera shortcoden för frontend
add_shortcode('viva_wind', 'render_dynamic_viva_wind_widget');

// 2. Koppla AJAX-åtgärder så WordPress tillåter bakgrundsuppdateringar
add_action('wp_ajax_refresh_viva_wind', 'viva_wind_ajax_handler');
add_action('wp_ajax_nopriv_refresh_viva_wind', 'viva_wind_ajax_handler');


// Denna funktion bygger själva innehållet (HTML) från Sjöfartsverket
function get_viva_clean_html($station_id) {
    $url = "https://services.viva.sjofartsverket.se:8080/output/vivaoutputservice.svc/vivastation/" . intval($station_id);

    $options = array(
        'http' => array(
            'method' => "GET",
            'header' => "Accept: application/json\r\n" . "User-Agent: WordPress-ViVa-Widget/1.0\r\n",
            'timeout' => 4 
        )
    );

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    $output = '';

    if ($response !== FALSE) {
        $data = json_decode($response, true);
        $station_data = $data['GetSingleStationResult'] ?? null;
        $samples = $station_data['Samples'] ?? array();
        $station_name = $station_data['Name'] ?? "Station " . $station_id;

        $output .= '<h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px;">' . htmlspecialchars($station_name) . '</h3>';

        if (!empty($samples)) {
            $output .= '<div style="display: block;">';
            foreach ($samples as $sample) {
                $s_type = isset($sample['Type']) ? strtolower(trim($sample['Type'])) : '';
                $s_name = isset($sample['Name']) ? strtolower(trim($sample['Name'])) : '';
                
                $val  = htmlspecialchars($sample['Value'] ?? '');
                $unit = htmlspecialchars($sample['Unit'] ?? '');
                $heading = htmlspecialchars($sample['Heading'] ?? '');

                
                if (strpos($s_type, 'windgust') !== false || strpos($s_name, 'byvind') !== false) {
                    $output .= '<p style="margin: 6px 0; font-size: 14px; text-align: center;"><strong style="color: #64748b;">Byvind:</strong> <span style="font-weight: 600; color: #dc2626;">' . $val . ' ' . $unit . '</span></p>';
                }
				if (strpos($s_type, 'windspeed') !== false || strpos($s_name, 'medelvind') !== false || strpos($s_name, 'hastighet') !== false) {
                    $output .= '<p style="margin: 6px 0; font-size: 24px; text-align: left;"><strong style="color: #64748b;"> </strong> <span style="font-weight: 600; color: #0f172a;">' . $val . ' ' . $unit . '</span></p>';
                }
				
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
    
    // Lägger till en diskret tidsstämpel i botten så du ser när den uppdaterades senast
    $output .= '<div style="margin-top: 10px; font-size: 12px; color: #94a3b8; text-align: left;">Uppdaterad: ' . current_time('H:i:s') . '</div>';
    
    return $output;
}

// Huvudfunktion för shortcoden som laddar första gången sidan besöks
function render_dynamic_viva_wind_widget($atts) {
    $atts = shortcode_atts(array('id' => '220'), $atts, 'viva_wind');
    $station_id = intval($atts['id']);
    
    // Skapa ett unikt ID för div-taggen ifall du har flera olika stationer på samma sida
    $unique_id = "viva-container-" . $station_id;
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    
    // Omslutande container med unikt ID
    echo '<div id="' . $unique_id . '" class="viva-wind-widget" style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; padding: 15px; background: #fafafa; border-radius: 8px; max-width: 210px; color: #2c3e50; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
    echo get_viva_clean_html($station_id);
    echo '</div>';

    // JavaScript-block som körs i bakgrunden var 120000:e millisekund (2 minuter)
    ?>
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        setInterval(function() {
            var container = document.getElementById('<?php echo $unique_id; ?>');
            if (!container) return;

            // Skapa ett asynkront anrop (AJAX) till din WordPress-server
            var formData = new FormData();
            formData.append('action', 'refresh_viva_wind');
            formData.append('station_id', '<?php echo $station_id; ?>');

            fetch('<?php echo $ajax_url; ?>', {
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
        }, 120000); // 120 000 ms = 2 minuter
    });
    </script>
    <?php

    return ob_get_clean();
}

// AJAX-hanteraren som svarar på bakgrundsanropen
function viva_wind_ajax_handler() {
    $station_id = isset($_POST['station_id']) ? intval($_POST['station_id']) : 220;
    
    // Generera och skicka enbart den inre HTML-strukturen till webbläsaren
    echo get_viva_clean_html($station_id);
    
    wp_die(); // Avslutar AJAX-anropet korrekt i WordPress
}