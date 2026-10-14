<?php
/**
 * Filter widgets v2.15.4 fixes — required at the end of tests/run-tests.php
 * (uses its harness: check(), section(), the WP / Bricks stubs). Kept in its
 * own file so parallel work on run-tests.php does not collide.
 */

$FD = 'ImmoAdmin_Filter_Data';
$frender = function ($el) { ob_start(); $el->render(); return ob_get_clean(); };
$fattr = function ($html, $name) { return preg_match('/ ' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null; };
$froot = function ($html) { return preg_match('/^<div [^>]*>/', $html, $m) ? $m[0] : ''; };

// Units of two projects on one site: Haus A/B (this page) + Fremd (another project).
$frows = array(
    array('building_name' => 'Haus A', 'status' => 'available', 'floor' => '0', 'floor_label' => 'EG', 'room_count' => '2', 'living_area' => '52', 'purchase_price' => '300000'),
    array('building_name' => 'Haus A', 'status' => 'available', 'floor' => '1', 'floor_label' => '1. OG', 'room_count' => '3', 'living_area' => '74', 'purchase_price' => '410000'),
    array('building_name' => 'haus b ', 'status' => 'available', 'floor' => '2', 'floor_label' => '2. OG', 'room_count' => '4', 'living_area' => '96', 'purchase_price' => '520000'),
    array('building_name' => 'Fremd', 'status' => 'available', 'floor' => '5', 'floor_label' => '5. OG', 'room_count' => '6', 'living_area' => '210', 'purchase_price' => '2000000'),
);

// filters.css split into unlayered / layered text.
$css = (string) file_get_contents(__DIR__ . '/../bricks/assets/css/filters.css');
$css_nc = preg_replace('#/\*.*?\*/#s', '', $css);
$layer_start = strpos($css_nc, '@layer bricks {');
$depth = 0;
$layer_end = null;
for ($i = $layer_start; $i < strlen($css_nc); $i++) {
    if ($css_nc[$i] === '{') { $depth++; }
    if ($css_nc[$i] === '}') { $depth--; if ($depth === 0) { $layer_end = $i; break; } }
}
$css_layered   = substr($css_nc, $layer_start, $layer_end - $layer_start + 1);
$css_unlayered = substr($css_nc, 0, $layer_start) . substr($css_nc, $layer_end + 1);
/** Declarations of the first rule whose selector equals $sel in $text. */
$css_rule = function ($text, $sel) {
    $text = preg_replace('/\s+/', ' ', $text);
    $sel  = preg_replace('/\s+/', ' ', $sel);
    $p = 0;
    while (($p = strpos($text, $sel . ' {', $p)) !== false) {
        $before = $p === 0 ? '}' : substr(rtrim(substr($text, 0, $p)), -1);
        if ($before === '}' || $before === '{' || $p === 0) {
            $open = strpos($text, '{', $p);
            return trim(substr($text, $open + 1, strpos($text, '}', $open) - $open - 1));
        }
        $p++;
    }
    return null;
};
// Specificity [ids, classes/attrs/pseudo-classes, types] incl. :not() argument, :where() = 0.
$spec3 = function ($sel) {
    $sel = preg_replace('/:where\((?:[^()]|\((?:[^()])*\))*\)/', '', $sel);
    $sel = preg_replace('/::[a-z-]+/', '', $sel);
    $sel = str_replace(':not(', ' (', $sel);
    $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $sel);
    $cls = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]|:[a-z-]+/', $sel);
    $typ = preg_match_all('/(?:^|[\s>(])[a-z]+(?=[.\[:#\s)]|$)/', $sel);
    return array($ids, $cls, $typ);
};
// Bricks 2.3.9 Assets: element CSS = "#brxe-<id>" + ' ' + selector ('&' = no space).
$bricks_out = function ($selector) {
    return substr($selector, 0, 1) === '&' ? '#brxe-x' . substr($selector, 1) : '#brxe-x ' . $selector;
};

// ================================================================ #1 / #3 active state
section('filters v2.15.4 #1: Normal / Hover / Aktiv stay independent');
$fb = new ImmoAdmin_Filter_Buttons(array('id' => 'fbx', 'settings' => array()));
$fb->set_control_groups(); $fb->set_controls();
$sel_normal = $bricks_out($fb->controls['optionBackground']['css'][0]['selector']);
$sel_hover  = $bricks_out($fb->controls['optionHoverBackground']['css'][0]['selector']);
$sel_active = $bricks_out($fb->controls['optionActiveBackground']['css'][0]['selector']);
check('Normal controls unchanged (saved settings keep their normal look)', array($sel_normal, $bricks_out($fb->controls['optionTypography']['css'][0]['selector']), $bricks_out($fb->controls['optionBorder']['css'][0]['selector'])),
    array('#brxe-x .immoadmin-filter-option', '#brxe-x .immoadmin-filter-option', '#brxe-x .immoadmin-filter-option'));
check('Hover: never a selected button, only with data-immoadmin-hover (hover devices)', $sel_hover, '#brxe-x[data-immoadmin-hover] .immoadmin-filter-option:not(.brx-option-active):hover');
check('Hover typography + border use the same selector', array($bricks_out($fb->controls['optionHoverTypography']['css'][0]['selector']), $bricks_out($fb->controls['optionHoverBorder']['css'][0]['selector'])), array($sel_hover, $sel_hover));
check('Aktiv selector unchanged', $sel_active, '#brxe-x .immoadmin-filter-option.brx-option-active');
check('specificity: Normal 1-1-0 < Aktiv 1-2-0', array($spec3($sel_normal), $spec3($sel_active)), array(array(1, 1, 0), array(1, 2, 0)));
$boost = '.brxe-immoadmin-filter-buttons.immoadmin-filter--default-active .immoadmin-filter-option.brx-option-active:not(#iaf-x)';
$boost_rule = $css_rule($css_unlayered, $boost);
check('built-in selected look is UNLAYERED (a layered rule loses to any unlayered Normal style)', $boost_rule !== null && $css_rule($css_layered, $boost) === null, true);
check('built-in selected look (1-4-0) beats designer Normal and its Bricks state variants', array($spec3($boost), $spec3($boost) > $spec3($sel_normal . ':focus')), array(array(1, 4, 0), true));
check('built-in selected look sets bg, border colour and text colour from own vars', $boost_rule,
    'background-color: var(--iaf-option-active-bg, #1f2937); border-color: var(--iaf-option-active-border, currentColor); color: var(--iaf-option-active-color, #fff);');
$fax = new ImmoAdmin_Filter_Actions(array('id' => 'fax', 'settings' => array()));
$fax->set_control_groups(); $fax->set_controls();
check('submit/reset hover unchanged (no Aktiv state there)', array($fax->controls['submitHoverBackground']['css'][0]['selector'], $fax->controls['resetHoverBorder']['css'][0]['selector']),
    array('.immoadmin-filter-submit:hover', '.immoadmin-filter-reset:hover'));
check('Aktiv section explains the built-in look', strpos($fb->controls['optionActiveSep']['description'] ?? '', 'Leer = eingebauter Aktiv-Look') === 0, true);

$FD::set_rows_for_tests($frows);
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array()));
$h_plain = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fd1', 'settings' => array('field' => 'room_count', 'optionBackground' => array('hex' => '#5a3b2e'), 'optionTypography' => array('color' => array('hex' => '#fff'))))));
check('no Aktiv style saved → root carries the built-in-look class', strpos($froot($h_plain), 'immoadmin-filter--default-active') !== false, true);
foreach (array(
    'optionActiveBackground' => array('hex' => '#fff'),
    'optionActiveTypography' => array('font-weight' => '700'),
    'optionActiveBorder' => array('width' => array('top' => '2px')),
    'optionActiveBackground:mobile_portrait' => array('hex' => '#000'),
) as $k => $v) {
    $h = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fd2', 'settings' => array('field' => 'room_count', $k => $v))));
    check("Aktiv style {$k} saved → designer look only (no built-in class)", strpos($h, 'immoadmin-filter--default-active') === false, true);
}
check('has_active_style ignores empty values, the separator, other states', array(
    ImmoAdmin_Filter_Buttons::has_active_style(array('optionActiveBackground' => '')),
    ImmoAdmin_Filter_Buttons::has_active_style(array('optionActiveSep' => 'x')),
    ImmoAdmin_Filter_Buttons::has_active_style(array('optionHoverBackground' => array('hex' => '#000'))),
    ImmoAdmin_Filter_Buttons::has_active_style(null)), array(false, false, false, false));

section('filters v2.15.4 #3: own default colours for the selected state');
check('base CSS no longer uses --bricks-color-primary (#ffd64f + white text failed contrast)', strpos($css_nc, '--bricks-color-primary') === false, true);
check('defaults: dark bg + white text (layered, overridable)', array(
    $css_rule($css_layered, ':where(.brxe-immoadmin-filter-buttons) .immoadmin-filter-option.brx-option-active'),
    strpos($css_layered, '--iaf-option-active-bg: #1f2937;') !== false && strpos($css_layered, '--iaf-option-active-color: #fff;') !== false),
    array('background-color: var(--iaf-option-active-bg); border-color: var(--iaf-option-active-border, currentColor); color: var(--iaf-option-active-color);', true));
$lum = function ($hex) {
    $c = array_map(function ($x) { $x = hexdec($x) / 255; return $x <= 0.03928 ? $x / 12.92 : pow(($x + 0.055) / 1.055, 2.4); }, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = ($lum('#ffffff') + 0.05) / ($lum('#1f2937') + 0.05);
check('default pair #fff on #1f2937 ≥ 4.5:1 (WCAG AA)', $ratio >= 4.5, true);

// ================================================================ #2 Bricks presets
section('filters v2.15.4 #2: Bricks button presets (Stil / Outline) take effect');
check('neutral bg only without a bricks-background-* preset', $css_rule($css_layered, ':where(.brxe-immoadmin-filter-buttons) .immoadmin-filter-option:where(:not([class*="bricks-background-"]))'), 'background-color: transparent;');
check('neutral border only without bg preset / outline', $css_rule($css_layered, ':where(.brxe-immoadmin-filter-buttons) .immoadmin-filter-option:where(:not([class*="bricks-background-"]):not(.outline))'), 'border: 1px solid currentColor;');
check('inherited text colour only without a bricks-color-* preset', $css_rule($css_layered, ':where(.brxe-immoadmin-filter-buttons) .immoadmin-filter-option:where(:not([class*="bricks-color-"]))'), 'color: inherit;');
$base_rule = (string) $css_rule($css_layered, ':where(.brxe-immoadmin-filter-buttons) .immoadmin-filter-option');
check('the shared base rule keeps the shape but sets no colours', array(strpos($base_rule, 'background-color:') === false, strpos($base_rule, ' color:') === false && strpos($base_rule, 'color:') !== 0,
    strpos($base_rule, 'border:') === false, strpos($base_rule, 'border-radius: 999px') !== false, strpos($base_rule, 'padding: 0.4em 1.1em') !== false), array(true, true, true, true, true));
$hp = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fd3', 'settings' => array('field' => 'room_count', 'optionStyle' => 'primary'))));
$hpo = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fd4', 'settings' => array('field' => 'room_count', 'optionStyle' => 'dark', 'optionOutline' => true))));
check('preset classes match the exclusions', array(strpos($hp, 'class="immoadmin-filter-option bricks-button bricks-background-primary"') !== false,
    strpos($hpo, 'class="immoadmin-filter-option bricks-button outline bricks-color-dark"') !== false), array(true, true));

// ================================================================ #4 range grid
section('filters v2.15.4 #4: range bounds on the step grid');
check('manual 50–155 / 10 → max up to 160', $FD::range_config(array('min' => 52, 'max' => 150), '50', '155', '10'), array('min' => 50.0, 'max' => 160.0, 'step' => 10.0));
check('manual 50–160 / 10 (live Wohnfläche) unchanged', $FD::range_config(array('min' => 52, 'max' => 150), '50', '160', '10'), array('min' => 50.0, 'max' => 160.0, 'step' => 10.0));
check('manual min off grid → down to a multiple of the step', $FD::range_config(array(), '283000', '760000', '10000'), array('min' => 280000.0, 'max' => 760000.0, 'step' => 10000.0));
check('manual min + automatic max: max = min + k·step', $FD::range_config(array('min' => 52, 'max' => 147.5), '45', '', '10'), array('min' => 40.0, 'max' => 150.0, 'step' => 10.0));
check('decimal step without float noise', $FD::range_config(array(), '0.1', '0.35', '0.1'), array('min' => 0.1, 'max' => 0.4, 'step' => 0.1));
check('already on grid (0–0.3 / 0.1) stays', $FD::range_config(array(), '0', '0.3', '0.1'), array('min' => 0.0, 'max' => 0.3, 'step' => 0.1));
foreach (array(array('5', '155', '7'), array('50', '155', '10'), array('283000', '1234567', '25000'), array('0', '1', '0.3')) as $c) {
    $rc = $FD::range_config(array(), $c[0], $c[1], $c[2]);
    $k = ($rc['max'] - $rc['min']) / $rc['step'];
    check('grid invariant ' . implode('/', $c) . ': (max−min)/step integer, covers the manual range', array(abs($k - round($k)) < 1e-6, $rc['min'] <= (float) $c[0], $rc['max'] >= (float) $c[1]), array(true, true, true));
}
$hr = $frender(new ImmoAdmin_Filter_Range(array('id' => 'fr4', 'settings' => array('field' => 'living_area', 'min' => '50', 'max' => '155', 'step' => '10'))));
check('rendered inputs carry the aligned max + value', array(strpos($hr, 'min="50" max="160" step="10" value="160"') !== false, strpos($hr, '>160 m²<') !== false), array(true, true));

// ================================================================ #5 follow default + width
section('filters v2.15.4 #5: "mitlaufend" for elements without a stored Wert-Position');
$hl = $frender(new ImmoAdmin_Filter_Range(array('id' => 'fr5', 'settings' => array('field' => 'living_area'))));
$hx = $frender(new ImmoAdmin_Filter_Range(array('id' => 'fr5', 'settings' => array('field' => 'living_area', 'value_position' => 'fixed'))));
check('saved element without value_position → follow markup', strpos($hl, '<div class="value-wrap" data-value-position="follow" aria-hidden="true">') !== false, true);
check('explicit fixed → original left/right row', array(strpos($hx, '<div class="value-wrap" aria-hidden="true">') !== false, strpos($hx, 'class="merged"') === false), array(true, true));
$root_rule = (string) $css_rule($css_layered, ':where(.brxe-immoadmin-filter-range)');
check('range root never wider than its column', array(strpos($root_rule, 'min-width: 0;') !== false, strpos($root_rule, 'max-width: 100%;') !== false), array(true, true));
check('moving labels cannot widen the column (contain: inline-size, unlayered)', $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .value-wrap[data-value-position="follow"]'), 'contain: inline-size;');

// ================================================================ #6 functional slider CSS
section('filters v2.15.4 #6: slider mechanics outside @layer, look inside');
foreach (array('pointer-events', '-webkit-appearance', 'position: absolute', 'inset: 0', 'z-index') as $needle) {
    check("layer holds no '{$needle}'", strpos($css_layered, $needle) === false, true);
}
$inp = (string) $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]');
check('inputs: stacked, transparent, no pointer (unlayered)', array(strpos($inp, 'position: absolute;') !== false, strpos($inp, 'pointer-events: none;') !== false,
    strpos($inp, 'appearance: none;') !== false, strpos($inp, 'background: none;') !== false), array(true, true, true, true));
check('thumbs take the pointer (unlayered, both engines)', array(
    strpos((string) $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]::-webkit-slider-thumb'), 'pointer-events: auto;') !== false,
    strpos((string) $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]::-moz-range-thumb'), 'pointer-events: auto;') !== false), array(true, true));
check('native track transparent (unlayered, both engines)', array(
    strpos((string) $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]::-webkit-slider-runnable-track'), 'background: none;') !== false,
    strpos((string) $css_rule($css_unlayered, '.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]::-moz-range-track'), 'background: none;') !== false), array(true, true));
check('theme "input[type=range]" (0-1-1) cannot beat the mechanics (0-3-1)', array($spec3('.brxe-immoadmin-filter-range .double-slider-wrap input[type="range"]'), $spec3('input[type="range"]')), array(array(0, 3, 1), array(0, 1, 1)));
check('visuals stay layered: bar colours, thumb look', array(
    $css_rule($css_layered, ':where(.brxe-immoadmin-filter-range) .slider-track'), $css_rule($css_layered, ':where(.brxe-immoadmin-filter-range) .slider-base'),
    strpos($css_unlayered, 'var(--iaf-thumb-bg)') === false),
    array('background-color: var(--iaf-bar-active-color);', 'background-color: var(--iaf-bar-color);', true));
check('default base bar ≠ active bar (tint of currentColor vs currentColor)', array(strpos($root_rule, '--iaf-bar-color: color-mix(in srgb, currentColor 25%, transparent);') !== false, strpos($root_rule, '--iaf-bar-active-color: currentColor;') !== false), array(true, true));
$frx = new ImmoAdmin_Filter_Range(array('id' => 'fr6', 'settings' => array()));
$frx->set_control_groups(); $frx->set_controls();
check('"Balken: Farbe (gewählter Bereich)" writes the var the track reads (root, unlayered by Bricks)', $frx->controls['sliderBarColorActive']['css'], array(array('property' => '--iaf-bar-active-color')));

// ================================================================ #9 options per target tables
section('filters v2.15.4 #9: options / bounds only from the targeted tables\' buildings');
$idx9 = array('tables' => array(
    't1' => array('id' => 't1', 'group' => '', 'buildings' => array('Haus A')),
    't2' => array('id' => 't2', 'group' => '', 'buildings' => array('Haus B')),
), 'filters' => array());
check('scope: all mode → union of all tables', $FD::building_scope($idx9, array(), ''), array('Haus A', 'Haus B'));
check('scope: one target', $FD::building_scope($idx9, array('t2'), ''), array('Haus B'));
check('scope: component instance id of a target', $FD::building_scope(array('tables' => array('t1-abc' => array('id' => 't1-abc', 'group' => '', 'buildings' => array('Haus A')))), array('t1'), ''), array('Haus A'));
$idx9u = $idx9;
$idx9u['tables']['t3'] = array('id' => 't3', 'group' => '', 'buildings' => array());
check('scope: an unrestricted table in all mode → no restriction', $FD::building_scope($idx9u, array(), ''), null);
check('scope: targets avoid the unrestricted table → restricted', $FD::building_scope($idx9u, array('t1', 't2'), ''), array('Haus A', 'Haus B'));
check('scope: target not on the page / no tables → no restriction', array($FD::building_scope($idx9, array('zzz'), ''), $FD::building_scope(array('tables' => array()), array(), '')), array(null, null));
check('scope: legacy group filter → tables of its group', $FD::building_scope(array('tables' => array('t1' => array('id' => 't1', 'group' => 'g', 'buildings' => array('Haus A')), 't2' => array('id' => 't2', 'group' => '', 'buildings' => array()))), array(), 'g'), array('Haus A'));
check('scope: index entries without "buildings" → no restriction', $FD::building_scope(array('tables' => array('t9' => array('id' => 't9', 'group' => ''))), array(), ''), null);
check('rows: case-/space-insensitive match (like MySQL IN)', array_column($FD::filter_rows_by_buildings($frows, array('Haus A', 'Haus B')), 'living_area'), array('52', '74', '96'));
check('rows: null = all', count($FD::filter_rows_by_buildings($frows, null)), 4);
$idx_scan = $FD::index_elements(array(array(
    array('id' => 'ta', 'name' => 'immoadmin-units-table', 'settings' => array('immoadmin_buildings' => array('Haus A', ' ', 'Haus A'))),
    array('id' => 'tb', 'name' => 'immoadmin-units-table', 'settings' => array('immoadmin_buildings' => 'Haus B')),
    array('id' => 'tc', 'name' => 'immoadmin-units-table', 'settings' => array()),
)));
check('page scan records each table\'s Gebäude (sanitized, unique; string tolerated)', array($idx_scan['tables']['ta']['buildings'], $idx_scan['tables']['tb']['buildings'], $idx_scan['tables']['tc']['buildings']), array(array('Haus A'), array('Haus B'), array()));

$FD::set_page_index_for_tests($idx9);
$h9 = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb9', 'settings' => array('field' => 'room_count'))));
check('Zimmer buttons: only units of Haus A + B (no "6" from the other project)', (preg_match_all('/data-value="([^"]*)"/', $h9, $m9) ? $m9[1] : null), array('2', '3', '4'));
$h9t = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb9t', 'settings' => array('field' => 'building_name', 'filter_targets' => array('t1')))));
check('Haus buttons of a filter targeting t1 → only Haus A', (preg_match_all('/data-value="([^"]*)"/', $h9t, $m9t) ? $m9t[1] : null), array('Haus A'));
$h9r = $frender(new ImmoAdmin_Filter_Range(array('id' => 'fr9', 'settings' => array('field' => 'living_area'))));
check('Wohnfläche bounds 52–96 (not 210 of the other project)', strpos($h9r, 'min="52" max="96"') !== false, true);
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array()));
$h9n = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb9n', 'settings' => array('field' => 'room_count'))));
check('no tables known → all units (as before)', (preg_match_all('/data-value="([^"]*)"/', $h9n, $m9n) ? $m9n[1] : null), array('2', '3', '4', '6'));
$GLOBALS['__is_builder'] = true;
$FD::reset_registered_keys();
$h9b = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb9b', 'settings' => array('field' => 'room_count'))));
check('builder without Bricks data → all units, no error', (preg_match_all('/data-value="([^"]*)"/', $h9b, $m9b) ? $m9b[1] : null), array('2', '3', '4', '6'));
$GLOBALS['__is_builder'] = false;
$FD::reset_registered_keys();

// ================================================================ #10 a11y
section('filters v2.15.4 #10: accessible structure');
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array()));
$h10 = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb10', 'settings' => array('field' => 'room_count', 'label' => 'Zimmer'))));
$r10 = $froot($h10);
check('group role + label on the root, <ul> keeps list semantics', array($fattr($r10, 'role'), $fattr($r10, 'aria-labelledby'), strpos($h10, '<ul class="immoadmin-filter-options">') !== false, substr_count($h10, 'role="group"')),
    array('group', 'iaf-label-fb10', true, 1));
check('label element still referenced', strpos($h10, '<span class="immoadmin-filter-label" id="iaf-label-fb10">Zimmer</span>') !== false, true);
$h10b = $frender(new ImmoAdmin_Filter_Buttons(array('id' => 'fb10b', 'settings' => array('field' => 'floor'))));
check('no label → aria-label on the root', $fattr($froot($h10b), 'aria-label'), 'Geschoss');
check('every option is a toggle button', substr_count($h10, '<button type="button"') === substr_count($h10, 'aria-pressed="false"'), true);
check('classes / nesting for existing styles unchanged', preg_match('#<ul class="immoadmin-filter-options"><li class="immoadmin-filter-item"><button type="button" class="immoadmin-filter-option"#', $h10), 1);
$status_rule = (string) $css_rule($css_unlayered, '.immoadmin-filter-status');
check('status region visually hidden, not display:none (unlayered)', array(strpos($status_rule, 'position: absolute !important;') !== false, strpos($status_rule, 'display') === false, strpos($status_rule, 'clip: rect(0, 0, 0, 0) !important;') !== false), array(true, true, true));

// ================================================================ #11 smaller fixes
section('filters v2.15.4 #11: Nachkommastellen with "Kurz", icon controls, cleanup');
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/format-k-decimals-cases.json'), true) as $c) {
    check('format ' . json_encode($c['value']) . ' ' . json_encode($c['fmt']), $FD::format_value($c['value'], $c['fmt']), $c['expected']);
}
check('config: explicit decimals + k → kDecimals', ImmoAdmin_Filter_Range::config_for(array('field' => 'purchase_price', 'decimals' => '0'), $frows)['format'], array('mode' => 'k', 'decimals' => 0, 'prefix' => '', 'suffix' => '', 'kDecimals' => 0));
check('config: no decimals → no kDecimals (automatic as before)', array_key_exists('kDecimals', ImmoAdmin_Filter_Range::config_for(array('field' => 'purchase_price'), $frows)['format']), false);
check('config: decimals with "thousands" → no kDecimals key', array_key_exists('kDecimals', ImmoAdmin_Filter_Range::config_for(array('field' => 'living_area', 'decimals' => '1'), $frows)['format']), false);
$h11 = $frender(new ImmoAdmin_Filter_Range(array('id' => 'fr11', 'settings' => array('field' => 'purchase_price', 'decimals' => '1'))));
check('rendered k labels honour 1 decimal', array(strpos($h11, '>300,0k<') !== false, strpos($h11, '>2,0 Mio.<') !== false), array(true, true));
check('decimals control explains "Kurz"', strpos($frx->controls['decimals']['description'] ?? '', 'Kurz') !== false, true);
check('icon position / size / colour / gap shown for SVG icons too (whole icon setting, like Bricks\' Button)', array(
    $fax->controls['submitIconPosition']['required'], $fax->controls['resetIconPosition']['required'],
    $fax->controls['submitIconSize']['required'], $fax->controls['resetIconColor']['required'], $fax->controls['submitIconGap']['required']),
    array(array('submitIcon', '!=', ''), array('resetIcon', '!=', ''), array('submitIcon', '!=', ''), array('resetIcon', '!=', ''), array('submitIcon', '!=', '')));
check('unused data_hint_html() removed', method_exists('ImmoAdmin_Filter_Element', 'data_hint_html'), false);

$FD::reset_registered_keys();
$FD::set_rows_for_tests(null);
