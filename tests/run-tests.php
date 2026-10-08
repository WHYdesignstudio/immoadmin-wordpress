<?php
/**
 * Lightweight test runner (no PHPUnit / no WordPress needed).
 *
 *   php tests/run-tests.php
 *
 * Stubs the handful of WP functions the tested code touches and exercises:
 * - ImmoAdmin_Unit_Fields (pure helpers)
 * - ImmoAdmin_Post_Type::get_meta_fields() registration of the new fields
 * - ImmoAdmin_Sync::sync_unit() end-to-end against an in-memory meta store,
 *   incl. null handling and the "hash only after all media succeeded" rule
 *
 * Exits non-zero on any failure. CLI only.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', '/dev/null'); // silence ImmoAdmin's own error_log() calls
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('ABSPATH', __DIR__ . '/');
$tmp_media = sys_get_temp_dir() . '/immoadmin-tests-' . getmypid() . '/media/';
@mkdir($tmp_media, 0777, true);
define('IMMOADMIN_DATA_DIR', dirname($tmp_media) . '/');
define('IMMOADMIN_MEDIA_DIR', $tmp_media);

// ---------------------------------------------------------------- WP stubs
class WP_Error {
    private $msg;
    public function __construct($code = '', $msg = '') { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}

$GLOBALS['__meta']    = array();
$GLOBALS['__next_id'] = 100;
$GLOBALS['__remote_calls'] = 0;

function is_wp_error($x) { return $x instanceof WP_Error; }
function current_time($t) { return '2026-01-01 00:00:00'; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_kses_post($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }
function sanitize_file_name($s) { return preg_replace('/[^A-Za-z0-9._-]/', '', (string) $s); }
function content_url($p = '') { return 'https://wp.test/wp-content' . $p; }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true); }
function wp_remote_get($url, $args = array()) { $GLOBALS['__remote_calls']++; return new WP_Error('http', 'offline in tests'); }
function wp_insert_post($data, $err = false) { return $GLOBALS['__next_id']++; }
function wp_update_post($data, $err = false) { return (int) $data['ID']; }
function get_post_meta($id, $key = '', $single = true) {
    if ($key === '') {
        // WP shape for "all meta of a post": key => array of values.
        $all = array();
        foreach ($GLOBALS['__meta'][$id] ?? array() as $k => $v) { $all[$k] = array($v); }
        return $all;
    }
    return isset($GLOBALS['__meta'][$id][$key]) ? $GLOBALS['__meta'][$id][$key] : '';
}
function update_post_meta($id, $key, $value) { $GLOBALS['__meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['__meta'][$id][$key]); return true; }

class Fake_WPDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    private $last_args = array();
    public function prepare($sql, ...$args) { $this->last_args = $args; return $sql; }
    public function query($sql) {
        // cleanup_dynamic_meta(): drop numbered media meta of that post
        if (stripos($sql, 'DELETE') !== false && isset($this->last_args[0])) {
            $id = (int) $this->last_args[0];
            foreach (array_keys($GLOBALS['__meta'][$id] ?? array()) as $k) {
                if (preg_match('/^(image_\d+|floor_plan_\d+|document_\d+_(url|title))$/', $k)) {
                    unset($GLOBALS['__meta'][$id][$k]);
                }
            }
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// --- extra stubs for the Bricks element / query types / visibility (v2.13.0)
define('IMMOADMIN_VERSION', 'test');
define('HOUR_IN_SECONDS', 3600);
class WP_Post {
    public $ID; public $post_type;
    public function __construct($id, $type) { $this->ID = $id; $this->post_type = $type; }
}
$GLOBALS['__filters']      = array();
$GLOBALS['__post_types']   = array();
$GLOBALS['__current_post'] = 0;
$GLOBALS['__can_edit']     = false;
function add_filter() { $GLOBALS['__filters'][] = func_get_args(); return true; }
function add_action() { return true; }
function esc_html__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function sanitize_html_class($c) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $c); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function get_option($k, $d = false) { return $d; }
function get_the_ID() { return $GLOBALS['__current_post']; }
function get_post_type($id) { return $GLOBALS['__post_types'][(int) $id] ?? false; }
function get_post($p = null) {
    $id = is_object($p) ? $p->ID : (int) $p;
    return isset($GLOBALS['__post_types'][$id]) ? new WP_Post($id, $GLOBALS['__post_types'][$id]) : null;
}
function current_user_can($cap, ...$args) { return !empty($GLOBALS['__can_edit']); }
function bricks_render_dynamic_data($s) {
    return preg_replace_callback('/\{cf_([a-z0-9_]+)\}/', function ($m) {
        return (string) get_post_meta(get_the_ID(), $m[1], true);
    }, (string) $s);
}

require __DIR__ . '/../includes/class-unit-fields.php';
require __DIR__ . '/../includes/class-post-type.php';
require __DIR__ . '/../includes/class-sync.php';
require __DIR__ . '/../includes/class-visibility.php';
require __DIR__ . '/stubs/bricks.php';
require __DIR__ . '/../bricks/elements/units-table.php';
require __DIR__ . '/../bricks/query-types.php';

// ---------------------------------------------------------------- harness
$GLOBALS['__fails'] = 0;
$GLOBALS['__count'] = 0;
function check($label, $actual, $expected) {
    $GLOBALS['__count']++;
    if ($actual === $expected) {
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['__fails']++;
    echo "  FAIL {$label}\n       expected: " . var_export($expected, true) . "\n       actual:   " . var_export($actual, true) . "\n";
}
function section($name) { echo "\n{$name}\n"; }

function sync_unit(array $unit, array $existing = array()) {
    $m = new ReflectionMethod('ImmoAdmin_Sync', 'sync_unit');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    return $m->invoke(null, $unit, $existing, 'https://api.immoadmin.test');
}

// ---------------------------------------------------------------- tests
section('format_area (mirrors backend formatArea)');
check('null', ImmoAdmin_Unit_Fields::format_area(null), '');
check('empty string', ImmoAdmin_Unit_Fields::format_area(''), '');
check('zero', ImmoAdmin_Unit_Fields::format_area(0), '');
check('"0"', ImmoAdmin_Unit_Fields::format_area('0'), '');
check('non-numeric', ImmoAdmin_Unit_Fields::format_area('abc'), '');
check('bool', ImmoAdmin_Unit_Fields::format_area(true), '');
check('integer', ImmoAdmin_Unit_Fields::format_area(80), '80 m²');
check('float 1 decimal', ImmoAdmin_Unit_Fields::format_area(24.5), '24,5 m²');
check('float 2 decimals', ImmoAdmin_Unit_Fields::format_area(12.25), '12,25 m²');
check('trailing zero stripped', ImmoAdmin_Unit_Fields::format_area('79.90'), '79,9 m²');
check('thousands', ImmoAdmin_Unit_Fields::format_area(1234.5), '1.234,5 m²');
check('numeric string int', ImmoAdmin_Unit_Fields::format_area('35'), '35 m²');

section('augment_meta_fields');
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('pool_area' => 24.5));
check('pool_area → pool_area_formatted', $a['pool_area_formatted'] ?? null, '24,5 m²');
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('pool_area' => null));
check('pool_area null → formatted "" (clears stale)', $a['pool_area_formatted'] ?? 'MISSING', '');
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('pool_area' => 0));
check('pool_area 0 → formatted ""', $a['pool_area_formatted'] ?? 'MISSING', '');
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('garden_area' => 50));
check('no pool_area key → nothing derived (old backend)', array_key_exists('pool_area_formatted', $a), false);
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('pool_area' => 24.5, 'pool_area_formatted' => 'ca. 25 m²'));
check('backend-provided formatted value wins', $a['pool_area_formatted'], 'ca. 25 m²');
$a = ImmoAdmin_Unit_Fields::augment_meta_fields(array('floor' => -10, 'floor_label' => 'GG', 'floor_to' => null));
check('other keys untouched', $a, array('floor' => -10, 'floor_label' => 'GG', 'floor_to' => null));
check('non-array input', ImmoAdmin_Unit_Fields::augment_meta_fields(null), array());

section('floor sort value');
check('int -10 (GG)', ImmoAdmin_Unit_Fields::floor_sort_value(-10, 'GG'), '-10');
check('string "-10" from DB', ImmoAdmin_Unit_Fields::floor_sort_value('-10', 'GG'), '-10');
check('string "0" (EG / EG+OG)', ImmoAdmin_Unit_Fields::floor_sort_value('0', 'EG+OG'), '0');
check('string "99" (DG)', ImmoAdmin_Unit_Fields::floor_sort_value('99', 'DG'), '99');
check('empty → fallback label', ImmoAdmin_Unit_Fields::floor_sort_value('', '-'), '-');
check('null → fallback', ImmoAdmin_Unit_Fields::floor_sort_value(null, 'x'), 'x');
check('garbage → fallback', ImmoAdmin_Unit_Fields::floor_sort_value('abc', 'abc'), 'abc');
$vals = array('99', '1', '-10', '0', '-1', '2');
usort($vals, function ($x, $y) { return (float) $x <=> (float) $y; });
check('numeric order GG < UG < EG < OG < DG', $vals, array('-10', '-1', '0', '1', '2', '99'));
check('floor_label is floor sort key', ImmoAdmin_Unit_Fields::is_floor_sort_key('floor_label'), true);
check('floor is floor sort key', ImmoAdmin_Unit_Fields::is_floor_sort_key('floor'), true);
check('living_area is not', ImmoAdmin_Unit_Fields::is_floor_sort_key('living_area'), false);
check('empty is not', ImmoAdmin_Unit_Fields::is_floor_sort_key(''), false);

section('meta registration');
$fields = ImmoAdmin_Post_Type::get_meta_fields();
check('floor integer', $fields['floor']['type'] ?? null, 'integer');
check('floor_label string', $fields['floor_label']['type'] ?? null, 'string');
check('floor_to integer', $fields['floor_to']['type'] ?? null, 'integer');
check('floor_to_label string', $fields['floor_to_label']['type'] ?? null, 'string');
check('pool_area number (like garden_area)', $fields['pool_area']['type'] ?? null, $fields['garden_area']['type']);
check('pool_area_formatted string', $fields['pool_area_formatted']['type'] ?? null, 'string');

section('sync_unit: new unit, GG maisonette with pool');
$unit = array(
    'id' => 'u-1',
    'title' => 'Top 1',
    'description' => '',
    'metaFields' => array(
        'floor' => -10,
        'floor_label' => 'GG+EG',
        'floor_to' => 0,
        'floor_to_label' => 'EG',
        'garden_area' => 80,
        'garden_area_formatted' => '80 m²',
        'pool_area' => 24.5,
        'feature_1_key' => 'pool',
        'feature_1_label' => 'Pool',
    ),
);
$r = sync_unit($unit);
$pid = $r['post_id'];
check('status created', $r['status'], 'created');
check('floor stored -10', get_post_meta($pid, 'floor'), -10);
check('floor_label GG+EG', get_post_meta($pid, 'floor_label'), 'GG+EG');
check('floor_to 0 (EG, falsy but kept)', get_post_meta($pid, 'floor_to'), 0);
check('floor_to_label EG', get_post_meta($pid, 'floor_to_label'), 'EG');
check('pool_area 24.5', get_post_meta($pid, 'pool_area'), 24.5);
check('pool_area_formatted derived', get_post_meta($pid, 'pool_area_formatted'), '24,5 m²');
check('feature pool label', get_post_meta($pid, 'feature_1_label'), 'Pool');
check('content hash set', get_post_meta($pid, '_content_hash') !== '', true);

section('sync_unit: unchanged → skipped');
$r = sync_unit($unit, array('u-1' => $pid));
check('status skipped', $r['status'], 'skipped');

section('sync_unit: nulls clear stale values');
$unit2 = $unit;
$unit2['metaFields']['floor'] = 2;
$unit2['metaFields']['floor_label'] = '2. OG';
$unit2['metaFields']['floor_to'] = null;
$unit2['metaFields']['floor_to_label'] = null;
$unit2['metaFields']['pool_area'] = null;
$r = sync_unit($unit2, array('u-1' => $pid));
check('status updated', $r['status'], 'updated');
check('floor_to cleared', get_post_meta($pid, 'floor_to'), '');
check('floor_to_label cleared', get_post_meta($pid, 'floor_to_label'), '');
check('pool_area cleared', get_post_meta($pid, 'pool_area'), '');
check('pool_area_formatted cleared', get_post_meta($pid, 'pool_area_formatted'), '');
check('floor updated', get_post_meta($pid, 'floor'), 2);

section('sync_unit: old backend payload (no new keys)');
$old = array('id' => 'u-old', 'title' => 'Alt', 'metaFields' => array('floor' => 1, 'floor_label' => '1. OG', 'garden_area' => 0));
$r = sync_unit($old);
check('no pool_area_formatted written', array_key_exists('pool_area_formatted', $GLOBALS['__meta'][$r['post_id']]), false);
check('no floor_to written', array_key_exists('floor_to', $GLOBALS['__meta'][$r['post_id']]), false);

section('sync_unit: hash only after ALL media succeeded');
$media_unit = array('id' => 'u-media', 'title' => 'Media', 'metaFields' => array(
    'pool_area' => 10,
    'floor_plan_1' => 'https://api.immoadmin.test/uploads/abc123-plan.png',
));
$r = sync_unit($media_unit);
$mid = $r['post_id'];
check('download attempted', $GLOBALS['__remote_calls'] > 0, true);
check('failed media → no content hash', get_post_meta($mid, '_content_hash'), '');
check('failed media → remote URL fallback', get_post_meta($mid, 'floor_plan_1'), 'https://api.immoadmin.test/uploads/abc123-plan.png');
check('fields still written despite media failure', get_post_meta($mid, 'pool_area_formatted'), '10 m²');
file_put_contents(IMMOADMIN_MEDIA_DIR . 'abc123-plan.png', 'png');
$r = sync_unit($media_unit, array('u-media' => $mid));
check('retry happens (not skipped)', $r['status'], 'updated');
check('media now local', get_post_meta($mid, 'floor_plan_1'), 'https://wp.test/wp-content/immoadmin/media/abc123-plan.png');
check('hash set after success', get_post_meta($mid, '_content_hash') !== '', true);
$r = sync_unit($media_unit, array('u-media' => $mid));
check('then skipped', $r['status'], 'skipped');

// ================================================================ v2.13.0
// Units Table presets, building filter, media query types

function priv($class, $method) {
    $m = new ReflectionMethod($class, $method);
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    return $m;
}

section('units-table: classes load');
check('ImmoAdmin_Units_Table loaded', class_exists('ImmoAdmin_Units_Table'), true);
check('ImmoAdmin_Bricks_Query_Types loaded', class_exists('ImmoAdmin_Bricks_Query_Types'), true);
$hooked = array();
foreach ($GLOBALS['__filters'] as $f) { if (is_array($f[1])) { $hooked[] = $f[0] . '=>' . $f[1][1]; } }
check('building filter hooked on bricks/posts/query_vars', in_array('bricks/posts/query_vars=>apply_building_query_vars', $hooked, true), true);
check('sort filter still hooked', in_array('bricks/posts/query_vars=>apply_sort_query_vars', $hooked, true), true);

// ---------------------------------------------------------------- building filter
section('building filter: sanitize selection');
check('array, trimmed, deduped, tags stripped',
    ImmoAdmin_Units_Table::sanitize_building_selection(array('Haus A', ' ', '<b>Haus B</b>', 'Haus A', array('x'), null)),
    array('Haus A', 'Haus B'));
check('single string', ImmoAdmin_Units_Table::sanitize_building_selection('Mackgasse 9'), array('Mackgasse 9'));
check('numeric name kept as string', ImmoAdmin_Units_Table::sanitize_building_selection(array(12)), array('12'));
check('garbage → []', ImmoAdmin_Units_Table::sanitize_building_selection(new stdClass()), array());
check('empty string → []', ImmoAdmin_Units_Table::sanitize_building_selection(''), array());

section('building filter: options from names');
check('natural order, unique, no empties',
    ImmoAdmin_Units_Table::building_options_from_names(array('Haus 11', 'Haus 9', 'haus 10', '', 'Haus 9')),
    array('Haus 9' => 'Haus 9', 'haus 10' => 'haus 10', 'Haus 11' => 'Haus 11'));
check('frontend: no DB query, empty options', ImmoAdmin_Units_Table::building_name_options(), array());

section('building filter: meta_query merge');
$ours = array('relation' => 'AND', array('key' => 'building_name', 'value' => array('Haus A'), 'compare' => 'IN'));
check('empty selection → existing untouched (non-empty)',
    ImmoAdmin_Units_Table::build_building_meta_query(array(array('key' => 'status', 'value' => 'available')), array()),
    array(array('key' => 'status', 'value' => 'available')));
check('empty selection → existing untouched ([])', ImmoAdmin_Units_Table::build_building_meta_query(array(), array()), array());
check('no user meta_query → own group', ImmoAdmin_Units_Table::build_building_meta_query(array(), array('Haus A')),
    array('relation' => 'AND', $ours));
check('own group has no top-level key (Bricks filter merge skips it)', isset($ours['key']), false);
$user_and = array(
    'relation' => 'AND',
    array('key' => 'status', 'value' => 'available', 'compare' => '='),
    array('key' => 'room_count', 'value' => 3, 'compare' => '>=', 'type' => 'NUMERIC'),
);
$merged = ImmoAdmin_Units_Table::build_building_meta_query($user_and, array('Haus A'));
check('AND: user clause 0 kept verbatim', $merged[0], $user_and[0]);
check('AND: user clause 1 kept verbatim', $merged[1], $user_and[1]);
check('AND: relation stays AND', $merged['relation'], 'AND');
check('AND: our group appended', $merged[2], $ours);
check('AND: nothing else added', count($merged), 4);
$user_norel = array(array('key' => 'status', 'value' => 'available'));
check('no relation (WP default AND) → appended',
    ImmoAdmin_Units_Table::build_building_meta_query($user_norel, array('Haus A')),
    array(array('key' => 'status', 'value' => 'available'), $ours));
$user_or = array('relation' => 'OR',
    array('key' => 'status', 'value' => 'available'),
    array('key' => 'status', 'value' => 'reserved'));
check('OR: user query nested intact and AND-ed (narrows, never widens)',
    ImmoAdmin_Units_Table::build_building_meta_query($user_or, array('Haus A')),
    array('relation' => 'AND', $user_or, $ours));
check('OR lowercase handled',
    ImmoAdmin_Units_Table::build_building_meta_query(array('relation' => 'or', array('key' => 'a')), array('Haus A')),
    array('relation' => 'AND', array('relation' => 'or', array('key' => 'a')), $ours));
check('bare single clause wrapped',
    ImmoAdmin_Units_Table::build_building_meta_query(array('key' => 'status', 'value' => 'available'), array('Haus A')),
    array('relation' => 'AND', array('key' => 'status', 'value' => 'available'), $ours));

section('building filter: Bricks query_vars hook');
$qv = array('post_type' => array('immoadmin_wohnung'), 'posts_per_page' => -1, 'meta_query' => $user_and);
check('settings without control (all existing widgets) → identical',
    ImmoAdmin_Units_Table::apply_building_query_vars($qv, array('default_sort_key' => 'sort_key'), 'abc'), $qv);
check('control empty array → identical', ImmoAdmin_Units_Table::apply_building_query_vars($qv, array('immoadmin_buildings' => array()), 'abc'), $qv);
check('control only blanks → identical', ImmoAdmin_Units_Table::apply_building_query_vars($qv, array('immoadmin_buildings' => array(' ', '')), 'abc'), $qv);
check('other element settings (not array) → identical', ImmoAdmin_Units_Table::apply_building_query_vars($qv, null, 'abc'), $qv);
$out = ImmoAdmin_Units_Table::apply_building_query_vars($qv, array('immoadmin_buildings' => array('Haus A', 'Haus B')), 'abc');
check('selection: user meta_query preserved + ours appended', $out['meta_query'], array(
    'relation' => 'AND', $user_and[0], $user_and[1],
    array('relation' => 'AND', array('key' => 'building_name', 'value' => array('Haus A', 'Haus B'), 'compare' => 'IN')),
));
check('selection: other vars untouched', array($out['post_type'], $out['posts_per_page']), array(array('immoadmin_wohnung'), -1));
$out = ImmoAdmin_Units_Table::apply_building_query_vars(array('post_type' => 'immoadmin_wohnung'), array('immoadmin_buildings' => 'Haus A'), 'abc');
check('selection without user meta_query', $out['meta_query'], array('relation' => 'AND', $ours));
// Sort + building filter chained like Bricks does (both on bricks/posts/query_vars)
$settings = array('default_sort_key' => 'building_name', 'immoadmin_buildings' => array('Haus A'));
$chain = ImmoAdmin_Units_Table::apply_sort_query_vars(array('post_type' => 'immoadmin_wohnung'), $settings, 'abc');
$chain = ImmoAdmin_Units_Table::apply_building_query_vars($chain, $settings, 'abc');
check('chained with sort: sort spec intact', isset($chain['immoadmin_sort_spec']) && $chain['orderby'] === 'none', true);
check('chained with sort: building group present', $chain['meta_query'], array('relation' => 'AND', $ours));

// ---------------------------------------------------------------- query types
section('query types: collect_media');
$QT = 'ImmoAdmin_Bricks_Query_Types';
$plans = function ($items) { return array_map(function ($i) { return $i['index'] . '=' . $i['url']; }, $items); };
check('0 plans → []', $QT::collect_media(array('floor_plans_count' => array('0'), 'door_number' => array('1')), $QT::TYPE_FLOOR_PLANS), array());
check('1 plan', $plans($QT::collect_media(array('floor_plan_1' => array('https://x/p1.png')), $QT::TYPE_FLOOR_PLANS)), array('1=https://x/p1.png'));
$meta = array(
    'floor_plan_10' => array('https://x/p10.png'),
    'floor_plan_1'  => array('https://x/p1.png'),
    'floor_plan_3'  => array('https://x/p3.png'),
    'floor_plan_4'  => array(''),          // empty → skipped
    'floor_plans_count' => array('2'),     // stale count must not matter
    'floor_plan_x'  => array('nope'),
    'floor_plans'   => array('[]'),
    'image_1'       => array('https://x/i1.jpg'),
);
check('N plans with gaps, numeric order, empties skipped', $plans($QT::collect_media($meta, $QT::TYPE_FLOOR_PLANS)),
    array('1=https://x/p1.png', '3=https://x/p3.png', '10=https://x/p10.png'));
check('flat meta map accepted too', $plans($QT::collect_media(array('floor_plan_2' => 'https://x/p2.png'), $QT::TYPE_FLOOR_PLANS)), array('2=https://x/p2.png'));
$item = $QT::collect_media($meta, $QT::TYPE_FLOOR_PLANS, 77)[0];
check('item shape', $item, array('type' => 'immoadmin_floor_plans', 'index' => 1, 'url' => 'https://x/p1.png', 'title' => 'Grundriss 1', 'post_id' => 77));
check('images separate from plans', $plans($QT::collect_media($meta, $QT::TYPE_IMAGES)), array('1=https://x/i1.jpg'));
$docs = $QT::collect_media(array(
    'document_2_url' => 'https://x/b.pdf', 'document_2_title' => '',
    'document_1_url' => 'https://x/a.pdf', 'document_1_title' => 'Exposé',
), $QT::TYPE_DOCUMENTS);
check('documents: titles + fallback title', array_map(function ($i) { return $i['title']; }, $docs), array('Exposé', 'Dokument 2'));
check('unknown type → []', $QT::collect_media($meta, 'post'), array());

section('query types: items_for_post / visibility');
$GLOBALS['__post_types'][501] = 'immoadmin_wohnung';
$GLOBALS['__post_types'][502] = 'page';
$GLOBALS['__meta'][501] = array('status' => 'available', 'floor_plan_1' => 'https://x/a1.png', 'floor_plan_2' => 'https://x/a2.png',
    'document_1_url' => 'https://x/expose.pdf', 'document_1_title' => 'Exposé');
$GLOBALS['__meta'][502] = array('floor_plan_1' => 'https://x/page.png');
check('unit → its plans', count($QT::items_for_post(501, $QT::TYPE_FLOOR_PLANS)), 2);
check('non-unit post → []', $QT::items_for_post(502, $QT::TYPE_FLOOR_PLANS), array());
check('post 0 → []', $QT::items_for_post(0, $QT::TYPE_FLOOR_PLANS), array());
check('available unit: documents listed', count($QT::items_for_post(501, $QT::TYPE_DOCUMENTS)), 1);
$GLOBALS['__meta'][501]['status'] = 'reserved';
check('reserved unit, visitor: documents hidden', $QT::items_for_post(501, $QT::TYPE_DOCUMENTS), array());
check('reserved unit, visitor: plans still shown', count($QT::items_for_post(501, $QT::TYPE_FLOOR_PLANS)), 2);
$GLOBALS['__can_edit'] = true;
check('reserved unit, editor: documents listed', count($QT::items_for_post(501, $QT::TYPE_DOCUMENTS)), 1);
$GLOBALS['__can_edit'] = false;
$GLOBALS['__meta'][501]['status'] = 'available';

section('query types: current unit + run_query');
$GLOBALS['bricks_loop_query'] = array();
$GLOBALS['__current_post'] = 501;
check('no Bricks loop → global post', $QT::resolve_unit_id(), 501);
$table_q = (object) array('object_type' => 'post', 'is_looping' => true, 'loop_object' => new WP_Post(501, 'immoadmin_wohnung'));
$GLOBALS['__current_post'] = 999;
$GLOBALS['bricks_loop_query'] = array('tbl001' => $table_q);
check('inside units-table row → row unit (not global post)', $QT::resolve_unit_id(), 501);
$slide_q = (object) array('object_type' => $QT::TYPE_FLOOR_PLANS, 'is_looping' => false, 'loop_object' => null);
check('run_query: our type → items of row unit', count($QT::run_query(array(), $slide_q)), 2);
$other = (object) array('object_type' => 'acf_repeater');
check('run_query: foreign type passthrough', $QT::run_query(array('x'), $other), array('x'));

section('query types: dynamic tags');
$plan_items = $QT::run_query(array(), $slide_q);
$slide_q->is_looping = true;
$slide_q->loop_object = $plan_items[1];
$GLOBALS['bricks_loop_query']['sld001'] = $slide_q;
check('image context → [url] of current plan', $QT::render_tag('{immoadmin_media_url}', null, 'image'), array('https://x/a2.png'));
check('tag without braces', $QT::render_tag('immoadmin_media_url', null, 'image'), array('https://x/a2.png'));
check('text context index', $QT::render_tag('{immoadmin_media_index}', null, 'text'), '2');
check('title', $QT::render_tag('{immoadmin_media_title}', null, 'text'), 'Grundriss 2');
check('foreign tag passthrough', $QT::render_tag('{post_title}', null, 'text'), '{post_title}');
check('non-string passthrough', $QT::render_tag(array(5), null, 'image'), array(5));
check('render_content replaces embedded tags', $QT::render_content('Plan {immoadmin_media_index}: {immoadmin_media_url} {post_title}', null, 'text'),
    'Plan 2: https://x/a2.png {post_title}');
check('render_content without our tags untouched', $QT::render_content('Top {cf_door_number}', null, 'text'), 'Top {cf_door_number}');
$GLOBALS['bricks_loop_query'] = array('tbl001' => $table_q);
check('outside our loop: image → [] (Image renders nothing)', $QT::render_tag('{immoadmin_media_url}', null, 'image'), array());
check('outside our loop: text → ""', $QT::render_tag('{immoadmin_media_url}', null, 'text'), '');
$GLOBALS['bricks_loop_query'] = array();

section('query types: builder registration');
$opts = $QT::add_query_types(array('queryTypes' => array('post' => 'Posts')));
check('keeps core types', $opts['queryTypes']['post'], 'Posts');
check('adds Grundrisse', $opts['queryTypes']['immoadmin_floor_plans'], 'ImmoAdmin Grundrisse');
check('adds Bilder + Dokumente', isset($opts['queryTypes']['immoadmin_images'], $opts['queryTypes']['immoadmin_documents']), true);
$tags = array_map(function ($t) { return $t['name']; }, $QT::add_tags_to_builder(array()));
check('dynamic tags listed', $tags, array('{immoadmin_media_url}', '{immoadmin_media_title}', '{immoadmin_media_index}'));

// ---------------------------------------------------------------- presets
// Meta keys the sync writes (immoadmin/apps/api/src/lib/sync-payload.ts)
// that are not register_post_meta()'d: the *_formatted display strings
// and the numbered media fields.
$known_keys = array_keys(ImmoAdmin_Post_Type::get_meta_fields());
$known_keys = array_merge($known_keys, array(
    'living_area_formatted', 'usable_area_formatted', 'garden_area_formatted', 'balcony_area_formatted',
    'terrace_area_formatted', 'loggia_area_formatted', 'pool_area_formatted', 'roof_terrace_area_formatted',
    'outdoor_area_total_formatted', 'purchase_price_formatted', 'floor_plans_count', 'images_count', 'documents_count',
));
$is_known = function ($key) use ($known_keys) {
    return in_array($key, $known_keys, true) || preg_match('/^(floor_plan_\d+|image_\d+|document_\d+_(url|title))$/', $key);
};
check('known-key check rejects unknown keys (self-test)', (bool) $is_known('foo_formatted'), false);
$cf_tags = function ($s) { preg_match_all('/\{cf_([a-z0-9_]+)\}/', (string) $s, $m); return $m[1]; };

section('presets: default columns');
$cols = ImmoAdmin_Units_Table::default_columns();
check('headers exactly as requested (+ arrow column)', array_map(function ($c) { return $c['header']; }, $cols),
    array('TOP', 'Stockwerk', 'Zimmer', 'Wohnfläche', 'Garten', 'Balkon', 'Terrasse', 'Loggia', 'Kaufpreis', 'Status', ''));
check('no pool column in preset', count(array_filter($cols, function ($c) { return strpos($c['value'], 'pool') !== false; })), 0);
$bad = array();
foreach ($cols as $c) {
    foreach ($cf_tags($c['value']) as $k) { if (!$is_known($k)) { $bad[] = $k; } }
    if (!empty($c['sortable']) && !$is_known($c['sort_meta_key'])) { $bad[] = 'sort:' . $c['sort_meta_key']; }
}
check('every {cf_*} and sort key is a synced meta key', $bad, array());
check('Stockwerk sorts by floor', $cols[1]['sort_meta_key'], 'floor');
check('Wohnfläche uses formatted value', $cols[3]['value'], '{cf_living_area_formatted}');
check('Kaufpreis is redacted for reserved units', priv('ImmoAdmin_Units_Table', 'is_sensitive_column')->invoke(null, $cols[8]), true);
check('Status colour from status meta', $cols[9]['type'] . '/' . (int) $cols[9]['status_color_from_meta'], 'status_badge/1');
check('arrow column last, icon + toggle, not sortable', array($cols[10]['type'], $cols[10]['accordion_toggle'], $cols[10]['sortable']), array('icon', true, false));
check('fallback dash on data columns', count(array_filter($cols, function ($c) { return $c['fallback'] === '—'; })), 10);

section('presets: default detail (native Bricks elements)');
$detail = ImmoAdmin_Units_Table::default_detail();
$allowed = array('block', 'heading', 'text-basic', 'button', 'image', 'slider-nested');
$names = array(); $problems = array(); $cond_ids = array(); $loops = array(); $dd = array();
$walk = function ($el, $depth = 0) use (&$walk, &$names, &$problems, &$cond_ids, &$loops, &$dd) {
    $names[] = $el['name'] ?? '?';
    if (!in_array($el['name'] ?? '', array('block', 'heading', 'text-basic', 'button', 'image', 'slider-nested'), true)) { $problems[] = 'name:' . ($el['name'] ?? '?'); }
    if (isset($el['id'])) { $problems[] = 'has id (builder must generate)'; }
    if (empty($el['settings']) || !is_array($el['settings']) || array_keys($el['settings']) === range(0, count($el['settings']) - 1)) {
        $problems[] = 'settings not a non-empty map: ' . $el['name'];
    }
    foreach ($el['settings']['_conditions'] ?? array() as $set) {
        foreach ($set as $c) { $cond_ids[] = $c['id']; $dd[] = $c['dynamic_data']; if ($c['key'] !== 'dynamic_data' || $c['compare'] !== 'empty_not') { $problems[] = 'cond'; } }
    }
    if (!empty($el['settings']['hasLoop'])) { $loops[] = $el['settings']['query']['objectType'] ?? ''; }
    $dd[] = json_encode($el['settings']);
    foreach ($el['children'] ?? array() as $child) { $walk($child, $depth + 1); }
};
$walk($detail);
check('only allowed native element names', $problems, array());
check('uses every planned element type', array_values(array_intersect($allowed, array_unique($names))), $allowed);
check('condition ids unique', count($cond_ids), count(array_unique($cond_ids)));
check('exactly one loop: ImmoAdmin Grundrisse', $loops, array('immoadmin_floor_plans'));
check('loop type is registered', isset($QT::types()[$loops[0]]), true);
$bad = array(); $media_tags = array();
foreach ($dd as $s) {
    foreach ($cf_tags($s) as $k) { if (!$is_known($k)) { $bad[] = $k; } }
    preg_match_all('/\{(immoadmin_[a-z_]+)\}/', $s, $m);
    $media_tags = array_merge($media_tags, $m[1]);
}
check('every {cf_*} in detail is a synced meta key', $bad, array());
check('{immoadmin_*} tags are registered', array_values(array_diff(array_unique($media_tags), $QT::tag_names())), array());
check('slider wraps exactly one loop slide', (function () use ($detail) {
    $slider = $detail['children'][1]['children'][0];
    return $slider['name'] === 'slider-nested' && count($slider['children']) === 1 && !empty($slider['children'][0]['settings']['hasLoop']);
})(), true);
check('slider hidden for units without plan', $detail['children'][1]['children'][0]['settings']['_conditions'][0][0]['dynamic_data'], '{cf_floor_plan_1}');
check('slider type slide (1 plan not cloned)', $detail['children'][1]['children'][0]['settings']['type'], 'slide');
check('JSON round trip clean', json_decode(json_encode($detail), true), $detail);
$inst = new ImmoAdmin_Units_Table(array('id' => 'tbl001', 'settings' => array()));
check('get_nestable_children() = [detail]', $inst->get_nestable_children(), array($detail));
check('get_nestable_item() = detail', $inst->get_nestable_item(), $detail);

section('presets: controls (existing widgets unchanged)');
$inst->set_control_groups();
$inst->set_controls();
check('Gebäude control in Query group', $inst->controls['immoadmin_buildings']['group'] ?? null, 'query');
check('Gebäude control has NO default (absent = all)', array_key_exists('default', $inst->controls['immoadmin_buildings']), false);
check('Gebäude multi-select', $inst->controls['immoadmin_buildings']['multiple'], true);
check('query default unchanged', $inst->controls['query']['default'], array(
    'objectType' => 'post', 'post_type' => array('immoadmin_wohnung'), 'posts_per_page' => -1,
    'orderby' => 'meta_value_num', 'meta_key' => 'sort_key', 'order' => 'ASC'));
check('columns default = preset', $inst->controls['columns']['default'], $cols);
foreach (array('sort_raw_meta', 'status_color_from_meta', 'accordion_toggle') as $flag) {
    check("new column field {$flag} has no default (opt-in)", array_key_exists('default', $inst->controls['columns']['fields'][$flag]), false);
}

// ---------------------------------------------------------------- render_cell
section('render_cell: new opt-in flags vs. unchanged legacy columns');
$cell = priv('ImmoAdmin_Units_Table', 'render_cell');
$GLOBALS['__post_types'][601] = 'immoadmin_wohnung';
$GLOBALS['__meta'][601] = array('status' => 'reserved', 'status_label' => 'Reserviert', 'purchase_price' => '1200000',
    'purchase_price_formatted' => '1.200.000', 'living_area' => '79.9', 'living_area_formatted' => '79,9 m²', 'floor' => '-10', 'floor_label' => 'GG');
$GLOBALS['__current_post'] = 601;
$sortval = function ($html) { preg_match('/data-sort-value="([^"]*)"/', $html, $m); return $m[1] ?? null; };
$price = $cols[8];
$legacy_price = $price; unset($legacy_price['sort_raw_meta']);
check('legacy column: sorts by display text (as before)', $sortval($cell->invoke(null, $legacy_price, 8, false, true)), '1.200.000');
check('preset column: sorts by raw number', $sortval($cell->invoke(null, $price, 8, false, true)), '1200000');
$redacted = $cell->invoke(null, $price, 8, true, false);
check('restricted row: raw price NOT in sort value', $sortval($redacted), '');
check('restricted row: price not rendered', strpos($redacted, '1.200') === false && strpos($redacted, '1200000') === false, true);
check('area: raw value for sort', $sortval($cell->invoke(null, $cols[3], 3, false, true)), '79.9');
check('floor column: numeric floor sort (unchanged rule)', $sortval($cell->invoke(null, $cols[1], 1, false, true)), '-10');
$status_html = $cell->invoke(null, $cols[9], 9, false, true);
check('status preset: label shown, colour class from status', strpos($status_html, 'is-reserved">Reserviert<') !== false, true);
$legacy_status = $cols[9]; unset($legacy_status['status_color_from_meta']);
check('legacy status badge: class from resolved value (unchanged)', strpos($cell->invoke(null, $legacy_status, 9, false, true), 'is-Reserviert') !== false, true);
$arrow_open = $cell->invoke(null, $cols[10], 10, false, true);
check('arrow column in openable row: rotating toggle icon', strpos($arrow_open, 'immoadmin-accordion-toggle') !== false && strpos($arrow_open, 'ti-angle-down') !== false, true);
check('arrow column in row without panel: no icon', strpos($cell->invoke(null, $cols[10], 10, true, false), '<i ') === false, true);
$legacy_icon = $cols[10]; unset($legacy_icon['accordion_toggle']);
check('legacy icon column without panel: icon still rendered (unchanged)', strpos($cell->invoke(null, $legacy_icon, 10, true, false), 'ti-angle-down') !== false, true);
check('legacy icon column: no toggle class', strpos($cell->invoke(null, $legacy_icon, 10, false, true), 'immoadmin-accordion-toggle') === false, true);
check('empty garden → fallback dash', strpos($cell->invoke(null, $cols[4], 4, false, true), '>—<') !== false, true);
$GLOBALS['__current_post'] = 0;

// cleanup temp dir
@unlink(IMMOADMIN_MEDIA_DIR . 'abc123-plan.png');
@rmdir(IMMOADMIN_MEDIA_DIR);
@rmdir(IMMOADMIN_DATA_DIR);

echo "\n{$GLOBALS['__count']} checks, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);
