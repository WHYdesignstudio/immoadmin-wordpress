<?php
/**
 * Units-table v2.15.4 fixes — required from tests/run-tests.php (needs its
 * harness + the helpers of the units-table sections: $attr, $styles, $block,
 * $matches, $node, $baseline). Kept in its own file so parallel work on
 * run-tests.php does not collide.
 */

// ================================================================ v2.15.4 units-table fixes
$UT = 'ImmoAdmin_Units_Table';
$u4 = new ImmoAdmin_Units_Table(array('id' => 'u4', 'settings' => array()));
$u4->set_control_groups();
$u4->set_controls();
$u4c = $u4->controls;
// Bricks 2.3.9 Assets: a 'selector' containing ', ' gets the element root
// prefixed to every part (str_replace(', ', ", $css_selector ", …)).
$bricks_sel = function ($control, $i = 0) {
    $sel = $control['css'][$i]['selector'];
    $root = '.brxe-x';
    if (strpos($sel, ', ')) { $sel = str_replace(', ', ", $root ", $sel); }
    return $root . ' ' . $sel;
};
// Specificity (classes/attrs/pseudo-classes; :where() = 0) of one selector.
$spec = function ($sel) {
    $sel = preg_replace('/:where\((?:[^()]|\((?:[^()])*\))*\)/', '', $sel);
    return preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]|:(?!:)[a-z-]+/', $sel);
};

section('units-table v2.15.4: style controls');
check('#7 row border: only the bottom side (directions key = value for PHP + builder)', array($u4c['row_border']['directions'], array_values($u4c['row_border']['directions']), array_keys($u4c['row_border']['directions'])), array(array('bottom' => 'bottom'), array('bottom'), array('bottom')));
check('#1 Zell-Padding reaches cells AND header cells', $bricks_sel($u4c['row_padding']), '.brxe-x .immoadmin-table-cell, .brxe-x :where(.immoadmin-table-cell-header)');
$rp = explode(', ', $bricks_sel($u4c['row_padding']));
check('#1 Kopfzeile-Padding still beats Zell-Padding on the header (specificity)', $spec($bricks_sel($u4c['header_padding'])) > $spec($rp[1]), true);
check('#8 zebra via .is-even on the row (no nth-of-type)', $u4c['row_alternate_background']['css'][0]['selector'], '.immoadmin-table-row.is-even > .immoadmin-table-cell');
$hover = explode(', ', $bricks_sel($u4c['row_hover_background']));
check('#8 hover: every row kind + open panel keeps its row lit', $hover, array('.brxe-x .immoadmin-table-row:hover > .immoadmin-table-cell', '.brxe-x .accordion-item:hover > :where(.immoadmin-table-row) > .immoadmin-table-cell'));
$open = $spec($bricks_sel($u4c['row_open_background']));
check('#8 order kept: zebra = hover = open weight (later control wins: hover > zebra, open > hover)', array($spec($bricks_sel($u4c['row_alternate_background'])), $spec($hover[0]), $spec($hover[1]), $open), array(4, 4, 4, 4));
$keys = array_keys($u4c);
check('#8 control order zebra < hover < open', array_search('row_alternate_background', $keys) < array_search('row_hover_background', $keys) && array_search('row_hover_background', $keys) < array_search('row_open_background', $keys), true);
check('#9 Spalten-Abstand: same key, now a variable on the root (no grid column-gap)', $u4c['table_column_gap']['css'], array(array('property' => '--iat-col-gap', 'selector' => '')));
check('#10 Border-Radius: overflow clip (no scroll container)', $u4c['table_border_radius']['css'][1], array('property' => 'overflow', 'selector' => '.immoadmin-table', 'value' => 'clip'));
$f = $u4c['columns']['fields'];
check('#14 Link-Ziel only for link + icon columns', $f['link_target']['required'], array('type', '=', array('link', 'icon')));
check('#1 new column fields: Mindestbreite + Innenabstand, text, no default', array($f['min_width']['type'], $f['cell_padding']['type'], array_key_exists('default', $f['min_width']), array_key_exists('default', $f['cell_padding'])), array('text', 'text', false, false));
check('#1 Kompakt info explains scroll mode', strpos($f['compact']['info'], 'Horizontal scrollen') !== false, true);

section('units-table v2.15.4: CSS file');
$c4 = preg_replace('#/\*.*?\*/#s', '', file_get_contents(__DIR__ . '/../bricks/assets/css/units-table.css')); // rules only, no comments
check('#2 no word-break: break-word (min-content stays a whole word)', preg_match('/word-break:\s*break-word/', $c4), 0);
check('#2 default = old breaking (anywhere): no overflow before / without JS', strpos($block($c4, ':where(.brxe-immoadmin-units-table) .immoadmin-table-cell'), 'overflow-wrap: anywhere;') !== false, true);
check('#2 whole words only under .is-roomy (set by JS when everything fits)', strpos($block($c4, ':where(.brxe-immoadmin-units-table) .immoadmin-table.is-roomy > .accordion-item > .immoadmin-table-row > .immoadmin-table-cell'), 'overflow-wrap: break-word;') !== false, true);
check('#2 no .is-tight left', strpos($c4, 'is-tight'), false);
check('safelist hint for purge tools in the CSS header', strpos(file_get_contents(__DIR__ . '/../bricks/assets/css/units-table.css'), '.is-even .is-roomy .is-highlighted .brx-open .immoadmin-row-toggle') !== false, true);
check('#3 builder preview: first unit = item after the header (no :first-of-type)', array(strpos($c4, 'accordion-item:first-of-type'), strpos($c4, '.immoadmin-table-header + .accordion-item > .immoadmin-accordion-panel') !== false, strpos($c4, '[data-builder-window="iframe"] .brxe-immoadmin-units-table') !== false), array(false, true, true));
check('#4 row structure via child combinators (nested accordions untouched)', array(strpos($c4, '.immoadmin-table > .accordion-item {') !== false, preg_match('/\) \.accordion-item \{/', $c4)), array(true, 0));
check('#5 collapsed panel hidden from tab order / AT', strpos($c4, 'visibility: hidden;') !== false && strpos($c4, 'visibility: visible;') !== false, true);
check('#9 no column-gap anywhere', preg_match('/column-gap/', $c4), 0);
check('#12 14px is a root fallback, not on the grid', array(strpos($c4, ".immoadmin-table {\n            font-size: 14px"), strpos($c4, ":where(.brxe-immoadmin-units-table) {\n            font-size: 14px;") !== false), array(false, true));
check('#1 mobile padding via the variable (header + cells consistent)', array(strpos($c4, '--iat-row-padding: 8px 10px;') !== false, preg_match('/(?<![-\w])padding:\s*8px 10px/', $c4)), array(true, 0));

section('units-table v2.15.4: tracks + per-column min width / padding');
check('#1/#11 tracks', array($UT::column_track(array(), false), $UT::column_track(array('compact' => true), false), $UT::column_track(array(), true), $UT::column_track(array('compact' => true), true), $UT::column_track(array('min_width' => '80px'), false)),
    array('minmax(min-content, 1fr)', 'minmax(min-content, max-content)', 'auto', 'max-content', 'minmax(min-content, 1fr)'));
$cmp = immoadmin_test_render_table(array_merge(immoadmin_test_table_cases()['accordion-preset'], array('columns' => array_merge(immoadmin_test_table_cases()['accordion-preset']['columns'], array(array('header' => 'X', 'value' => 'x', 'compact' => true, 'hide_on_breakpoints' => array('mobile_portrait')))))));
check('v2.15.5 compact shrinkable in grid, mobile grid and band templates (no bare max-content left)', array(
    preg_match('/--iat-grid-cols:[^;]*(?<!, )max-content(?!\))/', (string) $attr($cmp, 'style')),
    preg_match('/--iat-mobile-grid-cols:[^;]*(?<!, )max-content(?!\))/', (string) $attr($cmp, 'style')),
    preg_match('/grid-template-columns:[^}]*(?<!, )max-content(?!\))/', implode('', $styles($cmp))),
    strpos(implode('', $styles($cmp)), 'minmax(min-content, max-content)') !== false), array(0, 0, 0, true));
foreach (array('80px' => '80px', ' 1.5REM ' => '1.5rem', '.5em' => '.5em', '0' => '0', '12ch' => '12ch', '10vw' => '10vw',
    '80' => '', '10%' => '', '-5px' => '', 'calc(1px + 2px)' => '', 'var(--x)' => '', '{cf_x}' => '', '1px;}body{color:red' => '', '1e3px' => '', '12345px' => '', '' => '') as $in => $out) {
    check('#1 length ' . var_export((string) $in, true) . ' → ' . var_export($out, true), $UT::sanitize_css_length($in), $out);
}
check('#1 length: non-scalar → ""', $UT::sanitize_css_length(array('80px')), '');
foreach (array('8px 16px' => '8px 16px', " 4px\t8px  0 1rem " => '4px 8px 0 1rem', '10px' => '10px', '1px 2px 3px 4px 5px' => '', '8px x' => '', '8px; color:red' => '', '' => '') as $in => $out) {
    check('#1 padding ' . var_export((string) $in, true) . ' → ' . var_export($out, true), $UT::sanitize_css_padding($in), $out);
}
check('#1 column style from settings (invalid parts dropped)', array($UT::column_style_from_settings(array('min_width' => '80px', 'cell_padding' => '8px 16px')), $UT::column_style_from_settings(array('min_width' => '80', 'cell_padding' => 'x')), $UT::column_style_from_settings(array())),
    array(array('min-width' => '80px', 'padding' => '8px 16px'), array(), array()));
$csel = function ($scope, $i) {
    $out = array();
    foreach (array("{$scope} > .immoadmin-table", "{$scope} > .immoadmin-table-scroll > .immoadmin-table") as $t) {
        $out[] = "{$t} > .immoadmin-table-header > .immoadmin-table-cell-header[data-col-index=\"{$i}\"]";
        $out[] = "{$t} > .immoadmin-table-row > .immoadmin-table-cell[data-col-index=\"{$i}\"]";
        $out[] = "{$t} > .accordion-item > .immoadmin-table-row > .immoadmin-table-cell[data-col-index=\"{$i}\"]";
    }
    return implode(',', $out);
};
check('#1 per-column CSS: header + cells, child-scoped, one rule per column', $UT::column_style_css('.brxe-x', array(0 => array('min-width' => '80px'), 3 => array('padding' => '8px 16px', 'min-width' => '4rem'))),
    $csel('.brxe-x', 0) . '{min-width:80px}' . "\n" . $csel('.brxe-x', 3) . '{min-width:4rem;padding:8px 16px}');
check('#1 per-column CSS re-validates (nothing unsafe reaches <style>)', $UT::column_style_css('.brxe-x', array(1 => array('padding' => '1px}body{x', 'min-width' => 'expression(1)'))), '');
$col_sel = explode(',', $csel('.brxe-x', 1));
$own4 = array(
    array($node('brxe-x brxe-immoadmin-units-table'), $node('immoadmin-table'), $node('immoadmin-table-header'), $node('immoadmin-table-cell-header', array('data-col-index' => '1'))),
    array($node('brxe-x brxe-immoadmin-units-table'), $node('immoadmin-table'), $node('accordion-item'), $node('immoadmin-table-row accordion-title-wrapper'), $node('immoadmin-table-cell', array('data-col-index' => '1'))),
    array($node('brxe-x brxe-immoadmin-units-table'), $node('immoadmin-table-scroll'), $node('immoadmin-table'), $node('immoadmin-table-row'), $node('immoadmin-table-cell', array('data-col-index' => '1'))),
);
check('#1 per-column CSS hits own header / accordion row / scroll row', array_map(function ($p) use ($col_sel, $matches) { foreach ($col_sel as $s) { if ($matches($s, $p)) { return true; } } return false; }, $own4), array(true, true, true));
$nested4 = array($node('brxe-x brxe-immoadmin-units-table'), $node('immoadmin-table'), $node('accordion-item'), $node('immoadmin-accordion-panel'), $node('immoadmin-accordion-panel__cell'), $node('brxe-y brxe-immoadmin-units-table'), $node('immoadmin-table'), $node('immoadmin-table-row'), $node('immoadmin-table-cell', array('data-col-index' => '1')));
check('#1 per-column CSS never reaches a table nested in the panel', count(array_filter($col_sel, function ($s) use ($matches, $nested4) { for ($i = 0; $i < count($nested4); $i++) { if ($matches($s, array_slice($nested4, $i))) { return true; } } return false; })), 0);

$base4 = immoadmin_test_table_cases();
$pc = $base4['table-legacy-dim'];
$pc['columns'][0]['min_width'] = '6rem';
$pc['columns'][2]['cell_padding'] = '4px 12px';
$pch = immoadmin_test_render_table($pc);
check('#1 render: one <style> with the column rules', $styles($pch), array($csel('.brxe-tbl777', 0) . '{min-width:6rem}' . "\n" . $csel('.brxe-tbl777', 2) . '{padding:4px 12px}'));
check('#1 render: apart from the <style> byte-identical (tracks untouched)', preg_replace('#<style>.*?</style>#s', '', $pch), $baseline['table-legacy-dim']);
$bad4 = $base4['table-legacy-dim'];
$bad4['columns'][0]['min_width'] = '80';
$bad4['columns'][1]['cell_padding'] = '{cf_x}';
check('#1 render: invalid values → no <style>, byte-identical', immoadmin_test_render_table($bad4), $baseline['table-legacy-dim']);
$both = $base4['accordion-hide-scroll'];
$both['columns'][2]['hide_on_breakpoints'] = array('mobile_portrait');
$both['columns'][1]['cell_padding'] = '2px';
$bs = $styles(immoadmin_test_render_table($both, 'tb4'));
check('#1 render: breakpoint CSS + column CSS share one <style>', array(count($bs), strpos($bs[0] ?? '', '@media') === 0, strpos($bs[0] ?? '', '{padding:2px}') !== false), array(1, true, true));

section('units-table v2.15.4: markup');
$h4 = immoadmin_test_render_table($base4['accordion-preset']);
check('#14 root inline style ends with ";"', substr((string) $attr($h4, 'style'), -1), ';');
check('#11 scroll mode: non-compact tracks auto, compact max-content', $attr(immoadmin_test_render_table(array_merge($base4['accordion-preset'], array('horizontal_scroll' => true))), 'style') !== null
    && strpos($attr(immoadmin_test_render_table(array_merge($base4['accordion-preset'], array('horizontal_scroll' => true))), 'style'), '--iat-grid-cols: auto auto auto auto auto auto auto auto auto max-content max-content;') === 0, true);
check('#14 rows keep .brxe-{id} (Bricks load-more query trail)', strpos($h4, 'class="immoadmin-table-row brxe-tbl777 ') !== false, true);
check('#5 openable rows: no tabindex / aria-expanded on the display:contents row', preg_match('/class="immoadmin-table-row[^"]*accordion-title-wrapper"[^>]*(tabindex|aria-expanded|aria-controls)/', $h4), 0);
preg_match_all('/<button type="button" class="immoadmin-row-toggle" aria-expanded="false" aria-controls="([^"]+)" aria-label="Details zu (\d+)">Details zu \d+<\/button>/', $h4, $bm);
check('#5 toggle named after the row ("Details zu <TOP>")', $bm[2], array('1', '4'));
check('#5 toggle button per openable unit (available 701 + 704 only)', $bm[1], array('immoadmin-panel-tbl777-701', 'immoadmin-panel-tbl777-704'));
check('#5 aria-controls points at an existing panel', array_map(function ($id) use ($h4) { return strpos($h4, 'class="immoadmin-accordion-panel" role="row" id="' . $id . '"') !== false; }, $bm[1]), array(true, true));
check('#5 preset: toggle sits in the arrow column', preg_match_all('/data-col-index="10" data-sort-value=""><button type="button" class="immoadmin-row-toggle"/', $h4), 2);
check('#5 restricted rows: no toggle', preg_match('/data-unit-id="702"[^>]*>(?:(?!data-unit-id).)*immoadmin-row-toggle/s', $h4), 0);
check('#5 panel: row > cell (valid inside the rowgroup), no role=region', array(substr_count($h4, '<div class="immoadmin-accordion-panel__cell" role="cell">'), strpos($h4, 'role="region"')), array(2, false));
check('#5 toggle column: arrow column, else first, none without columns', array($UT::toggle_column_index($base4['accordion-preset']['columns']), $UT::toggle_column_index($base4['table-legacy-dim']['columns']), $UT::toggle_column_index(array())), array(10, 0, null));
$hc = $base4['accordion-preset']['columns'];
$hc[10]['hide_on_breakpoints'] = array('mobile_portrait');
check('#5 arrow column hideable → first never-hidden column', $UT::toggle_column_index($hc), 0);
$hc[0]['mobile_visible'] = false;
check('#5 … skipping hideable ones', $UT::toggle_column_index($hc), 2);
check('#5 every column hideable → arrow column (JS moves the button to a visible cell)', $UT::toggle_column_index(array(array('mobile_visible' => false), array('type' => 'icon', 'accordion_toggle' => true, 'hide_on_breakpoints' => array('x')))), 1);
check('#5 label: first cell with text, dash/empty skipped, escaped text decoded', array(
    $UT::toggle_label(array('<div><i class="x"></i></div>', '<div><span class="immoadmin-cell-empty">—</span></div>', '<div>Top&nbsp;7 &amp; Co</div>')),
    $UT::toggle_label(array('<div></div>'))), array("Details zu Top 7 & Co", "Details"));
$lh = immoadmin_test_render_table($base4['accordion-hide-scroll']);
check('#5 legacy columns: toggle at the start of the first cell', strpos($lh, 'data-sort-value="Top 1"><button type="button" class="immoadmin-row-toggle" aria-expanded="false" aria-controls="immoadmin-panel-tbl777-701" aria-label="Details zu Top 1">Details zu Top 1</button>Top 1</div>') !== false, true);
check('#5 status dot is an image with its label', substr_count(immoadmin_test_render_table($base4['table-legacy-dim']), 'class="immoadmin-status-dot is-available" role="img" aria-label="available"'), 2);
check('#6 URL state on: data attrs for JS', array($attr($h4, 'data-url-state'), $attr($h4, 'data-url-key')), array('1', 'unit'));
$noUrl = immoadmin_test_render_table(array_merge($base4['accordion-preset'], array('url_state_enabled' => false)));
check('#6 URL state off: no data-url-state → JS never opens ?unit=', array($attr($noUrl, 'data-url-state'), $attr($noUrl, 'data-url-key')), array(null, null));
check('#6 URL state off: rows carry an empty data-url-value', preg_match('/data-url-value="[^"]+"/', $noUrl), 0);
if (!function_exists('bricks_is_builder_call')) {
    function bricks_is_builder_call() { return !empty($GLOBALS['__builder_call']); }
}
$GLOBALS['__builder_call'] = true;
$forged = immoadmin_test_render_table($base4['table-legacy-dim']);
$GLOBALS['__can_edit'] = true;
$bh = immoadmin_test_render_table($base4['table-legacy-dim']);
$GLOBALS['__builder_call'] = false;
$GLOBALS['__can_edit'] = false;
check('#3 builder render by an editor: data-builder="1" on the root', $attr($bh, 'data-builder'), '1');
check('#3 builder call WITHOUT capability (forged "bricks=run" Referer): no data-builder, byte-identical', $forged, $baseline['table-legacy-dim']);
check('#3 frontend: no data-builder', $attr(immoadmin_test_render_table($base4['table-legacy-dim']), 'data-builder'), null);

section('units-table v2.15.4: detail preset — slider XOR single plan');
$d4 = $UT::default_detail();
$plans = $d4['children'][1]['children'];
check('#13 slider: only when {cf_floor_plan_2} is set', $plans[0]['settings']['_conditions'], array(array(array('id' => 'iaplan', 'key' => 'dynamic_data', 'dynamic_data' => '{cf_floor_plan_2}', 'compare' => 'empty_not'))));
check('#13 single image: plan 1 set AND plan 2 empty (one set = AND)', $plans[1]['settings']['_conditions'], array(array(
    array('id' => 'iaplm1', 'key' => 'dynamic_data', 'dynamic_data' => '{cf_floor_plan_1}', 'compare' => 'empty_not'),
    array('id' => 'iaplm2', 'key' => 'dynamic_data', 'dynamic_data' => '{cf_floor_plan_2}', 'compare' => 'empty'),
)));
// Same evaluation as Bricks\Conditions::check() (sets OR, conditions AND).
$eval = function ($conds, $meta) {
    foreach ($conds as $set) {
        $ok = true;
        foreach ($set as $c) {
            $v = (string) preg_replace_callback('/\{cf_([a-z0-9_]+)\}/', function ($m) use ($meta) { return $meta[$m[1]] ?? ''; }, $c['dynamic_data']);
            $ok = $ok && ($c['compare'] === 'empty' ? $v === '' : $v !== '');
        }
        if ($ok) { return true; }
    }
    return false;
};
$cases4 = array('no plan' => array(), 'one plan' => array('floor_plan_1' => 'a.png'), 'two plans' => array('floor_plan_1' => 'a.png', 'floor_plan_2' => 'b.png'));
check('#13 exactly one of slider / image renders (none without plan)', array_map(function ($meta) use ($eval, $plans) {
    return ($eval($plans[0]['settings']['_conditions'], $meta) ? 'slider' : '') . ($eval($plans[1]['settings']['_conditions'], $meta) ? 'image' : '');
}, $cases4), array('no plan' => '', 'one plan' => 'image', 'two plans' => 'slider'));

