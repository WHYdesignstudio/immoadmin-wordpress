<?php
/**
 * Filter-Bereich render cases shared by tests/run-tests.php (v2.15.1).
 *
 * filter-range-baseline.json holds the output of these cases rendered with
 * v2.15.0 — before "Wert-Position" existed. Every case is a SAVED element
 * without the new `value_position` setting (Bricks only stores what the user
 * set), so the test demands byte-identical HTML: existing elements must keep
 * their left/right values without a single changed byte.
 *
 * Regenerate ONLY on purpose: php tests/run-tests.php --write-range-baseline
 */

function immoadmin_test_range_cases() {
    return array(
        'area-default'      => array('field' => 'living_area'),
        'price-label-group' => array('filter_group' => 'wohnungen', 'field' => 'purchase_price', 'label' => 'Preis'),
        'von-bis-prefix'    => array('field' => 'living_area', 'label' => 'Wohnfläche', 'labelMin' => 'von', 'labelMax' => 'bis',
            'prefix' => 'ca. ', 'suffix' => ' qm', 'decimals' => '1', 'value_format' => 'plain'),
        'price-targets-k'   => array('field' => 'purchase_price', 'filter_targets' => array('tbl1'), 'value_format' => 'k',
            'labelMax' => 'max.', 'empty_handling' => 'hide', 'step' => '5000'),
    );
}
