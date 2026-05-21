<?php
/**
 * Bricks Builder helper functions.
 *
 * Whitelists small helpers so Bricks dynamic-data tags can decode a unit's
 * features JSON into a PHP array (for use with the native Array Query Loop
 * type introduced in Bricks 2.2). Each helper is exposed via the
 * `bricks/code/echo_function_names` allowlist so it can be referenced as
 * `{echo:function_name}` in a Bricks Query Loop's "Array source" field.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * German labels for the standard feature keys + direction values.
 * Falls back to the raw key if no label is mapped.
 */
if (!function_exists('immoadmin_feature_label')) {
function immoadmin_feature_label($key) {
    if (!is_string($key) || $key === '') return '';
    $labels = array(
        // Outdoor
        'balcony'               => 'Balkon',
        'terrace'               => 'Terrasse',
        'loggia'                => 'Loggia',
        'garden'                => 'Garten',
        'rooftop_terrace'       => 'Dachterrasse',
        // Accessibility / building
        'elevator'              => 'Aufzug',
        'wheelchair_accessible' => 'Rollstuhlgerecht',
        'barrier_free'          => 'Barrierefrei',
        'basement'              => 'Keller',
        'storage_room'          => 'Abstellraum',
        'bicycle_room'          => 'Fahrradraum',
        // Kitchen
        'fitted_kitchen'        => 'Einbauküche',
        'open_kitchen'          => 'Offene Küche',
        'dishwasher'            => 'Geschirrspüler',
        'refrigerator'          => 'Kühlschrank',
        'washing_machine'       => 'Waschmaschine',
        // Bath
        'bathtub'               => 'Badewanne',
        'shower'                => 'Dusche',
        'guest_wc'              => 'Gäste-WC',
        // Comfort
        'floor_heating'         => 'Fußbodenheizung',
        'air_conditioning'      => 'Klimaanlage',
        'fireplace'             => 'Kamin',
        'sauna'                 => 'Sauna',
        'pool'                  => 'Pool',
        // Security
        'alarm_system'          => 'Alarmanlage',
        'video_intercom'        => 'Video-Türsprechanlage',
        // Directions (also live in the features array — useful for an
        // orientation loop, but filtered OUT of the amenities loop below)
        'north'                 => 'Nord',
        'south'                 => 'Süd',
        'east'                  => 'Ost',
        'west'                  => 'West',
    );
    return isset($labels[$key]) ? $labels[$key] : $key;
}
}

if (!function_exists('immoadmin_unit_features_raw')) {
/**
 * Decode a unit's features meta into a PHP array of strings.
 * Accepts both raw arrays (newer WP) and JSON strings (current sync format).
 */
function immoadmin_unit_features_raw($post_id = 0) {
    // Resolve post id across contexts (loop, archive, single, Bricks builder).
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    if (!$post_id) {
        $post_id = get_queried_object_id();
    }
    // Bricks builder preview: the post id is passed via GET parameter.
    if (!$post_id && isset($_GET['bricks_preview']) && isset($_GET['post_id'])) {
        $post_id = (int) $_GET['post_id'];
    }
    if (!$post_id && isset($_GET['p'])) {
        $post_id = (int) $_GET['p'];
    }
    if (!$post_id) return array();
    $raw = get_post_meta($post_id, 'features', true);
    if (is_array($raw)) return array_values(array_filter($raw, 'is_string'));
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, 'is_string'));
    }
    return array();
}
}

if (!function_exists('immoadmin_unit_amenities')) {
/**
 * Amenities only (features WITHOUT direction values).
 * Returns an array of [ 'key' => ..., 'label' => ... ] so a Bricks Array
 * Query Loop can access both via {query_array @key:'key'} / 'label'.
 */
function immoadmin_unit_amenities($post_id = 0) {
    $directions = array('north', 'south', 'east', 'west');
    $features   = immoadmin_unit_features_raw($post_id);
    $out        = array();
    foreach ($features as $key) {
        if (in_array($key, $directions, true)) continue;
        $out[] = array(
            'key'   => $key,
            'label' => immoadmin_feature_label($key),
        );
    }
    return $out;
}
}

if (!function_exists('immoadmin_unit_orientations')) {
/**
 * Orientations only (direction values from the features array).
 * Returns an array of [ 'key' => 'west', 'label' => 'West' ] so the same
 * Bricks Array loop pattern works for orientation badges.
 */
function immoadmin_unit_orientations($post_id = 0) {
    $directions = array('north', 'south', 'east', 'west');
    $features   = immoadmin_unit_features_raw($post_id);
    $out        = array();
    foreach ($directions as $dir) {
        if (in_array($dir, $features, true)) {
            $out[] = array(
                'key'   => $dir,
                'label' => immoadmin_feature_label($dir),
            );
        }
    }
    return $out;
}
}

/**
 * Whitelist the helpers for Bricks `{echo:fn_name}` dynamic-data tag.
 * Without this, Bricks silently rejects the call as unsafe.
 */
add_filter('bricks/code/echo_function_names', function ($names) {
    $names[] = 'immoadmin_unit_amenities';
    $names[] = 'immoadmin_unit_orientations';
    $names[] = 'immoadmin_unit_features_raw';
    $names[] = 'immoadmin_feature_label';
    return $names;
});
