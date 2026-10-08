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
function get_post_meta($id, $key, $single = true) {
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

require __DIR__ . '/../includes/class-unit-fields.php';
require __DIR__ . '/../includes/class-post-type.php';
require __DIR__ . '/../includes/class-sync.php';

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

// cleanup temp dir
@unlink(IMMOADMIN_MEDIA_DIR . 'abc123-plan.png');
@rmdir(IMMOADMIN_MEDIA_DIR);
@rmdir(IMMOADMIN_DATA_DIR);

echo "\n{$GLOBALS['__count']} checks, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);
