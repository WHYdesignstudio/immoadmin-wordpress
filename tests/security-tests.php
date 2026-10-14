<?php
/**
 * Security / data-integrity checks (v2.15.4 audit fixes).
 * Included by tests/run-tests.php — uses its stubs and check()/section().
 */

if (PHP_SAPI !== 'cli' || !function_exists('check')) {
    exit;
}

$GLOBALS['__deleted_posts'] = array();
$GLOBALS['__scheduled']     = array();
function wp_delete_post($id, $force = false) { $GLOBALS['__deleted_posts'][] = $id; unset($GLOBALS['__meta'][$id]); return true; }
function wp_unschedule_hook($hook) { $GLOBALS['__scheduled'] = array(); return 0; }
function wp_schedule_single_event($ts, $hook, $args = array()) { $GLOBALS['__scheduled'][] = array($ts, $hook, $args); return true; }
function spawn_cron() { return true; }
function set_time_limit_stub() {}
if (!function_exists('add_option')) {
    // WP: no-op (false) when the option already exists
    function add_option($k, $v = '', $dep = '', $autoload = 'yes') {
        if (array_key_exists($k, $GLOBALS['__options'])) { return false; }
        $GLOBALS['__options'][$k] = $v; return true;
    }
}

$S = 'ImmoAdmin_Sync';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$http_ok = function ($body, $type, $extra = array()) {
    return array('response' => array('code' => 200), 'headers' => array_merge(array('content-type' => $type, 'content-length' => (string) strlen($body)), $extra), 'body' => $body);
};

// ---------------------------------------------------------------- meta whitelist
section('security: meta-key whitelist');
foreach (array('_wp_page_template', '_thumbnail_id', '_bricks_page_content_2', '_content_hash', '_immoadmin_id', 'evil_key', '', 'Status', 'image_x', 'feature_1_foo') as $k) {
    check("rejects " . var_export($k, true), $S::is_allowed_meta_key($k), false);
}
foreach (array('status', 'purchase_price', 'floor_to', 'pool_area_formatted', 'purchase_price_formatted', 'flatfinder_url', 'sort_key',
    'images_count', 'image_12', 'floor_plan_1', 'document_3_url', 'document_3_title', 'feature_2_key', 'feature_2_label') as $k) {
    check("allows {$k}", $S::is_allowed_meta_key($k), true);
}
check('static keys contain no internal "_" key', count(array_filter($S::static_meta_keys(), function ($k) { return $k[0] === '_'; })), 0);

$evil = sync_unit(array('id' => 'u-evil', 'title' => 'Evil', 'metaFields' => array(
    'status' => 'available', '_wp_page_template' => 'x.php', '_bricks_page_content_2' => '[{"name":"code"}]',
    '_content_hash' => 'forged', 'not_a_field' => 'x', 'living_area' => 80,
)));
$em = $GLOBALS['__meta'][$evil['post_id']];
check('payload cannot write _wp_page_template / _bricks_*', array(isset($em['_wp_page_template']), isset($em['_bricks_page_content_2'])), array(false, false));
check('payload cannot forge _content_hash', $em['_content_hash'] !== 'forged', true);
check('unknown key dropped, known key written', array(isset($em['not_a_field']), $em['living_area']), array(false, 80));

// ---------------------------------------------------------------- slashing / sanitizing
section('security: slashes survive, JSON/multiline sanitizers');
$sl = sync_unit(array('id' => 'u-slash', 'title' => 'Top 1\\a', 'description' => 'C:\\pfad', 'metaFields' => array(
    'status' => 'available',
    'equipment' => "Küche\\Bad\nZeile 2 <b>fett</b>",
    'features' => '["balcony","say \\"hi\\"","back\\\\slash"]',
    'extras' => '{}',
    'link_website_url' => 'https://example.at/a%20b?x=1&y=2',
    'door_number' => '07',
)));
$sm = $GLOBALS['__meta'][$sl['post_id']];
check('title backslash kept', $GLOBALS['__posts'][$sl['post_id']]['post_title'], 'Top 1\\a');
check('description backslash kept', $GLOBALS['__posts'][$sl['post_id']]['post_content'], 'C:\\pfad');
check('multiline text: backslash + newline kept, tags stripped', $sm['equipment'], "Küche\\Bad\nZeile 2 fett");
check('features JSON stays valid JSON with escapes', json_decode($sm['features'], true), array('balcony', 'say "hi"', 'back\\slash'));
check('extras "{}" stays an object', $sm['extras'], '{}');
check('URL keeps %20 (no sanitize_text_field octet stripping)', $sm['link_website_url'], 'https://example.at/a%20b?x=1&y=2');
check('numeric string kept as-is ("07")', $sm['door_number'], '07');
check('invalid JSON → ""', $S::sanitize_meta_value('features', '{bad'), '');
check('array value not written', $S::sanitize_meta_value('status', array('x')), null);

// ---------------------------------------------------------------- stale meta
section('security: stale meta cleared when the payload omits a key');
$full = array('id' => 'u-stale', 'title' => 'Stale', 'metaFields' => array(
    'status' => 'available', 'floor' => 1, 'floor_to' => 2, 'floor_to_label' => '2. OG', 'pool_area' => 20,
    'purchase_price_formatted' => '100.000', 'feature_1_key' => 'pool', 'feature_1_label' => 'Pool',
    'feature_2_key' => 'sauna', 'feature_2_label' => 'Sauna',
));
$st = sync_unit($full);
$spid = $st['post_id'];
check('initially written', array(get_post_meta($spid, 'floor_to'), get_post_meta($spid, 'pool_area_formatted'), get_post_meta($spid, 'feature_2_label')), array(2, '20 m²', 'Sauna'));
$reduced = array('id' => 'u-stale', 'title' => 'Stale', 'metaFields' => array('floor' => 1, 'feature_1_key' => 'pool', 'feature_1_label' => 'Pool'));
sync_unit($reduced, array('u-stale' => $spid));
$sm = $GLOBALS['__meta'][$spid];
foreach (array('floor_to', 'floor_to_label', 'pool_area', 'pool_area_formatted', 'status', 'purchase_price_formatted', 'feature_2_key', 'feature_2_label') as $k) {
    check("{$k} removed", array_key_exists($k, $sm), false);
}
check('present keys kept', array($sm['floor'], $sm['feature_1_label']), array(1, 'Pool'));
check('internal meta untouched', array_key_exists('_immoadmin_id', $sm), true);
$GLOBALS['__meta'][$spid]['living_area'] = 55;
sync_unit(array('id' => 'u-stale', 'title' => 'Stale 2'), array('u-stale' => $spid));
check('unit without metaFields: nothing cleared (broken payload)', get_post_meta($spid, 'living_area'), 55);

// ---------------------------------------------------------------- hash ordering
section('security: _content_hash removed before meta changes, set at the end');
$hu = array('id' => 'u-hash', 'title' => 'Hash', 'metaFields' => array('status' => 'available', 'living_area' => 50));
$hr = sync_unit($hu);
$hpid = $hr['post_id'];
$old_hash = get_post_meta($hpid, '_content_hash');
check('hash after clean sync', $old_hash !== '', true);
$seen_hash = 'UNSET';
$GLOBALS['__http'] = function ($url, $args) use ($hpid, &$seen_hash, $http_ok, $png) {
    $seen_hash = get_post_meta($hpid, '_content_hash');
    return $http_ok($png, 'image/png');
};
$hu['metaFields']['image_1'] = 'https://api.immoadmin.test/uploads/images/1-hash.png';
$hr = sync_unit($hu, array('u-hash' => $hpid));
check('hash already gone while media downloads', $seen_hash, '');
check('new hash set after all media succeeded', get_post_meta($hpid, '_content_hash') !== '' && get_post_meta($hpid, '_content_hash') !== $old_hash, true);
check('media stored locally', get_post_meta($hpid, 'image_1'), 'https://wp.test/wp-content/immoadmin/media/1-hash.png');
check('local file has the bytes', file_get_contents(IMMOADMIN_MEDIA_DIR . '1-hash.png'), $png);
check('no temp file left behind', count(glob(IMMOADMIN_MEDIA_DIR . '.*.part') ?: array()), 0);
$GLOBALS['__http'] = null;

// ---------------------------------------------------------------- media validation (pure)
section('security: media type validation');
check('png + image/png + sniff png', $S::is_allowed_media('a.png', 'image/png', 'image/png'), true);
check('JPG upper-case ext', $S::is_allowed_media('a.JPG', 'image/jpeg', 'image/jpeg'), true);
check('pdf with charset param', $S::is_allowed_media('plan.pdf', 'application/pdf; charset=binary', 'application/pdf'), true);
check('docx as zip', $S::is_allowed_media('x.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'), true);
check('no sniffer available → header decides', $S::is_allowed_media('a.webp', 'image/webp', null), true);
check('missing Content-Type rejected', $S::is_allowed_media('a.png', '', 'image/png'), false);
check('Content-Type mismatching ext rejected', $S::is_allowed_media('a.png', 'application/pdf', 'image/png'), false);
check('PHP bytes in .png rejected', $S::is_allowed_media('a.png', 'image/png', 'text/x-php'), false);
check('HTML as pdf rejected', $S::is_allowed_media('a.pdf', 'application/pdf', 'text/html'), false);
foreach (array('a.php', 'a.phtml', 'a.svg', 'a.html', 'a.js', 'a.png.php', 'a', '.htaccess', 'a.phar') as $f) {
    check("ext {$f} rejected", $S::media_extension($f), '');
}
check('octet-stream allowed only for office docs', array($S::is_allowed_media('a.xlsx', 'application/octet-stream', 'application/zip'), $S::is_allowed_media('a.png', 'application/octet-stream', 'image/png')), array(true, false));

section('security: download_media end-to-end');
$dl = function ($url, $trusted = 'api.immoadmin.test') { return priv('ImmoAdmin_Sync', 'download_media')->invoke(null, $url, $trusted); };
$calls = $GLOBALS['__remote_calls'];
check('.php URL: no request at all', array($dl('https://api.immoadmin.test/uploads/x.php'), $GLOBALS['__remote_calls'] - $calls), array(null, 0));
check('.svg URL rejected', $dl('https://api.immoadmin.test/uploads/x.svg'), null);
$GLOBALS['__http'] = function () use ($http_ok) { return $http_ok('<?php system($_GET["c"]); ?>', 'image/png'); };
check('PHP disguised as png rejected, nothing written', array($dl('https://api.immoadmin.test/uploads/shell.png'), file_exists(IMMOADMIN_MEDIA_DIR . 'shell.png')), array(null, false));
$GLOBALS['__http'] = function () use ($http_ok, $png) { return $http_ok($png, ''); };
check('missing Content-Type rejected', $dl('https://api.immoadmin.test/uploads/noct.png'), null);
$GLOBALS['__http'] = function () use ($http_ok, $png) { return $http_ok($png, 'image/png', array('content-length' => '99999')); };
check('truncated body (Content-Length mismatch) rejected', array($dl('https://api.immoadmin.test/uploads/trunc.png'), file_exists(IMMOADMIN_MEDIA_DIR . 'trunc.png')), array(null, false));
$GLOBALS['__http'] = function () use ($http_ok, $png) { return array('response' => array('code' => 404), 'headers' => array(), 'body' => ''); };
check('HTTP 404 rejected', $dl('https://api.immoadmin.test/uploads/404.png'), null);
$GLOBALS['__http'] = function () use ($http_ok, $png) { return $http_ok($png, 'image/png'); };
$GLOBALS['__http_args'] = array();
check('valid png stored', $dl('https://api.immoadmin.test/uploads/ok.png'), 'https://wp.test/wp-content/immoadmin/media/ok.png');
check('redirects limited', $GLOBALS['__http_args'][0]['args']['redirection'] ?? null, 2);
check('trusted host: private-network allowance active during the request', $GLOBALS['__http_args'][0]['external_filter'], true);
check('allowance removed afterwards', array_key_exists('http_request_host_is_external', $GLOBALS['__active_filters']), false);
$GLOBALS['__http_args'] = array();
$dl('https://203.0.113.10/uploads/other.png', 'api.immoadmin.test');
check('literal IP (not trusted) blocked before any request', count($GLOBALS['__http_args']), 0);
$dl('http://localhost/uploads/l.png', '');
check('localhost blocked without trust', count($GLOBALS['__http_args']), 0);
file_put_contents(IMMOADMIN_MEDIA_DIR . '1700-known.png', $png);
check('already-local media served without pinned host / SSRF lookup', array($dl('https://unresolvable.invalid/uploads/images/1700-known.png', ''), count($GLOBALS['__http_args'])),
    array('https://wp.test/wp-content/immoadmin/media/1700-known.png', 0));
check('local shortcut never for disallowed extensions', $dl('https://unresolvable.invalid/uploads/x.php', ''), null);
$GLOBALS['__http'] = null;

section('security: media failure keeps hash unset');
$GLOBALS['__http'] = function () use ($http_ok) { return $http_ok('<html>', 'text/html'); };
$mf = sync_unit(array('id' => 'u-mf', 'title' => 'MF', 'metaFields' => array('status' => 'available', 'image_1' => 'https://api.immoadmin.test/uploads/bad.png')));
check('invalid media → no hash, remote URL fallback', array(get_post_meta($mf['post_id'], '_content_hash'), get_post_meta($mf['post_id'], 'image_1')),
    array('', 'https://api.immoadmin.test/uploads/bad.png'));
$GLOBALS['__http'] = null;

// ---------------------------------------------------------------- trusted host
section('security: trusted backend host');
check('parse host', $S::parse_trusted_host('https://API.ImmoAdmin.at/x'), 'api.immoadmin.at');
check('parse host:port', $S::parse_trusted_host('http://immoadmin:3000'), 'immoadmin:3000');
check('parse garbage', $S::parse_trusted_host('not a url'), '');
check('match host', $S::url_matches_trusted_host('https://api.immoadmin.at/uploads/a.png', 'api.immoadmin.at'), true);
check('match is exact (no suffix tricks)', $S::url_matches_trusted_host('https://api.immoadmin.at.evil.com/a.png', 'api.immoadmin.at'), false);
check('port must match when trusted with port', array(
    $S::url_matches_trusted_host('http://immoadmin:3000/a.png', 'immoadmin:3000'),
    $S::url_matches_trusted_host('http://immoadmin:4000/a.png', 'immoadmin:3000'),
    $S::url_matches_trusted_host('http://immoadmin/a.png', 'immoadmin:3000')), array(true, false, false));
check('empty trust matches nothing', $S::url_matches_trusted_host('https://x/a.png', ''), false);
unset($GLOBALS['__options']['immoadmin_backend_host']);
check('no pin yet → empty', $S::trusted_backend_host(), '');
check('first signed payload pins', $S::pin_backend_host('https://api.immoadmin.at'), true);
check('pinned', $S::trusted_backend_host(), 'api.immoadmin.at');
check('later different host ignored', array($S::pin_backend_host('http://10.0.0.5'), $S::trusted_backend_host()), array(false, 'api.immoadmin.at'));
$S::reset_backend_host();
check('reset (new token) clears the pin', $S::trusted_backend_host(), '');

// ---------------------------------------------------------------- REST route matcher
section('security: REST route matcher');
$W = 'ImmoAdmin_Webhook';
foreach (array('/immoadmin/v1/sync' => true, '/immoadmin/v1/status' => true, 'immoadmin/v1/verify' => true, '/immoadmin/v1' => true,
    '/wp/v2/users' => false, '/immoadmin/v10/x' => false, '/immoadmin/v1x' => false, '/immoadmin/v1/../../wp/v2/users' => false,
    '/wp/v2/users?x=/immoadmin/v1/' => false, '' => false, '/IMMOADMIN/v1/sync' => false) as $route => $exp) {
    check('route ' . var_export($route, true), $W::is_own_route($route), $exp);
}
check('path → route', $W::route_from_path('/wp-json/immoadmin/v1/sync', 'wp-json', '/'), '/immoadmin/v1/sync');
check('path in subdirectory install', $W::route_from_path('/sub/wp-json/immoadmin/v1/sync', 'wp-json', '/sub/'), '/immoadmin/v1/sync');
check('non-REST path → ""', $W::route_from_path('/blog/wp-json-x/immoadmin/v1', 'wp-json', '/'), '');
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?foo=/wp-json/immoadmin/v1/sync';
check('query string never counts', $W::is_own_route($W::current_rest_route()), false);
$_SERVER['REQUEST_URI'] = '/?rest_route=/immoadmin/v1/sync';
$_GET['rest_route'] = '/immoadmin/v1/sync';
check('?rest_route= handled', $W::is_own_route($W::current_rest_route()), true);
$_GET['rest_route'] = '/wp/v2/users';
check('?rest_route= other route', $W::is_own_route($W::current_rest_route()), false);
unset($_GET['rest_route']);
$GLOBALS['wp'] = (object) array('query_vars' => array('rest_route' => '/wp/v2/users'));
$_SERVER['REQUEST_URI'] = '/wp-json/immoadmin/v1/sync';
check('parsed query var wins over the URI', $W::is_own_route($W::current_rest_route()), false);
unset($GLOBALS['wp'], $_SERVER['REQUEST_URI']);

// ---------------------------------------------------------------- deletion pass
section('security: deletion pass guard');
check('all files ok, units present → delete allowed', $S::deletion_allowed(0, 5), true);
check('one broken file → no deletion', $S::deletion_allowed(1, 5), false);
check('no units → no deletion', $S::deletion_allowed(0, 0), false);
check('posts_to_delete: only missing IDs (string/int keys)', $S::posts_to_delete(array('a' => 1, '12' => 2, 'c' => 3), array('a', 12)), array(3));
check('unit_id guards', array($S::unit_id(array()), $S::unit_id(array('id' => ' x ')), $S::unit_id(array('id' => array(1))), $S::unit_id(array('id' => 7))), array('', 'x', '', '7'));
check('unit without id → error, no fatal', sync_unit(array('title' => 'x'))['status'], 'error');
check('unit without title → "Einheit"', $GLOBALS['__posts'][sync_unit(array('id' => 'u-notitle', 'metaFields' => array('status' => 'available')))['post_id']]['post_title'], 'Einheit');

section('security: run() — broken payload disables deletion and orphan cleanup');
$GLOBALS['__options'] = array();
$S::ensure_storage();
$pdir = $S::payload_dir();
foreach (glob($pdir . '*.json') ?: array() as $f) { unlink($f); }
file_put_contents($pdir . 'a.json', json_encode(array('_format' => 'immoadmin-sync', 'meta' => array(), 'units' => array(
    array('id' => 'r-1', 'title' => 'R1', 'metaFields' => array('status' => 'available'))))));
file_put_contents($pdir . 'b.json', '{"_format":"immoadmin-sync","units":[{"id":');
$GLOBALS['wpdb']->results = array((object) array('immoadmin_id' => 'r-1', 'post_id' => 900), (object) array('immoadmin_id' => 'gone', 'post_id' => 901));
$GLOBALS['__meta'][900] = array('_immoadmin_id' => 'r-1');
$GLOBALS['__meta'][901] = array('_immoadmin_id' => 'gone');
file_put_contents(IMMOADMIN_MEDIA_DIR . 'orphan.png', 'x');
$GLOBALS['__deleted_posts'] = array();
$rr = $S::run();
check('run reports failure', $rr['success'], false);
check('no post deleted while a payload is broken', $GLOBALS['__deleted_posts'], array());
check('orphan media kept after a sync with errors', file_exists(IMMOADMIN_MEDIA_DIR . 'orphan.png'), true);
unlink($pdir . 'b.json');
$GLOBALS['wpdb']->col_result = array('https://wp.test/wp-content/immoadmin/media/1-hash.png');
$rr = $S::run();
check('clean run deletes the missing unit only', $GLOBALS['__deleted_posts'], array(901));
check('clean run removes orphan media', file_exists(IMMOADMIN_MEDIA_DIR . 'orphan.png'), false);
check('referenced media kept', file_exists(IMMOADMIN_MEDIA_DIR . '1-hash.png'), true);
check('.htaccess in media dir survives cleanup', file_exists(IMMOADMIN_MEDIA_DIR . '.htaccess'), true);

section('security: orphan cleanup aborts on DB trouble');
$orphan = priv('ImmoAdmin_Sync', 'cleanup_orphan_media');
file_put_contents(IMMOADMIN_MEDIA_DIR . 'orphan2.png', 'x');
$GLOBALS['wpdb']->last_error = 'MySQL server has gone away';
check('query error → nothing deleted', array($orphan->invoke(null), file_exists(IMMOADMIN_MEDIA_DIR . 'orphan2.png')), array(0, true));
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->col_result = array();
$GLOBALS['wpdb']->var_result = 3;
check('no references although units exist → nothing deleted', array($orphan->invoke(null), file_exists(IMMOADMIN_MEDIA_DIR . 'orphan2.png')), array(0, true));
$GLOBALS['wpdb']->var_result = 0;
check('no units at all → orphans removed', $orphan->invoke(null) >= 1 && !file_exists(IMMOADMIN_MEDIA_DIR . 'orphan2.png'), true);
$GLOBALS['wpdb']->results = array();

// ---------------------------------------------------------------- storage
section('security: storage — private payload dir, .htaccess, migration');
$GLOBALS['__options'] = array();
file_put_contents(IMMOADMIN_DATA_DIR . 'cottage-quartett.json', json_encode(array('_format' => 'immoadmin-sync', 'units' => array())));
file_put_contents(IMMOADMIN_DATA_DIR . 'mapsvg-other.json', json_encode(array('regions' => array())));
file_put_contents(IMMOADMIN_DATA_DIR . 'broken.json', '{"_format":"immoadmin-sync",');
check('ensure_storage ok', $S::ensure_storage(), true);
$pdir = $S::payload_dir();
check('payload dir name carries a 32-char secret', (bool) preg_match('#/payload-[a-z0-9]{32}/$#', $pdir), true);
check('sync payload copied to the private dir, public path now the redacted copy', array(
    !empty(json_decode(file_get_contents(IMMOADMIN_DATA_DIR . 'cottage-quartett.json'), true)['_redacted']),
    empty(json_decode(file_get_contents($pdir . 'cottage-quartett.json'), true)['_redacted'])), array(true, true));
check('broken payload moved too (sync must see it)', file_exists($pdir . 'broken.json'), true);
check('foreign JSON left alone', file_exists(IMMOADMIN_DATA_DIR . 'mapsvg-other.json'), true);
check('payload .htaccess denies all (2.4 + 2.2)', strpos(file_get_contents($pdir . '.htaccess'), 'Require all denied') !== false && strpos(file_get_contents($pdir . '.htaccess'), 'Deny from all') !== false, true);
check('data .htaccess blocks temp files but no longer *.json (public redacted copies)', array(
    strpos(file_get_contents(IMMOADMIN_DATA_DIR . '.htaccess'), 'RewriteRule \\.(tmp|part)$ - [F,L,NC]') !== false,
    strpos(file_get_contents(IMMOADMIN_DATA_DIR . '.htaccess'), 'json') === false), array(true, true));
check('media .htaccess: no script execution, no "deny all"', array(
    strpos(file_get_contents(IMMOADMIN_MEDIA_DIR . '.htaccess'), 'php_flag engine off') !== false,
    strpos(file_get_contents(IMMOADMIN_MEDIA_DIR . '.htaccess'), 'SetHandler none') !== false,
    strpos(file_get_contents(IMMOADMIN_MEDIA_DIR . '.htaccess'), 'Require all denied') === false), array(true, true, true));
check('index.php in payload dir', file_exists($pdir . 'index.php'), true);
$mh = file_get_contents(IMMOADMIN_MEDIA_DIR . '.htaccess');
check('media .htaccess: no ExecCGI (500 under AllowOverride Options=Indexes)', stripos($mh, 'ExecCGI'), false);
check('media .htaccess: only "Options -Indexes" outside IfModule', array_values(array_filter(array_map('trim', explode("\n", preg_replace('#<IfModule.*?</IfModule>\n#s', '', $mh))), function ($l) { return $l !== '' && $l[0] !== '#'; })), array('Options -Indexes'));
check('data .htaccess: only "Options -Indexes" outside IfModule', array_values(array_filter(array_map('trim', explode("\n", preg_replace('#<IfModule.*?</IfModule>\n#s', '', file_get_contents(IMMOADMIN_DATA_DIR . '.htaccess')))), function ($l) { return $l !== '' && $l[0] !== '#'; })), array('Options -Indexes'));
unlink($pdir . 'broken.json');
$secret_before = $GLOBALS['__options']['immoadmin_payload_secret'];
$GLOBALS['__options']['immoadmin_payload_secret'] = str_repeat('b', 32);
$S::ensure_storage();
check('payloads follow a new secret', file_exists($S::payload_dir() . 'cottage-quartett.json'), true);
$GLOBALS['__options']['immoadmin_payload_secret'] = $secret_before;
$S::ensure_storage();

check('payload filename = sanitize_file_name(slug).json (same as before)', array($S::payload_filename('cottage-quartett'), $S::payload_filename(''), $S::payload_filename('../.x'), $S::payload_filename(array())),
    array('cottage-quartett.json', 'data.json', 'data.json', 'data.json'));
$written = $S::write_payload_file('{"_format":"immoadmin-sync","units":[]}', 'proj-1');
check('webhook write lands in private dir', $written, $S::payload_dir() . 'proj-1.json');
check('no temp files left', count(glob($S::payload_dir() . '.*.tmp') ?: array()), 0);

section('security: payload secret shared by concurrent first requests');
$GLOBALS['__options'] = array();
$GLOBALS['__options']['immoadmin_payload_secret'] = str_repeat('c', 32); // other request won the race
check('existing secret is kept', $S::payload_dir(), IMMOADMIN_DATA_DIR . 'payload-' . str_repeat('c', 32) . '/');
$GLOBALS['__options'] = array();
$d1 = $S::payload_dir();
check('first secret stored and reused', array($S::payload_dir(), (bool) preg_match('/^[a-z0-9]{32}$/', get_option('immoadmin_payload_secret'))), array($d1, true));
$GLOBALS['__options']['immoadmin_payload_secret'] = 'INVALID';
check('invalid secret replaced', (bool) preg_match('#payload-[a-z0-9]{32}/$#', $S::payload_dir()) && get_option('immoadmin_payload_secret') !== 'INVALID', true);

$GLOBALS['__options'] = array();
$S::maybe_migrate_storage();
check('storage migration marks its version', get_option('immoadmin_storage_version'), $S::STORAGE_VERSION);

// ---------------------------------------------------------------- lock / status
section('security: sync lock + stale status');
$GLOBALS['__options'] = array();
check('first lock acquired', $S::acquire_lock(), true);
check('second lock refused (no overlap)', $S::acquire_lock(), false);
$GLOBALS['__options'][$S::LOCK_OPTION] = time() - $S::STALE_AFTER - 10;
check('stale lock taken over', $S::acquire_lock(), true);
$S::release_lock();
check('released', array_key_exists($S::LOCK_OPTION, $GLOBALS['__options']), false);
check('is_stale', array($S::is_stale(time(), time()), $S::is_stale(time() - $S::STALE_AFTER - 1, time()), $S::is_stale(0, time())), array(false, true, true));
$GLOBALS['__options']['immoadmin_sync_status'] = 'running';
$GLOBALS['__options']['immoadmin_sync_started'] = time() - 7200;
check('hanging "running" reported as error', array($S::current_status(), get_option('immoadmin_sync_status')), array('error', 'error'));
$GLOBALS['__options']['immoadmin_sync_status'] = 'running';
$GLOBALS['__options']['immoadmin_sync_started'] = time();
check('fresh run stays running', $S::current_status(), 'running');

$GLOBALS['__options'] = array();
$S::acquire_lock();
$locked = $S::run_background();
check('overlapping run_background refused and re-run remembered', array(!empty($locked['locked']), get_option('immoadmin_sync_rerun')), array(true, 1));
$S::release_lock();
$GLOBALS['__options'] = array();
$S::run_background();
check('status never stuck at running', get_option('immoadmin_sync_status') !== 'running', true);
check('lock released after run', array_key_exists($S::LOCK_OPTION, $GLOBALS['__options']), false);

$GLOBALS['__scheduled'] = array(array(0, 'immoadmin_background_sync', array('/old/path.json')));
$S::schedule();
check('schedule(): old events (any args) removed, one new event without args', $GLOBALS['__scheduled'], array(array($GLOBALS['__scheduled'][0][0], 'immoadmin_background_sync', array())));
check('status set when scheduling', get_option('immoadmin_sync_status'), 'running');
$GLOBALS['__options'] = array();

// ---------------------------------------------------------------- rate limiting
section('security: rate-limit client IP');
$W = 'ImmoAdmin_Webhook';
check('REMOTE_ADDR used', $W::resolve_client_ip(array('REMOTE_ADDR' => '198.51.100.7')), '198.51.100.7');
check('spoofed CF-Connecting-IP / XFF ignored without trusted proxy', $W::resolve_client_ip(array('REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '5.6.7.8')), '198.51.100.7');
check('trusted proxy: CF header honoured', $W::resolve_client_ip(array('REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'), array('10.0.0.2')), '1.2.3.4');
check('trusted proxy: right-most untrusted XFF hop', $W::resolve_client_ip(array('REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9, 10.0.0.3'), array('10.0.0.2', '10.0.0.3')), '203.0.113.9');
check('invalid REMOTE_ADDR → unknown', $W::resolve_client_ip(array('REMOTE_ADDR' => 'nope')), 'unknown');
check('trusted proxy without headers → proxy IP', $W::resolve_client_ip(array('REMOTE_ADDR' => '10.0.0.2'), array('10.0.0.2')), '10.0.0.2');

// ---------------------------------------------------------------- price leaks
section('security: filter buttons never list prices of non-public units');
$FD = 'ImmoAdmin_Filter_Data';
$prow = array(
    array('status' => 'available', 'purchase_price' => '300000', 'building_name' => 'A'),
    array('status' => 'reserved', 'purchase_price' => '410000', 'building_name' => 'B'),
    array('status' => 'sold', 'purchase_price' => '520000', 'building_name' => 'C'),
    array('status' => '', 'purchase_price' => '630000', 'building_name' => 'D'),
    array('purchase_price' => '740000', 'building_name' => 'E'),
);
$pv = function ($o) { return array_map(function ($x) { return $x['value']; }, $o); };
check('Eigenes Feld purchase_price: only available units', $pv($FD::button_options('__custom', $prow, array('key' => 'purchase_price'))), array('300000'));
check('non-sensitive custom key lists every unit', $pv($FD::button_options('__custom', $prow, array('key' => 'building_name'))), array('A', 'B', 'C', 'D', 'E'));
check('range: empty/missing status excluded for prices', $FD::range_values($prow, 'purchase_price')['max'], 300000.0);

section('security: visibility — empty status fails closed');
$GLOBALS['__post_types'][7001] = 'immoadmin_wohnung';
$GLOBALS['__meta'][7001] = array('status' => '');
$GLOBALS['__post_types'][7002] = 'immoadmin_wohnung';
$GLOBALS['__meta'][7002] = array('status' => 'Available');
$GLOBALS['__post_types'][7003] = 'immoadmin_wohnung';
$GLOBALS['__meta'][7003] = array();
$GLOBALS['__can_edit'] = false;
check('empty / missing status hides prices, available does not', array(
    ImmoAdmin_Visibility::hides_sensitive_data_for(7001), ImmoAdmin_Visibility::hides_sensitive_data_for(7002), ImmoAdmin_Visibility::hides_sensitive_data_for(7003)), array(true, false, true));
$mq = priv('ImmoAdmin_Visibility', 'merge_public_meta_query')->invoke(null, null);
check('archive guard: status IN (available), no NOT EXISTS escape', $mq, array(array(array('key' => 'status', 'value' => array('available'), 'compare' => 'IN'))));

// ---------------------------------------------------------------- public redacted copy (MapSVG)
section('security: public redacted copy for MapSVG');
$GLOBALS['__options'] = array();
foreach (glob(IMMOADMIN_DATA_DIR . '*.json') ?: array() as $f) { unlink($f); }
$S::ensure_storage();
$pdir = $S::payload_dir();
foreach (glob($pdir . '*.json') ?: array() as $f) { unlink($f); }
$mk_unit = function ($id, $status) {
    return array('id' => $id, 'title' => 'Top ' . $id, 'description' => 'Text', 'metaFields' => array(
        'status' => $status, 'status_label' => ucfirst($status), 'living_area' => 80, 'floor_label' => '1. OG', 'image_1' => 'https://api/x.png',
        'purchase_price' => 400000, 'purchase_price_investor' => 380000, 'purchase_price_private' => 420000, 'purchase_price_formatted' => '400.000',
        'price_per_sqm' => 5000, 'parking_price' => 25000, 'rent_cold' => 900, 'rent_cold_formatted' => '900', 'operating_costs' => 200,
        'document_1_url' => 'https://api/d.pdf', 'document_1_title' => 'Preisliste', 'documents_count' => 1, 'extras' => '{}'));
};
$full = array('_version' => '2.0', '_format' => 'immoadmin-sync',
    'meta' => array('projectSlug' => 'mapsvg-proj', 'projectName' => 'P', 'baseUrl' => 'https://api.immoadmin.at', 'webhookToken' => 'SECRET'),
    'buildings' => array(array('id' => 'b1', 'name' => 'Haus A', 'color' => null)),
    'units' => array($mk_unit('u-av', 'available'), $mk_unit('u-res', 'reserved'), $mk_unit('u-sold', 'sold'), $mk_unit('u-nost', '')));
$full_json = json_encode($full, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Webhook path
$written = $S::write_payload_file($full_json, 'mapsvg-proj');
$pub_path = IMMOADMIN_DATA_DIR . 'mapsvg-proj.json';
check('webhook: private full copy + public copy at the old path', array(file_get_contents($written), is_file($pub_path)), array($full_json, true));
$pub = json_decode(file_get_contents($pub_path), true);
check('public copy flagged _redacted', $pub['_redacted'] ?? null, true);
check('structure identical (same keys everywhere)', array(
    array_values(array_diff(array_keys($pub), array('_redacted'))), array_keys($pub['meta']), $pub['buildings'],
    array_map(function ($u) { return array_keys($u['metaFields']); }, $pub['units'])),
    array(array_keys($full), array('projectSlug', 'projectName', 'baseUrl'), $full['buildings'],
    array_map(function ($u) { return array_keys($u['metaFields']); }, $full['units'])));
check('no credentials in public meta, baseUrl kept', array(isset($pub['meta']['webhookToken']), $pub['meta']['baseUrl']), array(false, 'https://api.immoadmin.at'));
check('available unit unchanged', $pub['units'][0], $full['units'][0]);
foreach (array(1 => 'reserved', 2 => 'sold', 3 => 'empty status') as $i => $label) {
    $m = $pub['units'][$i]['metaFields'];
    check("{$label}: every price/document nulled", array($m['purchase_price'], $m['purchase_price_investor'], $m['purchase_price_private'], $m['purchase_price_formatted'],
        $m['price_per_sqm'], $m['parking_price'], $m['rent_cold'], $m['rent_cold_formatted'], $m['operating_costs'], $m['document_1_url'], $m['document_1_title'], $m['documents_count']),
        array_fill(0, 12, null));
    check("{$label}: non-sensitive data kept", array($m['status'], $m['living_area'], $m['floor_label'], $m['image_1'], $m['extras'], $pub['units'][$i]['title']),
        array($full['units'][$i]['metaFields']['status'], 80, '1. OG', 'https://api/x.png', '{}', $full['units'][$i]['title']));
}
check('investor/private prices appear only once (the available unit)', array(substr_count(file_get_contents($pub_path), '380000'), substr_count(file_get_contents($pub_path), '420000')), array(1, 1));
check('same rule as REST redaction (Visibility is the source)', array(ImmoAdmin_Visibility::is_public_status('available'), ImmoAdmin_Visibility::is_public_status('reserved'), ImmoAdmin_Visibility::is_public_status('')), array(true, false, false));

// _redacted copies are never sync input
section('security: redacted copies never become sync input');
$S::ensure_storage();
check('migration leaves the public redacted copy in place and does not import it', array(is_file($pub_path), file_get_contents($pdir . 'mapsvg-proj.json')), array(true, $full_json));
file_put_contents(IMMOADMIN_DATA_DIR . 'only-public.json', json_encode(array('_format' => 'immoadmin-sync', '_redacted' => true, 'units' => array())));
$S::ensure_storage();
check('stray redacted file in the root is never migrated', array(is_file(IMMOADMIN_DATA_DIR . 'only-public.json'), is_file($pdir . 'only-public.json')), array(true, false));
unlink(IMMOADMIN_DATA_DIR . 'only-public.json');
file_put_contents($pdir . 'evil.json', json_encode(array('_format' => 'immoadmin-sync', '_redacted' => true, 'units' => array())));
$GLOBALS['wpdb']->results = array((object) array('immoadmin_id' => 'keep-me', 'post_id' => 990));
$GLOBALS['__deleted_posts'] = array();
$rr = $S::run();
check('redacted file in the private dir is rejected and blocks the deletion pass', array($rr['success'], $GLOBALS['__deleted_posts']), array(false, array()));
check('…and never published again', is_file(IMMOADMIN_DATA_DIR . 'evil.json'), false);
unlink($pdir . 'evil.json');
$GLOBALS['wpdb']->results = array();

// FTP fallback: full file uploaded to the public path, then an empty webhook
section('security: FTP fallback flow');
$ftp = $full; $ftp['meta']['projectSlug'] = 'ftp-proj';
$ftp['units'][1]['metaFields']['purchase_price'] = 777777;
$ftp_json = json_encode($ftp);
file_put_contents(IMMOADMIN_DATA_DIR . 'ftp-proj.json', $ftp_json);
class Fake_Request { private $b; public function __construct($b) { $this->b = $b; } public function get_body() { return $this->b; } }
class WP_REST_Response { public $data; public $status; public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
if (!class_exists('ImmoAdmin_Admin')) { class ImmoAdmin_Admin { public static function mark_connection_verified() {} } }
$GLOBALS['__scheduled'] = array();
$resp = ImmoAdmin_Webhook::handle_sync(new Fake_Request(''));
check('empty webhook → 200, sync scheduled', array($resp->status, count($GLOBALS['__scheduled'])), array(200, 1));
check('before scheduling: full file moved to the private dir', file_get_contents($pdir . 'ftp-proj.json'), $ftp_json);
$ftp_pub = file_get_contents(IMMOADMIN_DATA_DIR . 'ftp-proj.json');
check('public path immediately holds the redacted copy (no 777777)', array(strpos($ftp_pub, '777777'), json_decode($ftp_pub, true)['_redacted']), array(false, true));
check('no temp files left in the data root', count(glob(IMMOADMIN_DATA_DIR . '.*.tmp') ?: array()), 0);

section('security: invalid webhook body → 400, nothing written');
$resp = ImmoAdmin_Webhook::handle_sync(new Fake_Request('{"_format":"other"}'));
check('wrong format → 400', $resp->status, 400);
$resp = ImmoAdmin_Webhook::handle_sync(new Fake_Request('not json'));
check('garbage → 400', $resp->status, 400);
$GLOBALS['__options'] = array();

// ---------------------------------------------------------------- review round 2
section('security: FTP upload after the migration is caught on the next request (init)');
$GLOBALS['__options'] = array('immoadmin_storage_version' => $S::STORAGE_VERSION);
foreach (glob(IMMOADMIN_DATA_DIR . '*.json') ?: array() as $f) { unlink($f); }
$S::ensure_storage();
$pdir = $S::payload_dir();
foreach (glob($pdir . '*.json') ?: array() as $f) { unlink($f); }
$S::write_payload_file($full_json, 'mapsvg-proj');
check('state remembered after a webhook write → nothing to do', $S::public_dir_changed(get_option('immoadmin_public_state')), false);
// FTP overwrites the existing public copy IN PLACE (dir mtime unchanged)
$ftp2 = $full; $ftp2['units'][1]['metaFields']['purchase_price'] = 888888;
$ftp2_json = json_encode($ftp2);
file_put_contents($pub_path, $ftp2_json);
touch($pub_path, time() + 5);
clearstatcache();
check('in-place overwrite detected via the file mtime', $S::public_dir_changed(get_option('immoadmin_public_state')), true);
$S::maybe_secure_public_dir();
check('init: full upload moved to the private dir', file_get_contents($pdir . 'mapsvg-proj.json'), $ftp2_json);
check('init: public path redacted again (no 888888)', array(strpos(file_get_contents($pub_path), '888888'), json_decode(file_get_contents($pub_path), true)['_redacted']), array(false, true));
clearstatcache();
check('…and state stored again', $S::public_dir_changed(get_option('immoadmin_public_state')), false);
// New file name (dir mtime changes)
file_put_contents(IMMOADMIN_DATA_DIR . 'new-proj.json', $ftp2_json);
touch(IMMOADMIN_DATA_DIR, time() + 10);
clearstatcache();
$S::maybe_secure_public_dir();
check('new FTP file name caught too', array(is_file($pdir . 'new-proj.json'), !empty(json_decode(file_get_contents(IMMOADMIN_DATA_DIR . 'new-proj.json'), true)['_redacted'])), array(true, true));
$GLOBALS['__options'] = array();
$S::maybe_secure_public_dir();
check('before the storage migration: init check does nothing', array_key_exists('immoadmin_public_state', $GLOBALS['__options']), false);
unlink($pdir . 'new-proj.json');

section('security: redact_payload fails closed on odd unit lists');
check('units as object → null', $S::redact_payload(json_decode('{"_format":"immoadmin-sync","units":{"a":{"id":"x"}}}')), null);
check('units with a non-object entry → null', $S::redact_payload(json_decode('{"_format":"immoadmin-sync","units":[{"id":"x"},"y"]}')), null);
check('units as string → null', $S::redact_payload(json_decode('{"_format":"immoadmin-sync","units":"x"}')), null);
check('empty list fine', $S::redact_payload(json_decode('{"_format":"immoadmin-sync","units":[]}'))->_redacted ?? null, true);
file_put_contents($pdir . 'odd.json', '{"_format":"immoadmin-sync","units":{"a":{"id":"x","metaFields":{"purchase_price":1}}}}');
file_put_contents(IMMOADMIN_DATA_DIR . 'odd.json', '{"_redacted":true,"units":[]}');
$S::write_public_copy($pdir . 'odd.json');
check('no safe copy → public file of that name removed (even an old redacted one)', is_file(IMMOADMIN_DATA_DIR . 'odd.json'), false);
unlink($pdir . 'odd.json');

section('security: non-allowlisted media keep the remote URL without blocking the hash');
$GLOBALS['__remote_calls'] = 0;
$sv = sync_unit(array('id' => 'u-svg', 'title' => 'SVG', 'metaFields' => array('status' => 'available', 'floor_plan_1' => 'https://api.immoadmin.test/uploads/plan.svg')));
check('svg: remote URL kept, no download attempt', array(get_post_meta($sv['post_id'], 'floor_plan_1'), $GLOBALS['__remote_calls']), array('https://api.immoadmin.test/uploads/plan.svg', 0));
check('svg: hash stored (unit not rewritten every sync)', get_post_meta($sv['post_id'], '_content_hash') !== '', true);
check('next sync skips it', sync_unit(array('id' => 'u-svg', 'title' => 'SVG', 'metaFields' => array('status' => 'available', 'floor_plan_1' => 'https://api.immoadmin.test/uploads/plan.svg')), array('u-svg' => $sv['post_id']))['status'], 'skipped');

section('security: heartbeat while downloading, only with the lock');
$GLOBALS['__options'] = array();
$GLOBALS['__http'] = function () use ($http_ok, $png) { return $http_ok($png, 'image/png'); };
if (is_file(IMMOADMIN_MEDIA_DIR . 'hb.png')) { unlink(IMMOADMIN_MEDIA_DIR . 'hb.png'); }
$dl('https://api.immoadmin.test/uploads/hb.png');
check('no lock held → download does not create a lock row', array_key_exists($S::LOCK_OPTION, $GLOBALS['__options']), false);
$S::acquire_lock();
$GLOBALS['__options'][$S::LOCK_OPTION] = 1;
if (is_file(IMMOADMIN_MEDIA_DIR . 'hb.png')) { unlink(IMMOADMIN_MEDIA_DIR . 'hb.png'); }
$dl('https://api.immoadmin.test/uploads/hb.png');
check('lock held → heartbeat refreshed before the request', $GLOBALS['__options'][$S::LOCK_OPTION] > 1, true);
$S::release_lock();
$GLOBALS['__http'] = null;

section('security: rerun flag read past the option cache');
$GLOBALS['wpdb']->var_result = '1';
check('rerun read from the DB', priv('ImmoAdmin_Sync', 'rerun_requested')->invoke(null), true);
$GLOBALS['wpdb']->var_result = null;
check('no row → no rerun', priv('ImmoAdmin_Sync', 'rerun_requested')->invoke(null), false);
$GLOBALS['wpdb']->var_result = 0;

section('security: units-table hides prices for empty/unknown status (allowlist)');
$ut_posts = immoadmin_test_table_fixture_posts();
$GLOBALS['__meta'][702]['status'] = '';
$GLOBALS['__meta'][703]['status'] = 'something-new';
$GLOBALS['__can_edit'] = false;
$ImmoAdmin_FD = 'ImmoAdmin_Filter_Data';
$ImmoAdmin_FD::set_page_index_for_tests(array('tables' => array(), 'filters' => array()));
$ut_html = immoadmin_test_render_table(immoadmin_test_table_cases()['table-legacy-dim'], 'tbl777', $ut_posts);
check('empty and unknown status: price not rendered; available price shown', array(strpos($ut_html, '500.000'), strpos($ut_html, '760.000'), strpos($ut_html, '439.800') !== false), array(false, false, true));
immoadmin_test_table_fixture_posts();
$GLOBALS['__options'] = array();
