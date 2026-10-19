<?php
/**
 * Plugin Name: Branch Locator Map
 * Description: Multilingual branch locator with Google Maps and OpenStreetMap support. Use [branch_locator].
 * Version: 1.2.1
 * Author: Shebas Khan
 * License: GPL-2.0-or-later
 * Text Domain: branch-locator-map
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optionally configure the Google Maps API key in wp-config.php:
 * define('BRANCH_LOCATOR_GOOGLE_MAPS_API_KEY', 'YOUR_API_KEY_HERE');
 */

/**
 * The API key can be configured in wp-config.php (constant takes priority)
 * or in WordPress Admin > Settings > Branch Locator. It is only required for Google Maps.
 */
function bl_locator_register_settings() {
    register_setting(
        'bl_locator_settings_group',
        'bl_locator_google_maps_api_key',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );
    register_setting(
        'bl_locator_settings_group',
        'bl_locator_branches',
        array(
            'type' => 'array',
            'sanitize_callback' => 'bl_locator_sanitize_branches',
            'default' => array(),
        )
    );
    register_setting(
        'bl_locator_settings_group',
        'bl_locator_map_settings',
        array(
            'type' => 'array',
            'sanitize_callback' => 'bl_locator_sanitize_map_settings',
            'default' => bl_locator_default_map_settings(),
        )
    );
}
add_action('admin_init', 'bl_locator_register_settings');
add_action('admin_menu', 'bl_locator_register_settings_menu');
add_action('admin_enqueue_scripts', 'bl_locator_admin_assets');

function bl_locator_admin_assets($hook) {
    if ($hook !== 'settings_page_branch-locator-settings') {
        return;
    }

    wp_enqueue_media();
    $asset_url = plugins_url('assets/vendor/leaflet/', __FILE__);
    wp_enqueue_style('bl-leaflet', $asset_url . 'leaflet.css', array(), '1.9.4');
    wp_enqueue_style('bl-leaflet-draw', $asset_url . 'leaflet.draw.css', array('bl-leaflet'), '1.0.4');
    wp_enqueue_script('bl-leaflet', $asset_url . 'leaflet.js', array(), '1.9.4', false);
    wp_enqueue_script('bl-leaflet-draw', $asset_url . 'leaflet.draw.js', array('bl-leaflet'), '1.0.4', false);
}

function bl_locator_default_map_settings() {
    return array(
        'provider' => 'osm',
        'center_lat' => '',
        'center_lng' => '',
        'zoom' => '',
        'google_styles' => '[{"featureType":"all","elementType":"geometry","stylers":[{"color":"#f5f7fa"}]},{"featureType":"water","elementType":"geometry","stylers":[{"color":"#dbeafe"}]},{"featureType":"road","elementType":"geometry","stylers":[{"color":"#ffffff"}]},{"featureType":"administrative","elementType":"labels.text.fill","stylers":[{"color":"#475569"}]}]',
        'service_area' => '',
    );
}

function bl_locator_sanitize_map_settings($settings) {
    if (!is_array($settings)) {
        return bl_locator_default_map_settings();
    }

    $defaults = bl_locator_default_map_settings();
    $provider = isset($settings['provider']) && is_scalar($settings['provider'])
        ? sanitize_key((string) $settings['provider'])
        : $defaults['provider'];
    $center_lat = isset($settings['center_lat']) && is_numeric($settings['center_lat']) ? (float) $settings['center_lat'] : null;
    $center_lng = isset($settings['center_lng']) && is_numeric($settings['center_lng']) ? (float) $settings['center_lng'] : null;
    $zoom = isset($settings['zoom']) && is_numeric($settings['zoom']) ? (int) $settings['zoom'] : null;
    $styles = isset($settings['google_styles']) && is_scalar($settings['google_styles'])
        ? trim((string) $settings['google_styles'])
        : '';
    $area = isset($settings['service_area']) && is_scalar($settings['service_area'])
        ? trim((string) $settings['service_area'])
        : '';

    if (!in_array($provider, array('google', 'osm'), true)) {
        add_settings_error('bl_locator_map_settings', 'invalid_map_provider', __('Choose either Google Maps or OpenStreetMap as the map provider.', 'branch-locator-map'));
        $provider = 'osm';
    }
    $invalid_center = ($center_lat !== null && ($center_lat < -90 || $center_lat > 90))
        || ($center_lng !== null && ($center_lng < -180 || $center_lng > 180));
    if ($invalid_center) {
        add_settings_error('bl_locator_map_settings', 'invalid_map_center', __('Map center coordinates are out of range; enter a latitude from -90 to 90 and longitude from -180 to 180.', 'branch-locator-map'));
        $center_lat = null;
        $center_lng = null;
    }
    if (($center_lat === null) !== ($center_lng === null)) {
        add_settings_error('bl_locator_map_settings', 'incomplete_map_center', __('Enter both map center coordinates or leave both blank.', 'branch-locator-map'));
        $center_lat = null;
        $center_lng = null;
    }
    if ($zoom !== null) {
        if ($zoom < 1 || $zoom > 20) {
            add_settings_error('bl_locator_map_settings', 'invalid_map_zoom', __('Map zoom must be between 1 and 20; the value was adjusted to that range.', 'branch-locator-map'));
        }
        $zoom = max(1, min(20, $zoom));
    }
    if ($styles !== '') {
        $decoded_styles = json_decode($styles);
        if (!is_array($decoded_styles)) {
            add_settings_error('bl_locator_map_settings', 'invalid_google_styles', __('Google Maps styles must be valid JSON.', 'branch-locator-map'));
            $previous = get_option('bl_locator_map_settings', $defaults);
            $styles = is_array($previous) && isset($previous['google_styles']) ? $previous['google_styles'] : '';
        }
    }
    if ($area !== '') {
        $decoded_area = json_decode($area, true);
        if (!is_array($decoded_area) || !isset($decoded_area['type'], $decoded_area['features'])
            || $decoded_area['type'] !== 'FeatureCollection' || !is_array($decoded_area['features'])) {
            add_settings_error('bl_locator_map_settings', 'invalid_service_area', __('Service area must be valid GeoJSON generated by the drawing tool.', 'branch-locator-map'));
            $previous = get_option('bl_locator_map_settings', $defaults);
            $area = is_array($previous) && isset($previous['service_area']) ? $previous['service_area'] : '';
        }
    }

    return array(
        'provider' => $provider,
        'center_lat' => $center_lat === null ? '' : (string) $center_lat,
        'center_lng' => $center_lng === null ? '' : (string) $center_lng,
        'zoom' => $zoom === null ? '' : $zoom,
        'google_styles' => $styles,
        'service_area' => $area,
    );
}

function bl_locator_get_map_settings() {
    $settings = get_option('bl_locator_map_settings', array());
    $settings = array_merge(bl_locator_default_map_settings(), is_array($settings) ? $settings : array());
    if (!in_array($settings['provider'], array('google', 'osm'), true)) {
        $settings['provider'] = 'osm';
    }
    $settings['zoom'] = is_numeric($settings['zoom']) ? max(1, min(20, (int) $settings['zoom'])) : '';
    foreach (array('center_lat', 'center_lng', 'google_styles', 'service_area') as $key) {
        if (!is_scalar($settings[$key])) {
            $settings[$key] = '';
        }
    }
    if (!is_numeric($settings['center_lat']) || (float) $settings['center_lat'] < -90 || (float) $settings['center_lat'] > 90) {
        $settings['center_lat'] = '';
    }
    if (!is_numeric($settings['center_lng']) || (float) $settings['center_lng'] < -180 || (float) $settings['center_lng'] > 180) {
        $settings['center_lng'] = '';
    }
    return $settings;
}

function bl_locator_register_settings_menu() {
    add_options_page(
        __('Branch Locator Settings', 'branch-locator-map'),
        __('Branch Locator', 'branch-locator-map'),
        'manage_options',
        'branch-locator-settings',
        'bl_locator_settings_page'
    );
}

function bl_locator_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved_key = get_option('bl_locator_google_maps_api_key', '');
    $saved_branches = bl_locator_normalize_branches(get_option('bl_locator_branches', array()));
    $map_settings = bl_locator_get_map_settings();
    $constant_key = defined('BRANCH_LOCATOR_GOOGLE_MAPS_API_KEY')
        ? BRANCH_LOCATOR_GOOGLE_MAPS_API_KEY
        : '';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Branch Locator Settings', 'branch-locator-map'); ?></h1>
        <p><?php echo esc_html__('A Google Maps API key is optional for OpenStreetMap and required only when you select Google Maps. A key defined in wp-config.php takes priority over the key entered here.', 'branch-locator-map'); ?></p>
        <?php if ($constant_key !== '') : ?>
            <div class="notice notice-info inline"><p><?php echo esc_html__('A key is defined in wp-config.php and will take priority over the key entered below.', 'branch-locator-map'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('bl_locator_settings_group'); ?>
            <h2><?php echo esc_html__('Map settings', 'branch-locator-map'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bl_locator_map_provider"><?php echo esc_html__('Map provider', 'branch-locator-map'); ?></label></th>
                    <td>
                        <select id="bl_locator_map_provider" name="bl_locator_map_settings[provider]">
                            <option value="osm" <?php selected($map_settings['provider'], 'osm'); ?>><?php echo esc_html__('OpenStreetMap', 'branch-locator-map'); ?></option>
                            <option value="google" <?php selected($map_settings['provider'], 'google'); ?>><?php echo esc_html__('Google Maps', 'branch-locator-map'); ?></option>
                        </select>
                        <p class="description"><?php echo esc_html__('OpenStreetMap is the default and does not require an API key. Google Maps requires the API key entered below.', 'branch-locator-map'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Initial map center', 'branch-locator-map'); ?></th>
                    <td>
                        <label><?php echo esc_html__('Latitude', 'branch-locator-map'); ?>
                            <input type="number" step="any" min="-90" max="90" name="bl_locator_map_settings[center_lat]" value="<?php echo esc_attr($map_settings['center_lat']); ?>" class="small-text" />
                        </label>
                        <label><?php echo esc_html__('Longitude', 'branch-locator-map'); ?>
                            <input type="number" step="any" min="-180" max="180" name="bl_locator_map_settings[center_lng]" value="<?php echo esc_attr($map_settings['center_lng']); ?>" class="small-text" />
                        </label>
                        <label><?php echo esc_html__('Zoom (1–20)', 'branch-locator-map'); ?>
                            <input type="number" min="1" max="20" name="bl_locator_map_settings[zoom]" value="<?php echo esc_attr($map_settings['zoom']); ?>" class="small-text" />
                        </label>
                    </td>
                </tr>
                <tr class="bl-google-settings">
                    <th scope="row"><label for="bl_locator_google_styles"><?php echo esc_html__('Google Maps styles (JSON)', 'branch-locator-map'); ?></label></th>
                    <td>
                        <textarea id="bl_locator_google_styles" name="bl_locator_map_settings[google_styles]" rows="8" class="large-text code"><?php echo esc_textarea($map_settings['google_styles']); ?></textarea>
                        <p class="description"><?php echo esc_html__('Optional Google Maps style JSON array. Ignored when OpenStreetMap is selected.', 'branch-locator-map'); ?></p>
                    </td>
                </tr>
            </table>
            <h3><?php echo esc_html__('Service area', 'branch-locator-map'); ?></h3>
            <p><?php echo esc_html__('Use the polygon drawing toolbar to define the service area. The saved area is shown on the public map.', 'branch-locator-map'); ?></p>
            <div id="bl-locator-area-editor" style="width:100%;max-width:900px;height:360px;background:#eee;"></div>
            <textarea id="bl_locator_service_area" name="bl_locator_map_settings[service_area]" class="large-text code" rows="4" aria-label="<?php echo esc_attr__('Service area GeoJSON', 'branch-locator-map'); ?>"><?php echo esc_textarea($map_settings['service_area']); ?></textarea>
            <p><button type="button" class="button" id="bl-locator-area-clear"><?php echo esc_html__('Clear service area', 'branch-locator-map'); ?></button></p>
            <script>
            (function () {
                const provider = document.getElementById('bl_locator_map_provider');
                const styleRow = document.querySelector('.bl-google-settings');
                function updateProviderFields() {
                    styleRow.hidden = provider.value !== 'google';
                }
                provider.addEventListener('change', updateProviderFields);
                updateProviderFields();

                const areaField = document.getElementById('bl_locator_service_area');
                const editor = document.getElementById('bl-locator-area-editor');
                let drawnItems = null;
                document.getElementById('bl-locator-area-clear').addEventListener('click', function () {
                    if (drawnItems) drawnItems.clearLayers();
                    areaField.value = '';
                    areaField.setCustomValidity('');
                });
                if (!editor) return;
                if (!window.L || !L.Control || !L.Control.Draw) {
                    editor.textContent = <?php echo wp_json_encode(__('The service-area drawing tools could not be loaded. You can still add markers and save other settings.', 'branch-locator-map')); ?>;
                    return;
                }
                const center = [
                    <?php echo wp_json_encode(is_numeric($map_settings['center_lat']) ? (float) $map_settings['center_lat'] : 20); ?>,
                    <?php echo wp_json_encode(is_numeric($map_settings['center_lng']) ? (float) $map_settings['center_lng'] : 0); ?>
                ];
                const areaMap = L.map(editor).setView(center, <?php echo is_numeric($map_settings['zoom']) ? (int) $map_settings['zoom'] : 2; ?>);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(areaMap);
                drawnItems = new L.FeatureGroup().addTo(areaMap);
                try {
                    if (areaField.value) {
                        const saved = L.geoJSON(JSON.parse(areaField.value));
                        saved.eachLayer(function (layer) { drawnItems.addLayer(layer); });
                    }
                } catch (error) {
                    areaField.setCustomValidity(<?php echo wp_json_encode(__('The saved service area is invalid GeoJSON. Clear it or redraw the area.', 'branch-locator-map')); ?>);
                }
                areaMap.addControl(new L.Control.Draw({
                    edit: { featureGroup: drawnItems },
                    draw: { polygon: true, rectangle: false, circle: false, circlemarker: false, marker: false, polyline: false }
                }));
                areaMap.on(L.Draw.Event.CREATED, function (event) {
                    drawnItems.clearLayers();
                    drawnItems.addLayer(event.layer);
                    areaField.value = JSON.stringify(drawnItems.toGeoJSON());
                    areaField.setCustomValidity('');
                });
                areaField.addEventListener('input', function () { areaField.setCustomValidity(''); });
                areaMap.on(L.Draw.Event.EDITED, function () {
                    areaField.value = JSON.stringify(drawnItems.toGeoJSON());
                    areaField.setCustomValidity('');
                });
                areaMap.on(L.Draw.Event.DELETED, function () {
                    areaField.value = JSON.stringify(drawnItems.toGeoJSON());
                    areaField.setCustomValidity('');
                });
                window.setTimeout(function () { areaMap.invalidateSize(); }, 100);
            })();
            </script>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="bl_locator_google_maps_api_key"><?php echo esc_html__('Google Maps API key', 'branch-locator-map'); ?></label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="bl_locator_google_maps_api_key"
                            name="bl_locator_google_maps_api_key"
                            value="<?php echo esc_attr($saved_key); ?>"
                            class="regular-text"
                            autocomplete="new-password"
                            spellcheck="false"
                        />
                        <p class="description"><?php echo esc_html__('For Google Maps, enable Maps JavaScript API and Geocoding API in Google Cloud. Restrict this key to your website HTTP referrers.', 'branch-locator-map'); ?></p>
                    </td>
                </tr>
            </table>
            <h2><?php echo esc_html__('Branches', 'branch-locator-map'); ?></h2>
            <p><?php echo esc_html__('Add as many marker locations as needed. For OpenStreetMap, enter latitude and longitude for each marker. Each marker can have translations and a custom icon.', 'branch-locator-map'); ?></p>
            <input type="hidden" name="bl_locator_branches[__empty]" value="" />
            <div id="bl-locator-branches">
                <?php foreach ($saved_branches as $index => $branch) : ?>
                    <?php bl_locator_render_branch_row($branch, $index); ?>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button" id="bl-locator-add-branch"><?php echo esc_html__('Add branch', 'branch-locator-map'); ?></button>
                <button type="button" class="button" id="bl-locator-remove-all-branches"><?php echo esc_html__('Remove all markers', 'branch-locator-map'); ?></button>
            </p>
            <p class="description"><?php echo esc_html__('Use [branch_locator] on any page, post, or custom post type. Optional attributes: provider="osm|google", lang="fr", zoom="12", center_lat="48.8566", center_lng="2.3522", height="600px".', 'branch-locator-map'); ?></p>
            <template id="bl-locator-branch-template">
                <?php bl_locator_render_branch_row(array(), '__INDEX__'); ?>
            </template>
            <template id="bl-locator-translation-template">
                <?php bl_locator_render_translation_row(array(), '__BRANCH_INDEX__', '__TRANSLATION_INDEX__'); ?>
            </template>
            <?php submit_button(); ?>
        </form>
    </div>
    <script>
    (function () {
        const list = document.getElementById('bl-locator-branches');
        const template = document.getElementById('bl-locator-branch-template');
        const addButton = document.getElementById('bl-locator-add-branch');
        if (!list || !template || !addButton) return;
        const removeAllButton = document.getElementById('bl-locator-remove-all-branches');

        let nextIndex = <?php echo (int) count($saved_branches); ?>;
        addButton.addEventListener('click', function () {
            const row = template.content.cloneNode(true);
            replaceTokens(row, { '__INDEX__': String(nextIndex) });
            list.appendChild(row);
            nextIndex++;
        });
        removeAllButton.addEventListener('click', function () {
            list.replaceChildren();
        });

        list.addEventListener('click', function (event) {
            const removeButton = event.target.closest('.bl-locator-remove-branch');
            if (removeButton) {
                removeButton.closest('.bl-locator-branch').remove();
                return;
            }

            const removeTranslationButton = event.target.closest('.bl-locator-remove-translation');
            if (removeTranslationButton) {
                removeTranslationButton.closest('.bl-locator-translation').remove();
                return;
            }

            const addTranslationButton = event.target.closest('.bl-locator-add-translation');
            if (addTranslationButton) {
                const branch = addTranslationButton.closest('.bl-locator-branch');
                const translationIndex = Number(branch.dataset.nextTranslation);
                const translation = document.getElementById('bl-locator-translation-template').content.cloneNode(true);
                replaceTokens(translation, {
                    '__BRANCH_INDEX__': branch.dataset.branchIndex,
                    '__TRANSLATION_INDEX__': String(translationIndex)
                });
                branch.querySelector('.bl-locator-translations').appendChild(translation);
                branch.dataset.nextTranslation = String(translationIndex + 1);
            }

            const iconButton = event.target.closest('.bl-locator-select-icon');
            if (iconButton && window.wp && wp.media) {
                const branch = iconButton.closest('.bl-locator-branch');
                const frame = wp.media({
                    title: <?php echo wp_json_encode(__('Choose marker icon', 'branch-locator-map')); ?>,
                    button: { text: <?php echo wp_json_encode(__('Use marker icon', 'branch-locator-map')); ?> },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function () {
                    const attachment = frame.state().get('selection').first().toJSON();
                    branch.querySelector('.bl-locator-marker-icon').value = attachment.url;
                    const preview = branch.querySelector('.bl-locator-icon-preview');
                    preview.src = attachment.url;
                    preview.hidden = false;
                });
                frame.open();
            }
        });

        function replaceTokens(fragment, replacements) {
            fragment.querySelectorAll('[name], [id], [for], [data-branch-index]').forEach(function (element) {
                ['name', 'id', 'for', 'data-branch-index'].forEach(function (attribute) {
                    if (element.hasAttribute(attribute)) {
                        let value = element.getAttribute(attribute);
                        Object.keys(replacements).forEach(function (token) {
                            value = value.replace(new RegExp(token, 'g'), replacements[token]);
                        });
                        element.setAttribute(attribute, value);
                    }
                });
            });
        }
    })();
    </script>
    <style>
        .bl-locator-branch { max-width: 900px; margin: 16px 0; padding: 16px; border: 1px solid #c3c4c7; }
        .bl-locator-translations { display: grid; gap: 12px; }
        .bl-locator-translation { display: grid; grid-template-columns: minmax(100px, 0.5fr) repeat(2, minmax(200px, 1fr)) auto; gap: 12px; align-items: end; }
        .bl-locator-translation input { width: 100%; }
        @media (max-width: 782px) { .bl-locator-translation { grid-template-columns: 1fr; } }
    </style>
    <?php
}

function bl_locator_render_branch_row($branch, $index) {
    $translations = isset($branch['translations']) && is_array($branch['translations'])
        ? $branch['translations']
        : array();
    if (empty($translations)) {
        $translations = array(array('language' => '', 'name' => '', 'address' => ''));
    }
    ?>
    <fieldset class="bl-locator-branch" data-branch-index="<?php echo esc_attr($index); ?>" data-next-translation="<?php echo esc_attr(count($translations)); ?>">
        <legend>
            <strong><?php echo esc_html(sprintf(
                /* translators: %s: branch number. */
                __('Branch %s', 'branch-locator-map'),
                is_numeric($index) ? ((int) $index + 1) : ''
            )); ?></strong>
            <button type="button" class="button-link-delete bl-locator-remove-branch"><?php echo esc_html__('Remove', 'branch-locator-map'); ?></button>
        </legend>
        <div class="bl-locator-translations">
            <?php foreach ($translations as $translation_index => $translation) :
                bl_locator_render_translation_row($translation, $index, $translation_index);
            endforeach; ?>
        </div>
        <p><button type="button" class="button bl-locator-add-translation"><?php echo esc_html__('Add language translation', 'branch-locator-map'); ?></button></p>
        <p>
            <label>
                <?php echo esc_html__('Phone', 'branch-locator-map'); ?><br />
                <input type="tel" name="bl_locator_branches[<?php echo esc_attr($index); ?>][phone]" value="<?php echo esc_attr(isset($branch['phone']) ? $branch['phone'] : ''); ?>" class="regular-text" />
            </label>
        </p>
        <p>
            <label>
                <?php echo esc_html__('Google Maps directions URL (optional)', 'branch-locator-map'); ?><br />
                <input type="url" name="bl_locator_branches[<?php echo esc_attr($index); ?>][map_url]" value="<?php echo esc_attr(isset($branch['map_url']) ? $branch['map_url'] : ''); ?>" class="regular-text" />
            </label>
        </p>
        <p>
            <label><?php echo esc_html__('Marker latitude', 'branch-locator-map'); ?>
                <input type="number" step="any" min="-90" max="90" name="bl_locator_branches[<?php echo esc_attr($index); ?>][lat]" value="<?php echo esc_attr(isset($branch['lat']) ? $branch['lat'] : ''); ?>" class="small-text" />
            </label>
            <label><?php echo esc_html__('Marker longitude', 'branch-locator-map'); ?>
                <input type="number" step="any" min="-180" max="180" name="bl_locator_branches[<?php echo esc_attr($index); ?>][lng]" value="<?php echo esc_attr(isset($branch['lng']) ? $branch['lng'] : ''); ?>" class="small-text" />
            </label>
        </p>
        <p>
            <label><?php echo esc_html__('Custom marker icon URL', 'branch-locator-map'); ?><br />
                <input type="url" name="bl_locator_branches[<?php echo esc_attr($index); ?>][icon]" value="<?php echo esc_attr(isset($branch['icon']) ? $branch['icon'] : ''); ?>" class="regular-text bl-locator-marker-icon" />
            </label>
            <button type="button" class="button bl-locator-select-icon"><?php echo esc_html__('Choose image', 'branch-locator-map'); ?></button>
            <img class="bl-locator-icon-preview" src="<?php echo esc_url(isset($branch['icon']) ? $branch['icon'] : ''); ?>" alt="" style="max-width:32px;max-height:32px;vertical-align:middle;" <?php echo empty($branch['icon']) ? 'hidden' : ''; ?> />
        </p>
    </fieldset>
    <?php
}

function bl_locator_render_translation_row($translation, $branch_index, $translation_index) {
    $fields = array(
        'language' => __('Language code (for example, fr or zh-CN)', 'branch-locator-map'),
        'name' => __('Branch name', 'branch-locator-map'),
        'address' => __('Address', 'branch-locator-map'),
    );
    ?>
    <div class="bl-locator-translation">
        <?php foreach ($fields as $key => $label) :
            $input_id = 'bl-locator-translation-' . $branch_index . '-' . $translation_index . '-' . $key;
            ?>
            <label for="<?php echo esc_attr($input_id); ?>">
                <?php echo esc_html($label); ?><br />
                <input
                    type="text"
                    id="<?php echo esc_attr($input_id); ?>"
                    name="bl_locator_branches[<?php echo esc_attr($branch_index); ?>][translations][<?php echo esc_attr($translation_index); ?>][<?php echo esc_attr($key); ?>]"
                    value="<?php echo esc_attr(isset($translation[$key]) ? $translation[$key] : ''); ?>"
                    <?php echo $key === 'language' ? 'placeholder="en"' : ''; ?>
                    class="regular-text"
                />
            </label>
        <?php endforeach; ?>
        <button type="button" class="button-link-delete bl-locator-remove-translation"><?php echo esc_html__('Remove translation', 'branch-locator-map'); ?></button>
    </div>
    <?php
}

function bl_locator_sanitize_branches($branches) {
    return bl_locator_normalize_branches($branches);
}

function bl_locator_normalize_branches($branches) {
    if (!is_array($branches)) {
        return array();
    }

    $sanitized = array();
    $used_ids = array();
    foreach ($branches as $branch) {
        if (!is_array($branch)) {
            continue;
        }

        $translations = array();
        if (isset($branch['translations']) && is_array($branch['translations'])) {
            foreach ($branch['translations'] as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                $language = isset($translation['language']) && is_scalar($translation['language'])
                    ? strtolower(str_replace('_', '-', sanitize_text_field((string) $translation['language'])))
                    : '';
                if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language)) {
                    if (!empty($translation['language']) || !empty($translation['name']) || !empty($translation['address'])) {
                        add_settings_error('bl_locator_branches', 'invalid_branch_language', __('A branch translation was skipped because its language code is invalid.', 'branch-locator-map'));
                    }
                    continue;
                }
                $name = isset($translation['name']) && is_scalar($translation['name'])
                    ? sanitize_text_field((string) $translation['name'])
                    : '';
                $address = isset($translation['address']) && is_scalar($translation['address'])
                    ? sanitize_text_field((string) $translation['address'])
                    : '';
                if ($name === '' || $address === '') {
                    if ($name !== '' || $address !== '') {
                        add_settings_error('bl_locator_branches', 'incomplete_branch_translation', __('Each branch translation needs both a name and an address.', 'branch-locator-map'));
                    }
                    continue;
                }
                $translations[$language] = array(
                    'language' => $language,
                    'name' => $name,
                    'address' => $address,
                );
            }
        } else {
            foreach ($branch as $key => $legacy_name) {
                if (!preg_match('/^name_([a-z]{2,3}(?:[_-][a-z0-9]{2,8})*)$/i', (string) $key, $matches)) {
                    continue;
                }
                $language = strtolower(str_replace('_', '-', $matches[1]));
                $address_key = 'address_' . $matches[1];
                $legacy_address = isset($branch[$address_key]) ? $branch[$address_key] : '';
                if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language)
                    || !is_scalar($legacy_name) || !is_scalar($legacy_address)) {
                    continue;
                }
                $name = sanitize_text_field((string) $legacy_name);
                $address = sanitize_text_field((string) $legacy_address);
                if ($name !== '' && $address !== '') {
                    $translations[$language] = array(
                        'language' => $language,
                        'name' => $name,
                        'address' => $address,
                    );
                }
            }
        }
        if (empty($translations)) {
            continue;
        }

        $has_lat = isset($branch['lat']) && $branch['lat'] !== '';
        $has_lng = isset($branch['lng']) && $branch['lng'] !== '';
        $valid_lat = $has_lat && is_numeric($branch['lat']) && (float) $branch['lat'] >= -90 && (float) $branch['lat'] <= 90;
        $valid_lng = $has_lng && is_numeric($branch['lng']) && (float) $branch['lng'] >= -180 && (float) $branch['lng'] <= 180;
        if (($has_lat || $has_lng) && (!$valid_lat || !$valid_lng)) {
            add_settings_error('bl_locator_branches', 'invalid_branch_coordinates', __('A marker needs both valid coordinates: latitude -90 to 90 and longitude -180 to 180. The address will be used as a fallback where supported.', 'branch-locator-map'));
        }

        $first_translation = reset($translations);
        $base_id = sanitize_title($first_translation['name']);
        if ($base_id === '') {
            $base_id = 'branch';
        }
        $id = $base_id;
        $suffix = 2;
        while (isset($used_ids[$id])) {
            $id = $base_id . '-' . $suffix;
            $suffix++;
        }
        $used_ids[$id] = true;

        $sanitized[] = array(
            'id' => $id,
            'translations' => array_values($translations),
            'phone' => isset($branch['phone']) && is_scalar($branch['phone'])
                ? sanitize_text_field((string) $branch['phone'])
                : '',
            'map_url' => isset($branch['map_url']) && is_scalar($branch['map_url'])
                ? esc_url_raw((string) $branch['map_url'])
                : '',
            'lat' => $valid_lat && $valid_lng
                ? (float) $branch['lat']
                : null,
            'lng' => $valid_lat && $valid_lng
                ? (float) $branch['lng']
                : null,
            'icon' => isset($branch['icon']) && is_scalar($branch['icon'])
                ? esc_url_raw((string) $branch['icon'])
                : '',
        );
    }

    return $sanitized;
}

function bl_get_branches($lang = 'en') {
    $branches = bl_locator_normalize_branches(get_option('bl_locator_branches', array()));
    $language = strtolower(str_replace('_', '-', (string) $lang));
    $base_language = strtok($language, '-');

    $localized_branches = array();
    foreach ($branches as $branch) {
        $translation = null;
        foreach ($branch['translations'] as $candidate) {
            if ($candidate['language'] === $language) {
                $translation = $candidate;
                break;
            }
            if ($translation === null && $candidate['language'] === $base_language) {
                $translation = $candidate;
            }
        }
        if ($translation === null) {
            $translation = reset($branch['translations']);
        }

        $localized_branches[] = array(
            'id' => $branch['id'],
            'name' => $translation['name'],
            'address' => $translation['address'],
            'phone' => isset($branch['phone']) ? $branch['phone'] : '',
            'map_url' => isset($branch['map_url']) ? $branch['map_url'] : '',
            'geocode_address' => $translation['address'],
            'lat' => isset($branch['lat']) ? $branch['lat'] : null,
            'lng' => isset($branch['lng']) ? $branch['lng'] : null,
            'icon' => isset($branch['icon']) ? $branch['icon'] : '',
        );
    }

    return $localized_branches;
}

function bl_locator_shortcode($atts = array()) {
    $atts = shortcode_atts(array(
        'lang' => '',
        'provider' => '',
        'zoom' => '',
        'center_lat' => '',
        'center_lng' => '',
        'height' => '',
    ), $atts, 'branch_locator');

    $requested_language = is_scalar($atts['lang']) ? (string) $atts['lang'] : '';
    $lang = strtolower(str_replace('_', '-', sanitize_text_field($requested_language)));
    if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $lang)) {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $lang = strtolower(str_replace('_', '-', $locale));
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $lang)) {
            $lang = 'en';
        }
    }

    $map_settings = bl_locator_get_map_settings();
    $provider = is_scalar($atts['provider']) ? sanitize_key((string) $atts['provider']) : '';
    if (!in_array($provider, array('google', 'osm'), true)) {
        $provider = $map_settings['provider'];
    }
    $zoom = is_numeric($atts['zoom'])
        ? max(1, min(20, (int) $atts['zoom']))
        : (is_numeric($map_settings['zoom']) ? (int) $map_settings['zoom'] : null);
    $center_lat = is_numeric($atts['center_lat'])
        ? (float) $atts['center_lat']
        : (is_numeric($map_settings['center_lat']) ? (float) $map_settings['center_lat'] : null);
    $center_lng = is_numeric($atts['center_lng'])
        ? (float) $atts['center_lng']
        : (is_numeric($map_settings['center_lng']) ? (float) $map_settings['center_lng'] : null);
    if ($center_lat !== null && ($center_lat < -90 || $center_lat > 90)) {
        $center_lat = null;
    }
    if ($center_lng !== null && ($center_lng < -180 || $center_lng > 180)) {
        $center_lng = null;
    }
    if ($center_lat === null || $center_lng === null) {
        $center_lat = null;
        $center_lng = null;
    }
    $height = is_scalar($atts['height']) && preg_match('/^\d{2,4}(?:px)?$/', (string) $atts['height'])
        ? (string) $atts['height']
        : '500px';
    if (ctype_digit($height)) {
        $height .= 'px';
    }

    $api_key = defined('BRANCH_LOCATOR_GOOGLE_MAPS_API_KEY')
        ? BRANCH_LOCATOR_GOOGLE_MAPS_API_KEY
        : get_option('bl_locator_google_maps_api_key', '');

    $id = function_exists('wp_unique_id') ? wp_unique_id('bl-locator-') : ('bl-locator-' . wp_rand(1000, 999999));
    $branches = bl_get_branches($lang);
    $labels = array(
        'heading' => __('Our Branches', 'branch-locator-map'),
        'address' => __('Address', 'branch-locator-map'),
        'phone' => __('Phone', 'branch-locator-map'),
        'directions' => __('Get directions', 'branch-locator-map'),
        'map_error' => __('The map could not be loaded. Please try again later.', 'branch-locator-map'),
        'key_error' => __('The Google Maps API key has not been configured.', 'branch-locator-map'),
        'loading' => __('Loading map…', 'branch-locator-map'),
        'branch_list' => __('All branches', 'branch-locator-map'),
        'no_branches' => __('No branches have been added.', 'branch-locator-map'),
        'data_error' => __('Branch locator data could not be loaded.', 'branch-locator-map'),
        'reset' => __('Reset map', 'branch-locator-map'),
        'locate' => __('Use my location', 'branch-locator-map'),
        'clear_markers' => __('Remove markers', 'branch-locator-map'),
        'location_error' => __('Your location could not be determined.', 'branch-locator-map'),
        'osm_coordinates_error' => __('OpenStreetMap markers need latitude and longitude. Add coordinates in Branch Locator settings.', 'branch-locator-map'),
    );

    $data = array(
        'lang' => $lang,
        'provider' => $provider,
        'labels' => $labels,
        'branches' => $branches,
        'apiKey' => $api_key,
        'leafletCssUrl' => plugins_url('assets/vendor/leaflet/leaflet.css', __FILE__),
        'leafletScriptUrl' => plugins_url('assets/vendor/leaflet/leaflet.js', __FILE__),
        'center' => $center_lat === null ? null : array($center_lat, $center_lng),
        'zoom' => $zoom,
        'googleStyles' => json_decode($map_settings['google_styles'], true),
        'serviceArea' => json_decode($map_settings['service_area'], true),
    );

    ob_start();
    ?>
    <section id="<?php echo esc_attr($id); ?>" class="bl-locator" dir="auto" lang="<?php echo esc_attr($lang); ?>">
        <h2 class="bl-heading"><?php echo esc_html($labels['heading']); ?></h2>
        <div class="bl-map" role="region" aria-label="<?php echo esc_attr($labels['heading']); ?>">
            <div class="bl-map-status" role="status"><?php echo esc_html($labels['loading']); ?></div>
        </div>
        <div class="bl-map-controls">
            <button type="button" class="bl-map-reset" disabled><?php echo esc_html($labels['reset']); ?></button>
            <button type="button" class="bl-map-locate" disabled><?php echo esc_html($labels['locate']); ?></button>
            <button type="button" class="bl-map-clear" disabled><?php echo esc_html($labels['clear_markers']); ?></button>
        </div>
        <h3 class="bl-list-heading"><?php echo esc_html($labels['branch_list']); ?></h3>
        <div class="bl-branch-list"></div>
        <script type="application/json" class="bl-data"><?php echo wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
    </section>
    <style>
        #<?php echo esc_attr($id); ?> { width:100%; color:#222; }
        #<?php echo esc_attr($id); ?> .bl-heading { margin:0 0 18px; font-size:clamp(24px,3vw,34px); line-height:1.3; }
        #<?php echo esc_attr($id); ?> .bl-map { position:relative; width:100%; height:<?php echo esc_attr($height); ?>; min-height:320px; overflow:hidden; border-radius:10px; background:#f1f3f4; }
        #<?php echo esc_attr($id); ?> .bl-map-controls { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        #<?php echo esc_attr($id); ?> .bl-map-controls button { padding:8px 12px; border:1px solid #bbb; border-radius:4px; background:#fff; cursor:pointer; font:inherit; }
        #<?php echo esc_attr($id); ?> .bl-map-status { position:absolute; z-index:1; inset:0; display:flex; align-items:center; justify-content:center; padding:20px; text-align:center; }
        #<?php echo esc_attr($id); ?> .bl-list-heading { margin:24px 0 12px; font-size:22px; }
        #<?php echo esc_attr($id); ?> .bl-branch-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        #<?php echo esc_attr($id); ?> .bl-branch-card { display:block; width:100%; padding:18px; text-align:inherit; color:inherit; background:#fff; border:1px solid #ddd; border-radius:8px; cursor:pointer; font:inherit; }
        #<?php echo esc_attr($id); ?> .bl-branch-card:hover, #<?php echo esc_attr($id); ?> .bl-branch-card:focus-visible { border-color:#4285f4; outline:2px solid #4285f4; outline-offset:2px; }
        #<?php echo esc_attr($id); ?> .bl-branch-name { display:block; margin-bottom:8px; font-weight:700; }
        #<?php echo esc_attr($id); ?> .bl-branch-address { display:block; margin-bottom:8px; line-height:1.5; overflow-wrap:anywhere; }
        #<?php echo esc_attr($id); ?> .bl-branch-phone { display:inline-block; color:#1769d2; text-decoration:underline; }
        #<?php echo esc_attr($id); ?> .bl-info { max-width:260px; padding:3px; color:#202124; font:14px/1.5 Arial,sans-serif; }
        #<?php echo esc_attr($id); ?> .bl-info strong { display:block; margin-bottom:6px; font-size:15px; }
        #<?php echo esc_attr($id); ?> .bl-info p { margin:5px 0; }
        #<?php echo esc_attr($id); ?> .bl-info a { color:#1769d2; }
        @media(max-width:640px) {
            #<?php echo esc_attr($id); ?> .bl-branch-list { grid-template-columns:1fr; }
        }
    </style>
    <script>
    (function () {
        const root = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!root || root.dataset.blStarted === '1') return;
        root.dataset.blStarted = '1';

        let config;
        try {
            config = JSON.parse(root.querySelector('.bl-data').textContent);
        } catch (e) {
            root.querySelector('.bl-map-status').textContent = <?php echo wp_json_encode($labels['data_error']); ?>;
            return;
        }

        const mapNode = root.querySelector('.bl-map');
        const statusNode = root.querySelector('.bl-map-status');
        const listNode = root.querySelector('.bl-branch-list');
        const labels = config.labels;
        let map, infoWindow, markers = [], markerBounds = [], locationMarkers = [], markersVisible = true;
        const initialCenter = config.center || [0, 0];
        const initialZoom = config.zoom || (config.center ? 12 : 2);

        function showError(message) {
            statusNode.textContent = message;
            statusNode.style.display = 'flex';
        }

        function addText(parent, tag, value) {
            const node = document.createElement(tag);
            node.textContent = value;
            parent.appendChild(node);
            return node;
        }

        function makeInfoContent(branch) {
            const box = document.createElement('div');
            box.className = 'bl-info';
            addText(box, 'strong', branch.name);
            const address = document.createElement('p');
            addText(address, 'b', labels.address + ': ');
            address.appendChild(document.createTextNode(branch.address));
            box.appendChild(address);
            if (branch.phone) {
                const phone = document.createElement('p');
                addText(phone, 'b', labels.phone + ': ');
                const tel = document.createElement('a');
                tel.href = 'tel:' + branch.phone.replace(/[^\d+]/g, '');
                tel.textContent = branch.phone;
                phone.appendChild(tel);
                box.appendChild(phone);
            }
            const directions = document.createElement('a');
            const destination = branch.lat !== null && branch.lng !== null
                ? branch.lat + ',' + branch.lng
                : branch.geocode_address;
            directions.href = branch.map_url || 'https://www.google.com/maps/dir/?api=1&destination=' +
                encodeURIComponent(destination);
            directions.target = '_blank';
            directions.rel = 'noopener noreferrer';
            directions.textContent = labels.directions;
            box.appendChild(directions);
            return box;
        }

        function openBranch(branch, marker) {
            if (config.provider === 'google') {
                infoWindow.setContent(makeInfoContent(branch));
                infoWindow.open({ map: map, anchor: marker });
            } else {
                marker.bindPopup(makeInfoContent(branch)).openPopup();
            }
        }

        function renderBranchList() {
            config.branches.forEach(function (branch, index) {
                const card = document.createElement('div');
                card.className = 'bl-branch-card';
                card.setAttribute('role', 'button');
                card.tabIndex = 0;
                const name = addText(card, 'span', branch.name);
                name.className = 'bl-branch-name';
                const address = addText(card, 'span', branch.address);
                address.className = 'bl-branch-address';
                if (branch.phone) {
                    const phone = document.createElement('a');
                    phone.className = 'bl-branch-phone';
                    phone.href = 'tel:' + branch.phone.replace(/[^\d+]/g, '');
                    phone.textContent = branch.phone;
                    phone.addEventListener('click', function (event) { event.stopPropagation(); });
                    card.appendChild(phone);
                }
                function focusBranch() {
                    if (markersVisible && markers[index]) {
                        if (config.provider === 'google') {
                            map.panTo(markers[index].getPosition());
                            map.setZoom(Math.max(map.getZoom() || 12, 12));
                        } else {
                            map.panTo(markers[index].getLatLng());
                            map.setZoom(Math.max(map.getZoom() || 12, 12));
                        }
                        openBranch(branch, markers[index]);
                    }
                }
                card.addEventListener('click', focusBranch);
                card.addEventListener('keydown', function (event) {
                    if (event.target === card && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        focusBranch();
                    }
                });
                listNode.appendChild(card);
            });
        }

        function createMarker(branch, index, position) {
            if (config.provider === 'google') {
                const options = {
                    map: markersVisible ? map : null,
                    position: position,
                    title: branch.name
                };
                if (branch.icon) options.icon = { url: branch.icon, scaledSize: new google.maps.Size(38, 38) };
                const marker = new google.maps.Marker(options);
                marker.addListener('click', function () { openBranch(branch, marker); });
                markers[index] = marker;
                markerBounds.push(position);
                return;
            }

            const options = { title: branch.name, alt: branch.name };
            if (branch.icon) {
                options.icon = L.icon({ iconUrl: branch.icon, iconSize: [38, 38], iconAnchor: [19, 38], popupAnchor: [0, -36] });
            }
            const marker = L.marker(position, options);
            if (markersVisible) marker.addTo(map);
            marker.on('click', function () { openBranch(branch, marker); });
            markers[index] = marker;
            markerBounds.push(position);
        }

        function addServiceArea() {
            if (!config.serviceArea || !config.serviceArea.features || !config.serviceArea.features.length) return;
            if (config.provider === 'google') {
                map.data.addGeoJson(config.serviceArea);
                map.data.setStyle({
                    fillColor: '#3388ff',
                    fillOpacity: 0.2,
                    strokeColor: '#1769d2',
                    strokeWeight: 2
                });
            } else {
                L.geoJSON(config.serviceArea, {
                    style: { color: '#1769d2', weight: 2, fillColor: '#3388ff', fillOpacity: 0.2 }
                }).addTo(map);
            }
        }

        function updateMarkerVisibility(visible) {
            markersVisible = visible;
            markers.forEach(function (marker) {
                if (!marker) return;
                if (config.provider === 'google') {
                    marker.setMap(visible ? map : null);
                } else if (visible) {
                    marker.addTo(map);
                } else {
                    map.removeLayer(marker);
                }
            });
        }

        function setInitialView() {
            if (!map) return;
            if (config.provider === 'google') {
                if (config.center) {
                    map.setCenter({ lat: config.center[0], lng: config.center[1] });
                    map.setZoom(initialZoom);
                } else if (markerBounds.length > 1) {
                    const bounds = new google.maps.LatLngBounds();
                    markerBounds.forEach(function (point) { bounds.extend(point); });
                    if (config.zoom) {
                        map.setCenter(bounds.getCenter());
                        map.setZoom(initialZoom);
                    } else {
                        map.fitBounds(bounds, 50);
                        if (map.getZoom() > 16) map.setZoom(16);
                    }
                } else if (markerBounds.length === 1) {
                    map.setCenter(markerBounds[0]);
                    map.setZoom(config.zoom || 12);
                }
            } else if (config.center) {
                map.setView(config.center, initialZoom);
            } else if (markerBounds.length > 1) {
                const bounds = L.latLngBounds(markerBounds);
                if (config.zoom) map.setView(bounds.getCenter(), initialZoom);
                else map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
            } else if (markerBounds.length === 1) {
                map.setView(markerBounds[0], config.zoom || 12);
            }
        }

        function addControls() {
            root.querySelector('.bl-map-reset').addEventListener('click', function () {
                removeLocationMarkers();
                updateMarkerVisibility(true);
                setInitialView();
            });
            root.querySelector('.bl-map-clear').addEventListener('click', function () {
                updateMarkerVisibility(false);
                removeLocationMarkers();
            });
            root.querySelector('.bl-map-locate').addEventListener('click', function () {
                if (!map) return;
                if (!navigator.geolocation) {
                    showError(labels.location_error);
                    return;
                }
                navigator.geolocation.getCurrentPosition(function (position) {
                    statusNode.style.display = 'none';
                    const point = [position.coords.latitude, position.coords.longitude];
                    if (config.provider === 'google') {
                        const latLng = { lat: point[0], lng: point[1] };
                        map.setCenter(latLng);
                        map.setZoom(15);
                        locationMarkers.push(new google.maps.Marker({ map: map, position: latLng, title: labels.locate }));
                    } else {
                        map.setView(point, 15);
                        locationMarkers.push(L.marker(point).addTo(map).bindPopup(labels.locate).openPopup());
                    }
                }, function () { showError(labels.location_error); }, { enableHighAccuracy: true, timeout: 10000 });
            });
        }

        function removeLocationMarkers() {
            locationMarkers.forEach(function (marker) {
                if (config.provider === 'google') marker.setMap(null);
                else map.removeLayer(marker);
            });
            locationMarkers = [];
        }

        function loadLeaflet() {
            if (window.L) {
                initMap();
                return;
            }

            if (!window.branchLocatorLeafletPromise) {
                window.branchLocatorLeafletPromise = new Promise(function (resolve, reject) {
                    if (!document.getElementById('bl-leaflet-frontend-css')) {
                        const style = document.createElement('link');
                        style.id = 'bl-leaflet-frontend-css';
                        style.rel = 'stylesheet';
                        style.href = config.leafletCssUrl;
                        document.head.appendChild(style);
                    }
                    const script = document.createElement('script');
                    script.src = config.leafletScriptUrl;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            }
            window.branchLocatorLeafletPromise.then(initMap).catch(function () {
                showError(labels.map_error);
            });
        }

        function initMap() {
            if (config.provider === 'google') {
                if (!window.google || !google.maps) {
                    showError(labels.map_error);
                    return;
                }
                const options = {
                    center: { lat: initialCenter[0], lng: initialCenter[1] },
                    zoom: initialZoom,
                    mapTypeId: 'roadmap',
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    clickableIcons: false,
                    gestureHandling: 'cooperative'
                };
                if (Array.isArray(config.googleStyles)) options.styles = config.googleStyles;
                map = new google.maps.Map(mapNode, options);
                infoWindow = new google.maps.InfoWindow();
                addServiceArea();
                const geocoder = new google.maps.Geocoder();
                let pending = config.branches.length;
                if (!pending) finishMap();
                config.branches.forEach(function (branch, index) {
                    const done = function () {
                        pending--;
                        if (!pending) {
                            finishMap();
                            if (markers.filter(Boolean).length !== config.branches.length) showError(labels.map_error);
                        }
                    };
                    if (branch.lat !== null && branch.lng !== null) {
                        createMarker(branch, index, { lat: branch.lat, lng: branch.lng });
                        done();
                    } else {
                        geocoder.geocode({ address: branch.geocode_address }, function (results, status) {
                            if (status === 'OK' && results && results[0]) {
                                createMarker(branch, index, results[0].geometry.location);
                            }
                            done();
                        });
                    }
                });
                return;
            }

            if (!window.L) {
                showError(labels.map_error);
                return;
            }
            map = L.map(mapNode).setView(initialCenter, initialZoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            addServiceArea();
            config.branches.forEach(function (branch, index) {
                if (branch.lat === null || branch.lng === null) return;
                createMarker(branch, index, [branch.lat, branch.lng]);
            });
            finishMap();
            if (markers.filter(Boolean).length !== config.branches.length && config.branches.length) showError(labels.osm_coordinates_error);
        }

        function finishMap() {
            setInitialView();
            statusNode.style.display = 'none';
            root.querySelectorAll('.bl-map-controls button').forEach(function (button) {
                button.disabled = false;
            });
        }

        renderBranchList();
        addControls();

        if (!config.branches.length) {
            if (config.provider === 'google' && !config.apiKey) {
                showError(labels.key_error);
                return;
            }
        }

        if (config.provider === 'osm') {
            loadLeaflet();
        } else {
            if (!config.apiKey) {
                showError(labels.key_error);
                return;
            }
            window.branchLocatorQueue = window.branchLocatorQueue || [];
            window.branchLocatorQueue.push(initMap);

            if (!window.branchLocatorLoaderStarted) {
                window.branchLocatorLoaderStarted = true;
                window.initBranchLocatorMaps = function () {
                    (window.branchLocatorQueue || []).forEach(function (init) { init(); });
                    window.branchLocatorQueue = [];
                };
                const script = document.createElement('script');
                script.src = 'https://maps.googleapis.com/maps/api/js?key=' +
                    encodeURIComponent(config.apiKey) +
                    '&callback=initBranchLocatorMaps&language=' +
                    encodeURIComponent(config.lang) +
                    '&loading=async';
                script.async = true;
                script.defer = true;
                script.onerror = function () { showError(labels.map_error); };
                document.head.appendChild(script);
            }
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('branch_locator', 'bl_locator_shortcode');
