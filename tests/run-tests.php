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

// --- extra stubs for the filter widgets (v2.14.0)
define('ARRAY_A', 'ARRAY_A');
define('IMMOADMIN_PLUGIN_URL', 'https://wp.test/wp-content/plugins/immoadmin/');
$GLOBALS['__enqueued']   = array();
$GLOBALS['__is_builder'] = false;
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function wp_enqueue_style($h) { $GLOBALS['__enqueued'][] = 'style:' . $h; }
function wp_enqueue_script($h) { $GLOBALS['__enqueued'][] = 'script:' . $h; }
function bricks_is_builder() { return !empty($GLOBALS['__is_builder']); }

require __DIR__ . '/../includes/class-unit-fields.php';
require __DIR__ . '/../includes/class-post-type.php';
require __DIR__ . '/../includes/class-sync.php';
require __DIR__ . '/../includes/class-visibility.php';
require __DIR__ . '/stubs/bricks.php';
require __DIR__ . '/../bricks/filter-data.php';
require __DIR__ . '/../bricks/elements/units-table.php';
require __DIR__ . '/../bricks/elements/filter-buttons.php';
require __DIR__ . '/../bricks/elements/filter-range.php';
require __DIR__ . '/../bricks/elements/filter-actions.php';
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

// ---------------------------------------------------------------- byte-identical baseline
section('units-table: output without Filter-Gruppe is byte-identical to v2.13.0');
require_once __DIR__ . '/fixtures/units-table-cases.php';
$baseline_file = __DIR__ . '/fixtures/units-table-baseline.json';
$rendered = array();
foreach (immoadmin_test_table_cases() as $case => $case_settings) {
    $rendered[$case] = immoadmin_test_render_table($case_settings);
}
if (in_array('--write-baseline', $argv ?? array(), true)) {
    file_put_contents($baseline_file, json_encode($rendered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "  wrote baseline ({$baseline_file})\n";
}
$baseline = json_decode((string) @file_get_contents($baseline_file), true);
check('baseline file present', is_array($baseline) && count($baseline) === count($rendered), true);
foreach ($rendered as $case => $html) {
    check("{$case}: identical", $html, $baseline[$case] ?? null);
}
check('sanity: baseline renders rows', strpos($baseline['accordion-preset'] ?? '', 'data-unit-id="701"') !== false, true);
check('sanity: reserved price never in baseline', strpos(implode('', $baseline), '500.000') === false && strpos(implode('', $baseline), '500000') === false, true);

// ================================================================ v2.14.0
// Filter widgets: data helpers, elements, units-table integration

$FD = 'ImmoAdmin_Filter_Data';

section('filter data: groups, keys, floors');
check('group lower-cased + trimmed', $FD::sanitize_group(' Wohnungen '), 'wohnungen');
check('group: spaces/umlauts → dashes', $FD::sanitize_group('Haus Süd'), 'haus-s-d');
check('group: non-scalar → ""', $FD::sanitize_group(array('x')), '');
check('group: empty', $FD::sanitize_group(''), '');
check('meta key valid', $FD::sanitize_meta_key(' Balcony_Area '), 'balcony_area');
check('meta key invalid → ""', $FD::sanitize_meta_key('a b'), '');
check('key list', $FD::parse_key_list('balcony_area, object_type_label;bad key,balcony_area'), array('balcony_area', 'object_type_label', 'bad', 'key'));
check('to_floor "0" = EG (not empty)', $FD::to_floor('0'), 0);
check('to_floor "" = null', $FD::to_floor(''), null);
check('to_floor "-10"', $FD::to_floor('-10'), -10);
check('floor labels', array_map(array($FD, 'floor_label'), array(-10, -1, 0, 2, 96, 98, 99, 12, -4)),
    array('GG', '1. UG', 'EG', '2. OG', 'OG', '2. DG', 'DG', '12. OG', '4. UG'));
$fl = array(99, 0, -10, -1, 2);
usort($fl, function ($a, $b) use ($FD) { return $FD::floor_sort_key($a) <=> $FD::floor_sort_key($b); });
check('floor order UG < GG < EG < OG < DG', $fl, array(-1, -10, 0, 2, 99));
check('to_number 0 = empty for sync fields', $FD::to_number('0'), null);
check('to_number 0 kept when asked', $FD::to_number('0', false), 0);
check('to_number "79.9"', $FD::to_number('79.9'), 79.9);
check('to_number "3" → int', $FD::to_number('3'), 3);
check('to_number junk', $FD::to_number('abc'), null);

section('filter data: orientation parsing');
check('key "south"', $FD::parse_orientation('south'), array('south'));
check('German "Süd/West"', $FD::parse_orientation('Süd/West'), array('south', 'west'));
check('"Südwest" compound', $FD::parse_orientation('Südwest'), array('south', 'west'));
check('"SW"', $FD::parse_orientation('SW'), array('south', 'west'));
check('"Nord-Ost"', $FD::parse_orientation('Nord-Ost'), array('east', 'north'));
check('"south,west"', $FD::parse_orientation('south,west'), array('south', 'west'));
check('"Ost und West"', $FD::parse_orientation('Ost und West'), array('east', 'west'));
check('"Südausrichtung" (feature label)', $FD::parse_orientation('Südausrichtung'), array('south'));
check('features JSON adds directions', $FD::parse_orientation('south', '["balcony","west","south"]'), array('south', 'west'));
check('features only', $FD::parse_orientation('', '["east"]'), array('east'));
check('nothing', $FD::parse_orientation('', ''), array());
check('garbage features', $FD::parse_orientation(null, '{bad'), array());
check('labels', array_map(array($FD, 'orientation_label'), array('east', 'south', 'west', 'north')), array('Ost', 'Süd', 'West', 'Nord'));

section('filter data: sensitivity (same list as REST redaction)');
foreach (array('purchase_price', 'rent_cold', 'rent_warm', 'price_per_sqm', 'purchase_price_investor', 'document_1_url') as $k) {
    check("{$k} sensitive", $FD::is_sensitive_key($k), true);
}
foreach (array('building_name', 'floor', 'floor_to', 'orientation', 'room_count', 'living_area', 'usable_area', 'features', 'balcony_area') as $k) {
    check("{$k} not sensitive", $FD::is_sensitive_key($k), false);
}
check('public statuses', array($FD::is_public_status('available'), $FD::is_public_status(''), $FD::is_public_status('reserved'), $FD::is_public_status('sold'), $FD::is_public_status('foo')),
    array(true, true, false, false, false));

section('filter data: row values');
$meta_a = array('building_name' => ' Presto ', 'floor' => '-10', 'floor_to' => '0', 'orientation' => 'south', 'features' => '["west"]',
    'room_count' => '3', 'living_area' => '79.9', 'usable_area' => '0', 'purchase_price' => '439800', 'rent_cold' => '', 'price_per_sqm' => '5504',
    'balcony_area' => '0', 'object_type_label' => 'Wohnung', 'parking_price' => '25000');
$va = $FD::values_from_meta($meta_a, array('balcony_area', 'object_type_label', 'parking_price'));
check('row values (public unit)', $va, array(
    'building_name' => 'Presto', 'floor' => -10, 'floor_to' => 0, 'orientation' => array('south', 'west'),
    'room_count' => 3, 'living_area' => 79.9, 'usable_area' => null, 'purchase_price' => 439800, 'rent_cold' => null, 'rent_warm' => null,
    'price_per_sqm' => 5504, 'balcony_area' => 0, 'object_type_label' => 'Wohnung', 'parking_price' => 25000,
));
$vr = $FD::values_from_meta($meta_a, array('balcony_area', 'parking_price'), true);
check('restricted: every price null', array($vr['purchase_price'], $vr['price_per_sqm'], $vr['parking_price']), array(null, null, null));
check('restricted: non-price values kept', array($vr['building_name'], $vr['living_area'], $vr['room_count'], $vr['balcony_area']), array('Presto', 79.9, 3, 0));
check('restricted JSON contains no price digits', strpos(json_encode($vr), '439800') === false && strpos(json_encode($vr), '25000') === false, true);
check('standard keys first, stable order', array_slice(array_keys($va), 0, 11), $FD::standard_keys());
check('array meta values (get_post_meta all) accepted', $FD::values_from_meta(array('building_name' => array('Largo')))['building_name'], 'Largo');

section('filter data: registered custom keys');
$FD::reset_registered_keys();
$FD::register_custom_key('Wohnungen', 'balcony_area');
$FD::register_custom_key('wohnungen', 'living_area'); // standard → ignored
$FD::register_custom_key('', 'x');
check('registered per group', $FD::registered_keys('wohnungen'), array('balcony_area'));
check('other group empty', $FD::registered_keys('andere'), array());
$FD::reset_registered_keys();

// Rows as fetch_rows() returns them (raw meta strings)
$rows = array(
    array('building_name' => 'Presto', 'status' => 'available', 'floor' => '-10', 'floor_label' => 'GG+EG', 'floor_to' => '0', 'floor_to_label' => 'EG',
        'orientation' => 'south', 'features' => '["west"]', 'room_count' => '3', 'living_area' => '79.9', 'purchase_price' => '439800'),
    array('building_name' => 'Allegro', 'status' => 'reserved', 'floor' => '1', 'floor_label' => '1. OG', 'orientation' => 'east',
        'room_count' => '2', 'living_area' => '154', 'purchase_price' => '990000'),
    array('building_name' => 'Largo', 'status' => 'available', 'floor' => '99', 'floor_label' => 'DG', 'orientation' => 'Nord',
        'room_count' => '5', 'living_area' => '70', 'purchase_price' => '280000'),
    array('building_name' => 'Andante', 'status' => 'sold', 'floor' => '-1', 'floor_label' => '1. UG', 'room_count' => '4', 'living_area' => '101', 'purchase_price' => '1500000'),
    array('building_name' => 'Presto', 'status' => 'available', 'floor' => '2', 'floor_label' => '2. OG', 'room_count' => '2.5', 'living_area' => '0', 'purchase_price' => '760000'),
    array('building_name' => '', 'status' => 'available', 'floor' => '', 'room_count' => '0'),
);
$labels = function ($opts) { return array_map(function ($o) { return $o['label']; }, $opts); };
$values = function ($opts) { return array_map(function ($o) { return $o['value']; }, $opts); };

section('options: Haus / Geschoss / Ausrichtung / Zimmer');
check('Haus: distinct, natural sort, no empty', $labels($FD::button_options('building_name', $rows)), array('Allegro', 'Andante', 'Largo', 'Presto'));
$floors = $FD::button_options('floor', $rows);
check('Geschoss: single floors, maisonette split, physical order', $values($floors), array('-1', '-10', '0', '1', '2', '99'));
check('Geschoss labels from data (EG from floor_to_label)', $labels($floors), array('1. UG', 'GG', 'EG', '1. OG', '2. OG', 'DG'));
check('Geschoss: maisonette label "GG+EG" never an option', in_array('GG+EG', $labels($floors), true), false);
check('Ausrichtung: sun order, features + German text', $labels($FD::button_options('orientation', $rows)), array('Ost', 'Süd', 'West', 'Nord'));
check('Ausrichtung values are keys', $values($FD::button_options('orientation', $rows)), array('east', 'south', 'west', 'north'));
check('Zimmer: numeric order, 0 = none skipped, 2,5 label', $FD::button_options('room_count', $rows), array(
    array('value' => '2', 'label' => '2'), array('value' => '2.5', 'label' => '2,5'), array('value' => '3', 'label' => '3'),
    array('value' => '4', 'label' => '4'), array('value' => '5', 'label' => '5')));
check('Zimmer grouped from 4 → "4+"', $labels($FD::button_options('room_count', $rows, array('group_from' => 4))), array('2', '2,5', '3', '4+'));
check('Zimmer "4+" value', $values($FD::button_options('room_count', $rows, array('group_from' => 4)))[3], '4+');
check('Zimmer group_from above max → no "+" option', $labels($FD::button_options('room_count', $rows, array('group_from' => 9))), array('2', '2,5', '3', '4', '5'));
check('Eigenes Feld', $labels($FD::button_options('__custom', $rows, array('key' => 'floor_label'))), array('1. OG', '1. UG', '2. OG', 'DG', 'GG+EG'));
check('Eigenes Feld without key → []', $FD::button_options('__custom', $rows, array('key' => '')), array());
check('unknown field → []', $FD::button_options('nope', $rows), array());
check('no rows → []', $FD::button_options('building_name', array()), array());

section('options: designer overrides');
$auto = $FD::button_options('floor', $rows);
$custom = $FD::apply_custom_options($auto, array(
    array('value' => '-10', 'label' => ''),
    array('value' => ' 0 ', 'label' => 'EG'),
    array('value' => '1 | 2 |', 'label' => 'OG'),
    array('value' => '99', 'label' => 'DG'),
    array('value' => '', 'label' => 'leer'),
    array('value' => '99', 'label' => 'doppelt'),
));
check('override: order + labels + "|" alternatives', $custom, array(
    array('value' => '-10', 'label' => 'GG'), array('value' => '0', 'label' => 'EG'),
    array('value' => '1|2', 'label' => 'OG'), array('value' => '99', 'label' => 'DG')));
check('empty override → automatic', $FD::apply_custom_options($auto, array()), $auto);
check('only invalid rows → automatic', $FD::apply_custom_options($auto, array(array('value' => ''))), $auto);

section('range: bounds, rounding, formatting');
$pv = $FD::range_values($rows, 'purchase_price');
check('price bounds from PUBLIC units only (990k reserved, 1.5M sold ignored)', array($pv['min'], $pv['max'], $pv['count']), array(280000.0, 760000.0, 3));
$av = $FD::range_values($rows, 'living_area');
check('area bounds from all units, 0 = no value', array($av['min'], $av['max'], $av['count']), array(70.0, 154.0, 4));
check('nice step: price span', $FD::nice_step(280000, 760000), 10000.0);
check('nice step: area span', $FD::nice_step(70, 154), 1.0);
check('nice step: zero span', $FD::nice_step(5, 5), 1.0);
check('range config price', $FD::range_config($pv), array('min' => 280000.0, 'max' => 760000.0, 'step' => 10000.0));
check('rounded outward', $FD::range_config(array('min' => 283500, 'max' => 757000)), array('min' => 280000.0, 'max' => 760000.0, 'step' => 10000.0));
check('area 69.5–154.2 rounded outward', $FD::range_config(array('min' => 69.5, 'max' => 154.2)), array('min' => 69.0, 'max' => 155.0, 'step' => 1.0));
check('manual min/max/step win', $FD::range_config($pv, '200000', '900000', '50000'), array('min' => 200000.0, 'max' => 900000.0, 'step' => 50000.0));
check('manual swapped min/max fixed', $FD::range_config($pv, '900000', '200000', '50000'), array('min' => 200000.0, 'max' => 900000.0, 'step' => 50000.0));
check('single value → max = min + step', $FD::range_config(array('min' => 80, 'max' => 80)), array('min' => 80.0, 'max' => 81.0, 'step' => 1.0));
check('no data, no manual → null', $FD::range_config(array('min' => null, 'max' => null)), null);
check('no data but manual range → config', $FD::range_config(array('min' => null, 'max' => null), '0', '100', '5'), array('min' => 0.0, 'max' => 100.0, 'step' => 5.0));
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/format-cases.json'), true) as $c) {
    check('format ' . json_encode($c['value']) . ' ' . json_encode($c['fmt']), $FD::format_value($c['value'], $c['fmt']), $c['expected']);
}
check('default format price = k', $FD::default_format('price')['mode'], 'k');
check('default format area suffix', $FD::default_format('area')['suffix'], ' m²');

// ---------------------------------------------------------------- elements
$render = function ($el) { ob_start(); $el->render(); return ob_get_clean(); };
$attr = function ($html, $name) { return preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null; };
$FD::set_rows_for_tests($rows);

section('element: Filter-Buttons');
$b = new ImmoAdmin_Filter_Buttons(array('id' => 'fb1', 'settings' => array('filter_group' => 'Wohnungen', 'field' => 'building_name', 'label' => 'Haus')));
$b->set_control_groups(); $b->set_controls();
check('category ImmoAdmin', $b->category, 'immoadmin');
check('name', $b->name, 'immoadmin-filter-buttons');
check('v2.15.2: no "Filter-Gruppe" control any more', isset($b->controls['filter_group']), false);
check('v2.15.2: "Ziel-Tabellen" multi-select is the first control', array(array_slice(array_keys($b->controls), 0, 1), $b->controls['filter_targets']['type'], $b->controls['filter_targets']['multiple']), array(array('filter_targets'), 'select', true));
check('v2.15: Ziel-Tabellen help text explains the empty default', strpos($b->controls['filter_targets']['description'], 'Leer = alle ImmoAdmin-Tabellen auf dieser Seite') === 0, true);
check('v2.15: no builder → no options (frontend never scans for the dropdown)', $b->controls['filter_targets']['options'], array());
check('field options include all fields', array_keys($b->controls['field']['options']), array('building_name', 'floor', 'orientation', 'room_count', '__custom'));
check('style: Aktiv background maps to .brx-option-active', $b->controls['optionActiveBackground']['css'][0]['selector'], '.immoadmin-filter-option.brx-option-active');
check('style: Hover typography maps to :hover', $b->controls['optionHoverTypography']['css'][0]['selector'], '.immoadmin-filter-option:hover');
check('style: label typography', $b->controls['labelTypography']['css'][0]['selector'], '.immoadmin-filter-label');
$html = $render($b);
check('root data: type + group (sanitized)', array($attr($html, 'data-immoadmin-filter'), $attr($html, 'data-immoadmin-filter-group')), array('buttons', 'wohnungen'));
check('config JSON', json_decode($attr($html, 'data-immoadmin-filter-config'), true), array('key' => 'building_name', 'match' => 'text', 'multiple' => true));
preg_match_all('/data-value="([^"]*)"/', $html, $m);
check('one button per house', $m[1], array('Allegro', 'Andante', 'Largo', 'Presto'));
check('label + aria-labelledby', strpos($html, '<span class="immoadmin-filter-label" id="iaf-label-fb1">Haus</span>') !== false && strpos($html, 'aria-labelledby="iaf-label-fb1"') !== false, true);
check('buttons are toggle buttons (aria-pressed=false)', substr_count($html, 'aria-pressed="false"'), 4);
check('no preset → no bricks-button class', strpos($html, 'bricks-button') === false, true);
check('no active state on frontend render', strpos($html, 'brx-option-active') === false, true);
$b2 = new ImmoAdmin_Filter_Buttons(array('id' => 'fb2', 'settings' => array('filter_group' => 'wohnungen', 'field' => 'room_count', 'rooms_group_from' => 4,
    'selection' => 'single', 'optionStyle' => 'primary', 'optionOutline' => true, 'optionSize' => 'sm')));
$html2 = $render($b2);
check('rooms: grouped option values', (preg_match_all('/data-value="([^"]*)"/', $html2, $m2) ? $m2[1] : null), array('2', '2.5', '3', '4+'));
check('single selection in config', json_decode($attr($html2, 'data-immoadmin-filter-config'), true)['multiple'], false);
check('no label → aria-label with field name', strpos($html2, 'aria-label="Zimmer"') !== false && strpos($html2, 'immoadmin-filter-label') === false, true);
check('native presets → bricks-button sm outline bricks-color-primary', strpos($html2, 'class="immoadmin-filter-option bricks-button sm outline bricks-color-primary"') !== false, true);
$b3 = new ImmoAdmin_Filter_Buttons(array('id' => 'fb3', 'settings' => array('filter_group' => 'wohnungen', 'field' => 'floor',
    'custom_options' => array(array('value' => '-10', 'label' => 'GG'), array('value' => '0', 'label' => 'EG'), array('value' => '1|2', 'label' => 'OG'), array('value' => '99', 'label' => 'DG')))));
$html3 = $render($b3);
check('floor with own options GG EG OG DG', (preg_match_all('/>([^<]+)<\/button>/', $html3, $m3) ? $m3[1] : null), array('GG', 'EG', 'OG', 'DG'));
check('floor match type', json_decode($attr($html3, 'data-immoadmin-filter-config'), true)['match'], 'floor');
$nog = new ImmoAdmin_Filter_Buttons(array('id' => 'fb4', 'settings' => array('filter_group' => '', 'field' => 'floor')));
$nogh = $render($nog);
check('v2.15: no group → renders (acts on all tables), no group/targets attrs', array($attr($nogh, 'data-immoadmin-filter'), strpos($nogh, 'data-immoadmin-filter-group') === false, strpos($nogh, 'data-immoadmin-filter-targets') === false), array('buttons', true, true));
$tgt = new ImmoAdmin_Filter_Buttons(array('id' => 'fb4b', 'settings' => array('field' => 'floor', 'filter_targets' => array('tbla', 'tblb', 'bad id"', 'tbla'))));
check('v2.15: targets attr (sanitized, unique, space-separated)', $attr($render($tgt), 'data-immoadmin-filter-targets'), 'tbla tblb');
$custom_el = new ImmoAdmin_Filter_Buttons(array('id' => 'fb5', 'settings' => array('filter_group' => 'wohnungen', 'field' => '__custom', 'field_custom' => 'floor_label')));
$render($custom_el);
check('custom field registers its key for later tables', $FD::registered_keys('wohnungen'), array('floor_label'));
$FD::reset_registered_keys();
$FD::set_rows_for_tests(array());
check('no data, frontend → no output', $render(new ImmoAdmin_Filter_Buttons(array('id' => 'fb6', 'settings' => array('filter_group' => 'wohnungen')))), '');
$FD::set_rows_for_tests($rows);
$GLOBALS['__is_builder'] = true;
$prev = new ImmoAdmin_Filter_Buttons(array('id' => 'fb7', 'settings' => array('filter_group' => 'wohnungen', 'builder_preview_active' => true)));
$htmlp = $render($prev);
check('builder preview: first option active + data-builder', substr_count($htmlp, 'aria-pressed="true"') === 1 && strpos($htmlp, 'data-builder="1"') !== false, true);
$GLOBALS['__is_builder'] = false;

section('element: Filter-Bereich');
$cfg = ImmoAdmin_Filter_Range::config_for(array('field' => 'purchase_price'), $rows);
check('price config: public bounds, step, k-format, include empty (auto)', $cfg, array(
    'key' => 'purchase_price', 'min' => 280000.0, 'max' => 760000.0, 'step' => 10000.0,
    'format' => array('mode' => 'k', 'decimals' => 0, 'prefix' => '', 'suffix' => ''), 'includeEmpty' => true));
$cfg = ImmoAdmin_Filter_Range::config_for(array('field' => 'living_area'), $rows);
check('area config: m², no empty by default', array($cfg['min'], $cfg['max'], $cfg['step'], $cfg['format']['suffix'], $cfg['includeEmpty']), array(70.0, 154.0, 1.0, ' m²', false));
$cfg = ImmoAdmin_Filter_Range::config_for(array('field' => 'living_area', 'empty_handling' => 'show', 'value_format' => 'plain', 'suffix' => ' qm', 'decimals' => '1', 'prefix' => 'ca. '), $rows);
check('overrides: empty show, format, suffix, prefix, decimals', array($cfg['includeEmpty'], $cfg['format']), array(true, array('mode' => 'plain', 'decimals' => 1, 'prefix' => 'ca. ', 'suffix' => ' qm')));
check('price "hide" override', ImmoAdmin_Filter_Range::config_for(array('field' => 'purchase_price', 'empty_handling' => 'hide'), $rows)['includeEmpty'], false);
check('custom numeric key keeps 0 as value', ImmoAdmin_Filter_Range::config_for(array('field' => '__custom', 'field_custom' => 'room_count'), $rows)['min'], 0.0);
check('custom key missing → null', ImmoAdmin_Filter_Range::config_for(array('field' => '__custom'), $rows), null);
check('unknown field falls back to Wohnfläche', ImmoAdmin_Filter_Range::config_for(array('field' => 'evil'), $rows)['key'], 'living_area');
$r = new ImmoAdmin_Filter_Range(array('id' => 'fr1', 'settings' => array('filter_group' => 'wohnungen', 'field' => 'purchase_price', 'label' => 'Preis')));
$r->set_control_groups(); $r->set_controls();
check('range style: thumb border on both engines', array_column($r->controls['sliderThumbBorder']['css'], 'selector'), array(
    '.double-slider-wrap input[type="range"]::-webkit-slider-thumb', '.double-slider-wrap input[type="range"]::-moz-range-thumb'));
check('range style: active bar color is a CSS variable', $r->controls['sliderBarColorActive']['css'][0]['property'], '--iaf-bar-active-color');
$rh = $render($r);
check('two native range inputs', substr_count($rh, '<input type="range"'), 2);
check('min/max/step on inputs', strpos($rh, 'min="280000" max="760000" step="10000"') !== false, true);
check('SSR value labels "280k" … "760k"', strpos($rh, '<span class="value">280k</span>') !== false && strpos($rh, '<span class="value">760k</span>') !== false, true);
check('accessible names + valuetext', strpos($rh, 'aria-label="Preis Minimum" aria-valuetext="280k"') !== false && strpos($rh, 'aria-label="Preis Maximum" aria-valuetext="760k"') !== false, true);
check('reserved/sold prices not in range markup', strpos($rh, '990') === false && strpos($rh, '1500000') === false, true);
check('native class structure', strpos($rh, 'class="double-slider-wrap"') !== false && strpos($rh, 'class="slider-track"') !== false && strpos($rh, 'class="value-wrap"') !== false, true);
$ra = new ImmoAdmin_Filter_Range(array('id' => 'fr2', 'settings' => array('filter_group' => 'wohnungen', 'field' => 'living_area')));
$rah = $render($ra);
check('area labels "70 m²" … "154 m²"', strpos($rah, '>70 m²<') !== false && strpos($rah, '>154 m²<') !== false, true);
check('no label → aria-label on group', strpos($rah, 'role="group" aria-label="Wohnfläche"') !== false, true);

section('element: Filter-Bereich — saved elements without "Wert-Position" are byte-identical to v2.15.0');
require_once __DIR__ . '/fixtures/filter-range-cases.php';
$range_baseline_file = __DIR__ . '/fixtures/filter-range-baseline.json';
$range_rendered = array();
foreach (immoadmin_test_range_cases() as $case => $case_settings) {
    $range_rendered[$case] = $render(new ImmoAdmin_Filter_Range(array('id' => 'frb' . count($range_rendered), 'settings' => $case_settings)));
}
if (in_array('--write-range-baseline', $argv ?? array(), true)) {
    file_put_contents($range_baseline_file, json_encode($range_rendered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "  wrote range baseline ({$range_baseline_file})\n";
}
$range_baseline = json_decode((string) @file_get_contents($range_baseline_file), true);
check('range baseline file present', is_array($range_baseline) && count($range_baseline) === count($range_rendered), true);
foreach ($range_rendered as $case => $html) {
    check("{$case}: identical", $html, $range_baseline[$case] ?? null);
}
check('sanity: baseline has fixed value-wrap', strpos($range_baseline['area-default'] ?? '', '<div class="value-wrap" aria-hidden="true">') !== false, true);
foreach (immoadmin_test_range_cases() as $case => $case_settings) {
    $fixed_html = $render(new ImmoAdmin_Filter_Range(array('id' => 'frx', 'settings' => $case_settings + array('value_position' => 'fixed'))));
    $legacy_html = $render(new ImmoAdmin_Filter_Range(array('id' => 'frx', 'settings' => $case_settings)));
    check("{$case}: explicit 'fixed' = absent (legacy)", $fixed_html, $legacy_html);
}

section('element: Filter-Bereich — Wert-Position (v2.15.1)');
$rp = new ImmoAdmin_Filter_Range(array('id' => 'frp', 'settings' => array()));
$rp->set_control_groups(); $rp->set_controls();
$vp = $rp->controls['value_position'];
check('control: select in "Werte-Anzeige" with both modes (German labels)', array($vp['type'], $vp['group'], $vp['label'], $vp['options']), array('select', 'values', 'Wert-Position',
    array('follow' => 'Unter den Griffen (mitlaufend)', 'fixed' => 'Links/Rechts fest')));
check('control: default "follow" (Bricks copies it into NEW elements on add)', $vp['default'], 'follow');
check('control: placeholder shows the legacy state of saved elements, not clearable', array($vp['placeholder'], $vp['clearable']), array('Links/Rechts fest', false));
check('control: first in "Werte-Anzeige", before the typography', array_slice(array_keys(array_filter($rp->controls, function ($c) { return ($c['group'] ?? '') === 'values'; })), 0, 2), array('value_position', 'valueTypography'));
check('spacing control unchanged (margin-top on .value-wrap, both modes)', $rp->controls['valueSpacing']['css'], array(array('property' => 'margin-top', 'selector' => '.value-wrap')));
check('value typography still targets .value-wrap .value (moving labels too)', $rp->controls['valueTypography']['css'][0]['selector'], '.value-wrap .value');
check('resolver: absent / empty / junk / fixed → fixed', array(
    ImmoAdmin_Filter_Range::value_position(array()), ImmoAdmin_Filter_Range::value_position(array('value_position' => '')),
    ImmoAdmin_Filter_Range::value_position(array('value_position' => 'evil')), ImmoAdmin_Filter_Range::value_position(array('value_position' => 'fixed')),
    ImmoAdmin_Filter_Range::value_position(null)), array('fixed', 'fixed', 'fixed', 'fixed', 'fixed'));
check('resolver: follow', ImmoAdmin_Filter_Range::value_position(array('value_position' => 'follow')), 'follow');
// A new element = settings as Bricks stores them on add: every control default.
$new_settings = array();
foreach ($rp->controls as $k => $c) {
    if (!empty($c['default'])) { $new_settings[$k] = $c['default']; }
}
check('new element (defaults copied on add) → follow', ImmoAdmin_Filter_Range::value_position($new_settings), 'follow');
$fh = $render(new ImmoAdmin_Filter_Range(array('id' => 'frf', 'settings' => array('field' => 'living_area', 'value_position' => 'follow'))));
check('follow: marked value-wrap, still aria-hidden', strpos($fh, '<div class="value-wrap" data-value-position="follow" aria-hidden="true"><span class="lower"><span class="value">70 m²</span></span><span class="upper"><span class="value">154 m²</span></span><span class="merged"><span class="value">70 m²</span><span class="value sep">–</span><span class="value">154 m²</span></span></div>') !== false, true);
check('follow: inputs + aria unchanged', strpos($fh, 'aria-label="Wohnfläche Minimum" aria-valuetext="70 m²"') !== false && strpos($fh, 'aria-label="Wohnfläche Maximum" aria-valuetext="154 m²"') !== false, true);
$fh_legacy = $render(new ImmoAdmin_Filter_Range(array('id' => 'frf', 'settings' => array('field' => 'living_area'))));
check('follow differs from legacy ONLY in the value-wrap', preg_replace('/<div class="value-wrap".*$/s', '', $fh), preg_replace('/<div class="value-wrap".*$/s', '', $fh_legacy));
$fvb = $render(new ImmoAdmin_Filter_Range(array('id' => 'frv', 'settings' => array('field' => 'living_area', 'value_position' => 'follow', 'labelMin' => 'von', 'labelMax' => 'bis <b>'))));
check('follow: von/bis kept + escaped, merged uses "bis" as connector', strpos($fvb, '<span class="lower"><span class="label">von</span><span class="value">70 m²</span></span><span class="upper"><span class="label">bis &lt;b&gt;</span><span class="value">154 m²</span></span><span class="merged"><span class="label">von</span><span class="value">70 m²</span><span class="label">bis &lt;b&gt;</span><span class="value">154 m²</span></span>') !== false, true);
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/merged-parts-cases.json'), true) as $c) {
    check('merged ' . json_encode($c['args'], JSON_UNESCAPED_UNICODE), call_user_func_array(array('ImmoAdmin_Filter_Range', 'merged_parts'), $c['args']), $c['expected']);
}

section('element: Filter-Aktionen');
$a = new ImmoAdmin_Filter_Actions(array('id' => 'fa1', 'settings' => array('filter_group' => 'wohnungen', 'submit_text' => 'Suchen',
    'submitIcon' => array('library' => 'themify', 'icon' => 'ti-arrow-right'), 'reset_text' => 'Filter zurücksetzen', 'submitStyle' => 'primary', 'resetStyle' => 'primary', 'resetOutline' => true)));
$a->set_control_groups(); $a->set_controls();
check('submit default style primary (native)', $a->controls['submitStyle']['default'], 'primary');
check('reset default outline', $a->controls['resetOutline']['default'], true);
check('v2.15.2: no "Filter anwenden" control any more', isset($a->controls['apply_on']), false);
check('v2.15.2: no "Filter-Gruppe" control', isset($a->controls['filter_group']), false);
check('v2.15.2: Suchen toggle explains the apply mode', $a->controls['hide_submit']['description'] ?? '', '„Suchen“ sichtbar = Filter greifen erst beim Klick auf „Suchen“. Ausgeblendet = Filter greifen sofort.');
$ah = $render($a);
check('Suchen shown → apply on click', $attr($ah, 'data-apply-on'), 'click');
check('submit button: native classes + text + icon right', strpos($ah, '<button type="button" class="immoadmin-filter-submit bricks-button bricks-background-primary" data-immoadmin-filter-action="submit"><span class="text">Suchen</span><i class="icon ti-arrow-right"></i></button>') !== false, true);
check('reset button outline', strpos($ah, 'class="immoadmin-filter-reset bricks-button outline bricks-color-primary" data-immoadmin-filter-action="reset"') !== false, true);
$a2 = new ImmoAdmin_Filter_Actions(array('id' => 'fa2', 'settings' => array('filter_group' => 'wohnungen', 'apply_on' => 'change', 'hide_submit' => true, 'reset_hide_inactive' => true,
    'resetIcon' => array('icon' => 'ti-close'), 'resetIconPosition' => 'left')));
$ah2 = $render($a2);
check('Suchen hidden → instant mode', $attr($ah2, 'data-apply-on'), 'change');
check('submit hidden', strpos($ah2, 'immoadmin-filter-submit') === false, true);
check('reset hidden-when-inactive flag + class, icon left', strpos($ah2, 'immoadmin-no-active-filter" data-immoadmin-filter-action="reset" data-hide-inactive="1"><i class="icon ti-close"></i><span class="text">Filter zurücksetzen</span>') !== false, true);
$a3 = new ImmoAdmin_Filter_Actions(array('id' => 'fa3', 'settings' => array('filter_group' => 'wohnungen', 'hide_submit' => true, 'hide_reset' => true)));
check('both hidden → empty element still carries the (instant) mode', strpos($render($a3), 'data-apply-on="change"') !== false, true);
// v2.15.2: the Suchen button alone decides; a stored apply_on is ignored.
foreach (array(
    array(array(), 'click', 'no stored apply, Suchen shown'),
    array(array('hide_submit' => true), 'change', 'no stored apply, Suchen hidden'),
    array(array('apply_on' => 'click'), 'click', 'stored click, Suchen shown'),
    array(array('apply_on' => 'click', 'hide_submit' => true), 'change', 'stored click, Suchen hidden (was unusable) → instant'),
    array(array('apply_on' => 'change'), 'click', 'stored instant, Suchen shown → click'),
    array(array('apply_on' => 'change', 'hide_submit' => true), 'change', 'stored instant, Suchen hidden'),
) as $i => $c) {
    check('apply mode: ' . $c[2], array(ImmoAdmin_Filter_Actions::apply_mode($c[0]), $attr($render(new ImmoAdmin_Filter_Actions(array('id' => 'fam' . $i, 'settings' => $c[0]))), 'data-apply-on')), array($c[1], $c[1]));
}

section('enqueue');
$GLOBALS['__enqueued'] = array();
$a->enqueue_scripts();
check('filter element enqueues css + logic + controller', $GLOBALS['__enqueued'], array('style:immoadmin-filters', 'script:immoadmin-filter-logic', 'script:immoadmin-filters'));
$GLOBALS['__enqueued'] = array();
(new ImmoAdmin_Units_Table(array('id' => 't1', 'settings' => array())))->enqueue_scripts();
check('table without group: only its own assets (as before)', $GLOBALS['__enqueued'], array('style:immoadmin-units-table', 'script:immoadmin-units-table'));
$GLOBALS['__enqueued'] = array();
(new ImmoAdmin_Units_Table(array('id' => 't1', 'settings' => array('immoadmin_filter_group' => 'wohnungen'))))->enqueue_scripts();
check('table with group: + filter assets', count($GLOBALS['__enqueued']), 5);

section('units-table: filter controls are opt-in');
$tc = new ImmoAdmin_Units_Table(array('id' => 'tc1', 'settings' => array()));
$tc->set_control_groups(); $tc->set_controls();
check('v2.15.2: units-table has no "Filter-Gruppe" control any more', isset($tc->controls['immoadmin_filter_group']), false);
$new_ctrls = array('immoadmin_filter_empty', 'immoadmin_filter_empty_text', 'immoadmin_filter_hide_with', 'immoadmin_filter_hide_selector', 'immoadmin_filter_extra_keys');
foreach ($new_ctrls as $k) {
    check("{$k}: exists, no default", isset($tc->controls[$k]) && !array_key_exists('default', $tc->controls[$k]), true);
}
check('filter control group in content tab', $tc->control_groups['filter']['tab'] ?? null, 'content');
check('existing control groups keep their order', array_keys($tc->control_groups), array('query', 'columns', 'behavior', 'filter', 'table_style'));
check('group from settings: absent', ImmoAdmin_Units_Table::filter_group_from_settings(array()), '');
check('group from settings: sanitized', ImmoAdmin_Units_Table::filter_group_from_settings(array('immoadmin_filter_group' => ' Wohnungen')), 'wohnungen');

section('units-table: with Filter-Gruppe');
$fsettings = immoadmin_test_table_cases()['accordion-preset'];
$fsettings['immoadmin_filter_group'] = 'Wohnungen';
$fsettings['immoadmin_filter_empty'] = 'message';
$fsettings['immoadmin_filter_empty_text'] = 'Nichts <b>gefunden</b>';
$fsettings['immoadmin_filter_hide_with'] = 'custom';
$fsettings['immoadmin_filter_hide_selector'] = '.haus';
$fsettings['immoadmin_filter_extra_keys'] = 'garden_area_formatted, parking_price';
$fixture_ids = immoadmin_test_table_fixture_posts();
$GLOBALS['__meta'][701]['parking_price'] = '25000';
$GLOBALS['__meta'][702]['parking_price'] = '26000';
$fh = immoadmin_test_render_table($fsettings, 'tbl777', $fixture_ids);
check('root: group attr', $attr($fh, 'data-immoadmin-filter-group'), 'wohnungen');
check('root: empty mode + escaped text', array($attr($fh, 'data-immoadmin-filter-empty'), strpos($fh, 'data-immoadmin-filter-empty-text="Nichts &lt;b&gt;gefunden&lt;/b&gt;"') !== false), array('message', true));
check('root: hide wrapper selector', array($attr($fh, 'data-immoadmin-filter-hide'), $attr($fh, 'data-immoadmin-filter-hide-selector')), array('custom', '.haus'));
preg_match_all('/data-unit-id="(\d+)"[^>]*data-immoadmin-filter-values="([^"]*)"/', $fh, $fm);
$rowvals = array();
foreach ($fm[1] as $i => $pid) { $rowvals[$pid] = json_decode(html_entity_decode($fm[2][$i], ENT_QUOTES), true); }
check('every rendered unit carries values (all 4 statuses shown)', array_keys($rowvals), array(701, 702, 703, 704));
check('available unit: full values', $rowvals['701'], array(
    'building_name' => 'Presto', 'floor' => -10, 'floor_to' => 0, 'orientation' => array('south', 'west'), 'room_count' => 3, 'living_area' => 79.9,
    'usable_area' => null, 'purchase_price' => 439800, 'rent_cold' => null, 'rent_warm' => null, 'price_per_sqm' => null,
    'garden_area_formatted' => '80 m²', 'parking_price' => 25000));
check('reserved unit: price + parking price null', array($rowvals['702']['purchase_price'], $rowvals['702']['parking_price']), array(null, null));
check('reserved unit: other values present', array($rowvals['702']['building_name'], $rowvals['702']['floor'], $rowvals['702']['living_area']), array('Allegro', 1, 54));
check('sold unit: price null', $rowvals['703']['purchase_price'], null);
check('unit with 0 values: null (no value)', array($rowvals['704']['room_count'], $rowvals['704']['living_area'], $rowvals['704']['purchase_price'], $rowvals['704']['floor']), array(null, null, null, 0));
check('reserved/sold prices nowhere in markup', strpos($fh, '500000') === false && strpos($fh, '500.000') === false && strpos($fh, '760000') === false && strpos($fh, '26000') === false, true);
check('accordion item carries values (not the title row)', preg_match('/<div class="accordion-item immoadmin-table-rowgroup" role="rowgroup" data-unit-id="701"[^>]*data-immoadmin-filter-values=/', $fh), 1);
check('restricted bare row carries values', preg_match('/<div class="immoadmin-table-row[^"]*is-reserved"[^>]*data-unit-id="702"[^>]*data-immoadmin-filter-values=/', $fh), 1);
$stripped = preg_replace('/ data-immoadmin-filter-(values|group|empty|empty-text|hide|hide-selector)="[^"]*"/', '', $fh);
check('with group: identical to baseline apart from the new data attributes', $stripped, $baseline['accordion-preset']);
$GLOBALS['__is_builder'] = false;
$FD::register_custom_key('wohnungen', 'balcony_area');
$fh2 = immoadmin_test_render_table(array_merge(immoadmin_test_table_cases()['table-legacy-dim'], array('immoadmin_filter_group' => 'wohnungen')));
check('table mode: rows carry values incl. key registered by an earlier filter', substr_count($fh2, '&quot;balcony_area&quot;:null'), 4);
check('defaults: no empty/hide attrs', strpos($fh2, 'data-immoadmin-filter-empty') === false && strpos($fh2, 'data-immoadmin-filter-hide') === false, true);
$FD::reset_registered_keys();
$fh3 = immoadmin_test_render_table(array_merge(immoadmin_test_table_cases()['table-legacy-dim'], array('immoadmin_filter_group' => 'wohnungen', 'immoadmin_filter_hide_with' => 'custom')));
check('custom hide without selector → no hide attr', strpos($fh3, 'data-immoadmin-filter-hide') === false, true);
$GLOBALS['__meta'][701]['status'] = 'available';
unset($GLOBALS['__meta'][701]['parking_price'], $GLOBALS['__meta'][702]['parking_price']);
$FD::set_rows_for_tests(null);

// ================================================================ v2.15.0
// Ziel-Tabellen: targeting by element id, empty = all tables

section('targeting: same cases as JS (tests/fixtures/targeting-cases.json)');
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/targeting-cases.json'), true) as $c) {
    $present = $FD::groups_present($c['page']);
    $got = array();
    foreach ($c['page'] as $t) {
        if ($FD::applies_to_table($c['spec']['targets'], $c['spec']['group'], $t['id'], $t['group'], $present)) {
            $got[] = $t['id'];
        }
    }
    check($c['name'], $got, $c['expected']);
}
check('sanitize_targets: array, unique, junk dropped', $FD::sanitize_targets(array('t1', 't1', 'bad id', '"x"', 7, array('y'), 't2')), array('t1', '7', 't2'));
check('sanitize_targets: string / null', array($FD::sanitize_targets('t1, t2 t3'), $FD::sanitize_targets(null)), array(array('t1', 't2', 't3'), array()));
check('id_matches: component instance yes, longer id no', array($FD::id_matches('abc123-x1', 'abc123'), $FD::id_matches('abc1234', 'abc123')), array(true, false));

section('targeting: dropdown labels');
check('no label → "Units Table #id"', $FD::table_label(array('id' => 'abc123', 'name' => 'immoadmin-units-table')), 'Units Table #abc123');
check('Gebäude filter value shown', $FD::table_label(array('id' => 'abc123', 'settings' => array('immoadmin_buildings' => array('Presto')))), 'Units Table · Presto #abc123');
check('Bricks custom label wins, several Gebäude', $FD::table_label(array('id' => 'k9', 'label' => 'Haus <b>A</b> ', 'settings' => array('immoadmin_buildings' => array('Presto', 'Largo')))), 'Haus A · Presto, Largo #k9');
check('from a template / component', $FD::table_label(array('id' => 'k9'), 'Template'), 'Units Table #k9 (Template)');

section('targeting: page scan (templates, components)');
$page_content = array(
    array('id' => 'sec1', 'name' => 'section', 'parent' => 0),
    array('id' => 'fall', 'name' => 'immoadmin-filter-buttons', 'settings' => array('field' => 'building_name')),
    array('id' => 'ftwo', 'name' => 'immoadmin-filter-range', 'settings' => array('field' => '__custom', 'field_custom' => 'balcony_area', 'filter_targets' => array('tblb', 'tblc'))),
    array('id' => 'fact', 'name' => 'immoadmin-filter-actions', 'settings' => array('filter_targets' => array('tbla'))),
    array('id' => 'tbla', 'name' => 'immoadmin-units-table', 'label' => 'Presto-Tabelle', 'settings' => array()),
    array('id' => 'tblb', 'name' => 'immoadmin-units-table', 'settings' => array('immoadmin_buildings' => array('Largo'))),
    array('id' => 'tpl1', 'name' => 'template', 'settings' => array('template' => 55)),
    array('id' => 'cmp1', 'name' => 'div', 'cid' => 'comp9'),
    array('id' => 'tpl2', 'name' => 'template', 'settings' => array('template' => 55)), // same template twice → scanned once
);
$resolver_calls = array();
$resolver = function ($type, $id) use (&$resolver_calls) {
    $resolver_calls[] = $type . ':' . $id;
    if ($type === 'template' && $id === 55) {
        return array(array('id' => 'tblc', 'name' => 'immoadmin-units-table', 'settings' => array('immoadmin_filter_group' => 'Wohnungen')),
            array('id' => 'tpl3', 'name' => 'template', 'settings' => array('template' => 55))); // self-reference
    }
    if ($type === 'component' && $id === 'comp9') {
        return array(array('id' => 'tbld', 'name' => 'immoadmin-units-table', 'settings' => array()));
    }
    return array();
};
$idx = $FD::index_elements(array($page_content, 'not-a-list'), $resolver);
check('tables found incl. template + component', array_keys($idx['tables']), array('tbla', 'tblb', 'tblc', 'tbld'));
check('each template / component resolved once', $resolver_calls, array('template:55', 'component:comp9'));
check('table group sanitized', $idx['tables']['tblc']['group'], 'wohnungen');
check('filters with targets, group, custom key', array($idx['filters']['fall']['targets'], $idx['filters']['ftwo']['targets'], $idx['filters']['ftwo']['key'], $idx['filters']['fact']['name']),
    array(array(), array('tblb', 'tblc'), 'balcony_area', 'immoadmin-filter-actions'));
$opts = $FD::target_options_from_index($idx);
check('dropdown options (labels)', $opts['options'], array('tbla' => 'Presto-Tabelle #tbla', 'tblb' => 'Units Table · Largo #tblb',
    'tblc' => 'Units Table #tblc (Template)', 'tbld' => 'Units Table #tbld (Komponente)'));
check('template/component tables flagged for the live builder list', $opts['external'], array('tblc', 'tbld'));
check('participation: all-mode filter reaches every table', $FD::table_participation($idx, 'tbla', ''), array('active' => true, 'keys' => array()));
check('participation: targeted filter adds its custom key', $FD::table_participation($idx, 'tblb', ''), array('active' => true, 'keys' => array('balcony_area')));
$only_targeted = array('tables' => $idx['tables'], 'filters' => array('ftwo' => $idx['filters']['ftwo'], 'fact' => $idx['filters']['fact']));
check('participation: not targeted → inactive (actions alone never count)', $FD::table_participation($only_targeted, 'tbla', ''), array('active' => false, 'keys' => array()));
check('participation: component instance id', $FD::table_participation($only_targeted, 'tblc-inst1', '')['active'], true);
check('no filters on the page → nothing', $FD::table_participation(array('tables' => $idx['tables'], 'filters' => array()), 'tbla', ''), array('active' => false, 'keys' => array()));
check('no Bricks → empty element lists', $FD::bricks_element_lists(null), array());
check('builder options outside the builder → empty', $FD::builder_target_options(), array('options' => array(), 'external' => array()));

section('units-table: v2.15.0 targeting output');
$base_cases = immoadmin_test_table_cases();
$FD::set_rows_for_tests($rows);
$FD::set_page_index_for_tests(array('tables' => array('tbl777' => array('id' => 'tbl777', 'group' => '')), 'filters' => array()));
check('page with tables but no filter → byte-identical', immoadmin_test_render_table($base_cases['accordion-preset']), $baseline['accordion-preset']);
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array('fx' => array('id' => 'fx', 'name' => 'immoadmin-filter-actions', 'targets' => array(), 'group' => '', 'key' => ''))));
check('only a Filter-Aktionen element → byte-identical', immoadmin_test_render_table($base_cases['accordion-preset']), $baseline['accordion-preset']);
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array('fx' => array('id' => 'fx', 'name' => 'immoadmin-filter-buttons', 'targets' => array('other1'), 'group' => '', 'key' => ''))));
check('filter targeting another table → byte-identical', immoadmin_test_render_table($base_cases['accordion-preset']), $baseline['accordion-preset']);

$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array('fx' => array('id' => 'fx', 'name' => 'immoadmin-filter-buttons', 'targets' => array(), 'group' => '', 'key' => ''))));
$zc = immoadmin_test_render_table($base_cases['accordion-preset']);
check('zero-config filter → table marked filterable (no group attr)', array($attr($zc, 'data-immoadmin-filterable'), strpos($zc, 'data-immoadmin-filter-group') === false), array('1', true));
check('zero-config: every unit carries values, prices of reserved/sold redacted', array(substr_count($zc, 'data-immoadmin-filter-values='), strpos($zc, '500000') === false && strpos($zc, '760000') === false), array(4, true));
check('zero-config: identical to baseline apart from the filter attributes', preg_replace('/ data-immoadmin-filter(able|-values)="[^"]*"/', '', $zc), $baseline['accordion-preset']);
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array()));
$FD::register_filter('flate', 'immoadmin-filter-buttons', array('tbl777'), '', 'balcony_area');
$late = immoadmin_test_render_table(array_merge($base_cases['table-legacy-dim'], array('immoadmin_filter_empty' => 'message', 'immoadmin_filter_hide_with' => 'parent')));
check('filter announced while rendering (scan missed it) → table joins, custom key on rows', array($attr($late, 'data-immoadmin-filterable'), substr_count($late, '&quot;balcony_area&quot;:null')), array('1', 4));
check('ungrouped targeted table honours "Keine Treffer" / wrapper settings', array($attr($late, 'data-immoadmin-filter-empty'), $attr($late, 'data-immoadmin-filter-hide')), array('message', 'parent'));
$FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array('fx' => array('id' => 'fx', 'name' => 'immoadmin-filter-buttons', 'targets' => array(), 'group' => 'wohnungen', 'key' => 'balcony_area'))));
$grp = immoadmin_test_render_table(array_merge($base_cases['accordion-preset'], array('immoadmin_filter_group' => 'wohnungen')));
check('v2.14.0 grouped table: group attr, no filterable attr', array($attr($grp, 'data-immoadmin-filter-group'), strpos($grp, 'data-immoadmin-filterable') === false), array('wohnungen', true));
check('v2.14.0 grouped table: custom key of a filter BELOW it now found too', strpos($grp, '&quot;balcony_area&quot;') !== false, true);
$GLOBALS['__is_builder'] = true;
$FD::reset_registered_keys();
$bld = immoadmin_test_render_table($base_cases['accordion-preset']);
check('builder: no scan, ungrouped table untouched', strpos($bld, 'data-immoadmin-filter') === false, true);
$GLOBALS['__is_builder'] = false;
$FD::reset_registered_keys();
$FD::set_rows_for_tests(null);

section('elements: v2.15.0 targeting attributes');
$FD::set_rows_for_tests($rows);
$z = $render(new ImmoAdmin_Filter_Range(array('id' => 'frz', 'name' => 'immoadmin-filter-range', 'settings' => array('field' => 'living_area'))));
check('range without config: renders, no group/targets attrs', array($attr($z, 'data-immoadmin-filter'), strpos($z, 'data-immoadmin-filter-group') === false, strpos($z, 'data-immoadmin-filter-targets') === false), array('range', true, true));
$ia = $FD::page_index();
check('rendered filters announce themselves for later tables', isset($ia['filters']['frz']) && $ia['filters']['frz']['targets'] === array(), true);
$az = $render(new ImmoAdmin_Filter_Actions(array('id' => 'faz', 'settings' => array('filter_targets' => array('tbla')))));
check('actions: targets attr + still renders without group', array($attr($az, 'data-immoadmin-filter-targets'), $attr($az, 'data-apply-on')), array('tbla', 'click'));
$aold = $render(new ImmoAdmin_Filter_Actions(array('id' => 'fao', 'settings' => array('filter_group' => 'wohnungen'))));
check('v2.14.0 saved actions element: unchanged group attr', $attr($aold, 'data-immoadmin-filter-group'), 'wohnungen');
$ac = new ImmoAdmin_Filter_Actions(array('id' => 'fac', 'settings' => array()));
$ac->set_control_groups(); $ac->set_controls();
foreach (array('ImmoAdmin_Filter_Buttons', 'ImmoAdmin_Filter_Range', 'ImmoAdmin_Filter_Actions') as $cls) {
    $el = new $cls(array('id' => 'nog', 'settings' => array()));
    $el->set_control_groups(); $el->set_controls();
    $hay = json_encode($el->controls, JSON_UNESCAPED_UNICODE);
    check("v2.15.2: {$cls} panel without Filter-Gruppe", array(isset($el->controls['filter_group']), strpos($hay, 'Filter-Gruppe') === false), array(false, true));
}
$tcg = new ImmoAdmin_Units_Table(array('id' => 'tng', 'settings' => array()));
$tcg->set_control_groups(); $tcg->set_controls();
check('v2.15.2: units-table panel without Filter-Gruppe, Keine Treffer / mit ausblenden stay', array(strpos(json_encode($tcg->controls, JSON_UNESCAPED_UNICODE), 'Filter-Gruppe') === false, isset($tcg->controls['immoadmin_filter_empty']), isset($tcg->controls['immoadmin_filter_hide_with'])), array(true, true, true));
// Legacy stored groups (v2.14 default "wohnungen"): honoured only while a table on the page carries it.
$tabs = array(array('id' => 'ta', 'group' => ''), array('id' => 'tb', 'group' => ''));
check('v2.15.2: orphan stored group → all tables', array(array_map(function ($t) use ($FD, $tabs) { return $FD::applies_to_table(array(), 'wohnungen', $t['id'], $t['group'], array()); }, $tabs)), array(array(true, true)));
$present = array('haus-a' => true, 'haus-b' => true);
$ltabs = array(array('ta', 'haus-a'), array('tb', 'haus-b'), array('tc', ''));
check('v2.15.2: matching legacy groups keep separate setups (2 groups, 1 page)', array(
    array_map(function ($t) use ($FD, $present) { return $FD::applies_to_table(array(), 'haus-a', $t[0], $t[1], $present); }, $ltabs),
    array_map(function ($t) use ($FD, $present) { return $FD::applies_to_table(array(), 'haus-b', $t[0], $t[1], $present); }, $ltabs),
    array_map(function ($t) use ($FD, $present) { return $FD::applies_to_table(array(), '', $t[0], $t[1], $present); }, $ltabs),
), array(array(true, false, false), array(false, true, false), array(true, true, true)));
$lg = $render(new ImmoAdmin_Filter_Buttons(array('id' => 'flg', 'settings' => array('filter_group' => 'Wohnungen', 'field' => 'building_name'))));
check('v2.15.2: stored group still rendered for the JS (legacy)', $attr($lg, 'data-immoadmin-filter-group'), 'wohnungen');
check('actions help text explains coordination', strpos($ac->controls['filter_targets']['description'], 'Leer = alle ImmoAdmin-Tabellen auf dieser Seite') === 0 && strpos($ac->controls['filter_targets']['description'], 'mindestens eine') !== false, true);
$FD::reset_registered_keys();
$FD::set_rows_for_tests(null);

// cleanup temp dir
@unlink(IMMOADMIN_MEDIA_DIR . 'abc123-plan.png');
@rmdir(IMMOADMIN_MEDIA_DIR);
@rmdir(IMMOADMIN_DATA_DIR);

echo "\n{$GLOBALS['__count']} checks, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);
