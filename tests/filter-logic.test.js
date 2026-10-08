/**
 * Plain-node tests for bricks/assets/js/filter-logic.js (no dependencies).
 *
 *   node tests/filter-logic.test.js
 *
 * Exits non-zero on any failure.
 */
'use strict';

var path = require('path');
var fs = require('fs');
var L = require(path.join(__dirname, '..', 'bricks', 'assets', 'js', 'filter-logic.js'));

var count = 0, fails = 0;
function check(label, actual, expected) {
    count++;
    var a = JSON.stringify(actual), e = JSON.stringify(expected);
    if (a === e) {
        console.log('  ok   ' + label);
    } else {
        fails++;
        console.log('  FAIL ' + label + '\n       expected: ' + e + '\n       actual:   ' + a);
    }
}
function section(name) { console.log('\n' + name); }

// Rows as units-table emits them (data-immoadmin-filter-values).
var presto1 = { building_name: 'Presto', floor: -10, floor_to: 0, orientation: ['south', 'west'], room_count: 3, living_area: 79.9, purchase_price: 439800 };
var presto2 = { building_name: 'Presto', floor: 1, floor_to: null, orientation: ['east'], room_count: 2, living_area: 54, purchase_price: 280000 };
var allegroReserved = { building_name: 'Allegro', floor: 99, floor_to: null, orientation: ['north'], room_count: 4, living_area: 120, purchase_price: null };
var allegro5 = { building_name: 'Allegro', floor: 0, floor_to: null, orientation: [], room_count: 5, living_area: 154, purchase_price: 760000 };
var rows = [presto1, presto2, allegroReserved, allegro5];
var ids = function (crit) { return rows.map(function (r, i) { return L.matchRow(r, crit) ? i : null; }).filter(function (x) { return x !== null; }); };

var set = function (key, match, values) { return { kind: 'set', key: key, match: match, values: values }; };
var range = function (key, min, max, bMin, bMax, inc) { return { kind: 'range', key: key, min: min, max: max, boundMin: bMin, boundMax: bMax, includeEmpty: !!inc }; };

section('set criteria: OR within a field');
check('no values → inactive, all rows', ids([set('building_name', 'text', [])]), [0, 1, 2, 3]);
check('Haus Presto', ids([set('building_name', 'text', ['Presto'])]), [0, 1]);
check('Haus Presto OR Allegro', ids([set('building_name', 'text', ['Presto', 'Allegro'])]), [0, 1, 2, 3]);
check('text match case/space-insensitive', ids([set('building_name', 'text', [' presto '])]), [0, 1]);
check('unknown house → nothing', ids([set('building_name', 'text', ['Largo'])]), []);

section('floor: maisonette matches both floors');
check('GG (-10) → maisonette GG+EG', ids([set('floor', 'floor', ['-10'])]), [0]);
check('EG (0) → maisonette AND plain EG unit', ids([set('floor', 'floor', ['0'])]), [0, 3]);
check('DG (99)', ids([set('floor', 'floor', ['99'])]), [2]);
check('alternatives "1|2|3" labelled OG', ids([set('floor', 'floor', ['1|2|3'])]), [1]);
check('GG OR DG', ids([set('floor', 'floor', ['-10', '99'])]), [0, 2]);
check('row without floor never matches active floor filter', L.matchRow({ floor: null, floor_to: null }, [set('floor', 'floor', ['0'])]), false);
check('floor 0 is a value, not empty', L.matchRow({ floor: 0 }, [set('floor', 'floor', ['0'])]), true);

section('orientation: list membership');
check('Süd', ids([set('orientation', 'list', ['south'])]), [0]);
check('West → unit facing south,west too', ids([set('orientation', 'list', ['west'])]), [0]);
check('Ost OR Nord', ids([set('orientation', 'list', ['east', 'north'])]), [1, 2]);
check('comma string row value accepted', L.matchRow({ orientation: 'south, west' }, [set('orientation', 'list', ['west'])]), true);
check('unit without orientation excluded when active', L.matchRow({ orientation: [] }, [set('orientation', 'list', ['south'])]), false);

section('rooms: numbers and "N+"');
check('3 Zimmer', ids([set('room_count', 'number', ['3'])]), [0]);
check('"4+" → 4 and 5', ids([set('room_count', 'number', ['4+'])]), [2, 3]);
check('2 OR 4+', ids([set('room_count', 'number', ['2', '4+'])]), [1, 2, 3]);
check('2.5 vs "2,5"', L.matchRow({ room_count: 2.5 }, [set('room_count', 'number', ['2,5'])]), true);
check('null rooms excluded', L.matchRow({ room_count: null }, [set('room_count', 'number', ['3'])]), false);

section('AND across fields');
check('Presto AND 2 Zimmer', ids([set('building_name', 'text', ['Presto']), set('room_count', 'number', ['2'])]), [1]);
check('Presto AND EG (maisonette)', ids([set('building_name', 'text', ['Presto']), set('floor', 'floor', ['0'])]), [0]);
check('Allegro AND Süd → none', ids([set('building_name', 'text', ['Allegro']), set('orientation', 'list', ['south'])]), []);

section('range criteria');
check('handles at bounds → inactive → includes empty price', ids([range('purchase_price', 280000, 760000, 280000, 760000, false)]), [0, 1, 2, 3]);
check('isActive false at bounds', L.isActive(range('purchase_price', 280000, 760000, 280000, 760000)), false);
check('isActive true when narrowed', L.isActive(range('purchase_price', 300000, 760000, 280000, 760000)), true);
check('price 300k–500k, empty hidden', ids([range('purchase_price', 300000, 500000, 280000, 760000, false)]), [0]);
check('price 300k–500k, include empty (redacted reserved stays)', ids([range('purchase_price', 300000, 500000, 280000, 760000, true)]), [0, 2]);
check('inclusive ends', ids([range('purchase_price', 280000, 439800, 0, 1000000, false)]), [0, 1]);
check('area 70–130', ids([range('living_area', 70, 130, 54, 154, false)]), [0, 2]);
check('area + house AND', ids([range('living_area', 70, 130, 54, 154, false), set('building_name', 'text', ['Allegro'])]), [2]);
check('string numbers in row', L.matchRow({ living_area: '79.9' }, [range('living_area', 70, 80, 0, 200)]), true);

section('evaluateTable: scope (Haus) vs empty result');
var tablePresto = [presto1, presto2];
var e1 = L.evaluateTable(tablePresto, [set('building_name', 'text', ['Allegro'])]);
check('house not selected → hiddenByScope', e1.hiddenByScope, true);
check('… count 0', e1.count, 0);
var e2 = L.evaluateTable(tablePresto, [set('building_name', 'text', ['Presto']), set('room_count', 'number', ['5'])]);
check('house selected but no match → not hiddenByScope (empty mode decides)', [e2.hiddenByScope, e2.count, e2.anyActive], [false, 0, true]);
var e3 = L.evaluateTable(tablePresto, []);
check('no filters → all visible, inactive', [e3.visible, e3.count, e3.anyActive, e3.hiddenByScope], [[true, true], 2, false, false]);
var e4 = L.evaluateTable(tablePresto, [set('building_name', 'text', [])]);
check('empty house selection = inactive', [e4.count, e4.hiddenByScope], [2, false]);
var e5 = L.evaluateTable([], [set('building_name', 'text', ['Presto'])]);
check('empty table with house filter → hidden', e5.hiddenByScope, true);

section('format (same cases as PHP)');
var cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'format-cases.json'), 'utf8'));
cases.forEach(function (c) {
    check('format ' + JSON.stringify(c.value) + ' ' + JSON.stringify(c.fmt), L.format(c.value, c.fmt), c.expected);
});

section('snap');
check('snap to step', L.snap(283400, 280000, 760000, 10000), 280000);
check('snap rounds up', L.snap(286000, 280000, 760000, 10000), 290000);
check('clamped to max', L.snap(900000, 280000, 760000, 10000), 760000);
check('fractional step', L.snap(70.26, 70, 80, 0.5), 70.5);

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails > 0 ? 1 : 0);
