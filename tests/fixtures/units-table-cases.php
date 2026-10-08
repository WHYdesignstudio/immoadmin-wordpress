<?php
/**
 * Units-table render cases shared by tests/run-tests.php.
 *
 * units-table-baseline.json holds the output of these cases rendered with
 * v2.13.0 (before the filter widgets existed). The test re-renders them with
 * the current code and demands byte-identical HTML: a table without a
 * "Filter-Gruppe" must not change by a single byte.
 *
 * Regenerate ONLY on purpose: php tests/run-tests.php --write-baseline
 */

function immoadmin_test_table_fixture_posts() {
    $posts = array(
        701 => array('status' => 'available', 'status_label' => 'Verfügbar', 'building_name' => 'Presto', 'door_number' => '1',
            'floor' => '-10', 'floor_label' => 'GG+EG', 'floor_to' => '0', 'floor_to_label' => 'EG', 'orientation' => 'south',
            'features' => '["balcony","south","west"]', 'room_count' => '3', 'living_area' => '79.9', 'living_area_formatted' => '79,9 m²',
            'purchase_price' => '439800', 'purchase_price_formatted' => '439.800', 'garden_area_formatted' => '80 m²'),
        702 => array('status' => 'reserved', 'status_label' => 'Reserviert', 'building_name' => 'Allegro', 'door_number' => '2',
            'floor' => '1', 'floor_label' => '1. OG', 'orientation' => 'east', 'room_count' => '2', 'living_area' => '54',
            'living_area_formatted' => '54 m²', 'purchase_price' => '500000', 'purchase_price_formatted' => '500.000'),
        703 => array('status' => 'sold', 'status_label' => 'Verkauft', 'building_name' => 'Presto', 'door_number' => '3',
            'floor' => '99', 'floor_label' => 'DG', 'room_count' => '4', 'living_area' => '120', 'living_area_formatted' => '120 m²',
            'purchase_price' => '760000', 'purchase_price_formatted' => '760.000'),
        704 => array('status' => 'available', 'status_label' => 'Verfügbar', 'building_name' => 'Largo', 'door_number' => '4',
            'floor' => '0', 'floor_label' => 'EG', 'room_count' => '0', 'living_area' => '0', 'purchase_price' => '0'),
    );
    foreach ($posts as $id => $meta) {
        $GLOBALS['__post_types'][$id] = 'immoadmin_wohnung';
        $GLOBALS['__meta'][$id] = $meta;
    }
    return array_keys($posts);
}

/**
 * @return array name => settings
 */
function immoadmin_test_table_cases() {
    $preset = ImmoAdmin_Units_Table::default_columns();
    $legacy = array(
        array('header' => 'Top', 'value' => 'Top {cf_door_number}', 'type' => 'text', 'sortable' => true, 'mobile_visible' => true, 'align' => 'left'),
        array('header' => 'Preis', 'value' => '{cf_purchase_price_formatted}', 'type' => 'text', 'sortable' => true, 'fallback' => '—'),
        array('header' => 'Status', 'value' => '{cf_status}', 'type' => 'status_dot'),
    );
    return array(
        'accordion-preset' => array(
            'columns' => $preset, 'mode' => 'accordion', 'inline_sort_enabled' => true, 'accordion_single_open' => true,
            'url_state_enabled' => true, 'url_state_key' => 'unit', 'url_state_value' => '{cf_door_number}',
            'status_handling' => 'show', 'immoadmin_buildings' => array('Presto'),
        ),
        'table-legacy-dim' => array(
            'columns' => $legacy, 'mode' => 'table', 'status_handling' => 'dim',
        ),
        'accordion-hide-scroll' => array(
            'columns' => $legacy, 'mode' => 'accordion', 'status_handling' => 'hide', 'horizontal_scroll' => true,
            'scroll_hint_enabled' => true, 'scroll_hint_label' => 'Wischen', 'empty_message' => 'Nix da',
        ),
        'empty-columns' => array(),
    );
}

function immoadmin_test_render_table($settings, $id = 'tbl777', $posts = null) {
    $GLOBALS['__query_posts'] = $posts === null ? immoadmin_test_table_fixture_posts() : $posts;
    $el = new ImmoAdmin_Units_Table(array('id' => $id, 'name' => 'immoadmin-units-table', 'settings' => $settings));
    ob_start();
    $el->render();
    $html = ob_get_clean();
    $GLOBALS['__query_posts'] = array();
    return $html;
}
