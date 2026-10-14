/**
 * Plain-node tests for the DOM-free helpers in bricks/assets/js/units-table.js
 * (row detection, nested-accordion isolation, zebra phase, sort compare,
 * URL parsing). No dependencies — fake nodes only expose what the helpers
 * read: classList.contains, children, parentElement.
 *
 *   node tests/units-table-logic.test.js
 *
 * Exits non-zero on any failure.
 */
'use strict';

var path = require('path');
var T = require(path.join(__dirname, '..', 'bricks', 'assets', 'js', 'units-table.js'));

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

// Minimal element: el('a b', [children]) — name = first class (for output).
function el(classes, kids) {
    var list = classes.split(/\s+/).filter(Boolean);
    var node = {
        name: list[0] || '',
        classList: { contains: function (c) { return list.indexOf(c) !== -1; } },
        children: kids || [],
        parentElement: null
    };
    node.children.forEach(function (k) { k.parentElement = node; });
    return node;
}
function ids(nodes) { return nodes.map(function (n) { return n.id || n.name; }); }
function unit(id, extraKids) {
    var n = el('accordion-item immoadmin-table-rowgroup', [
        el('immoadmin-table-row accordion-title-wrapper', [el('immoadmin-table-cell')])
    ].concat(extraKids || []));
    n.id = id;
    return n;
}
function bare(id) { var n = el('immoadmin-table-row is-sold', [el('immoadmin-table-cell')]); n.id = id; return n; }
function panel(kids) { return el('immoadmin-accordion-panel', [el('immoadmin-accordion-panel__cell', kids || [])]); }

section('collectUnits: own rows only');
var bricksAccordion = el('brxe-accordion-nested', [
    el('accordion-item', [el('accordion-title-wrapper'), el('accordion-content-wrapper')]),
    el('accordion-item', [el('accordion-title-wrapper'), el('accordion-content-wrapper')])
]);
var nestedTable = el('brxe-y brxe-immoadmin-units-table', [el('immoadmin-table', [el('immoadmin-table-header'), unit('inner1'), bare('inner2')])]);
var u1 = unit('u1');
u1.children.push(panel([bricksAccordion, nestedTable]));
u1.children[1].parentElement = u1;
var grid = el('immoadmin-table', [el('immoadmin-table-header', [el('immoadmin-table-cell-header')]), u1, bare('b2'), unit('u3', [panel()])]);
check('accordion units + bare rows, header skipped, panel content ignored', ids(T.collectUnits(grid)), ['u1', 'b2', 'u3']);

section('collectUnits: Bricks load-more nesting (batch inside the last item)');
var p3 = unit('p3a');
var p2 = unit('p2a', [panel(), unit('p2b', [panel()]), bare('p2c'), p3]);
var loadGrid = el('immoadmin-table', [el('immoadmin-table-header'), unit('p1a', [panel()]), unit('p1b', [panel(), p2])]);
check('nested pages found in document order', ids(T.collectUnits(loadGrid)), ['p1a', 'p1b', 'p2a', 'p2b', 'p2c', 'p3a']);

section('isOwnNode');
var ownTitle = u1.children[0];
var foreignTitle = bricksAccordion.children[0].children[0];
var innerRow = nestedTable.children[0].children[1].children[0];
check('own title row', T.isOwnNode(ownTitle, grid), true);
check('Bricks accordion title inside the panel', T.isOwnNode(foreignTitle, grid), false);
check('nested units-table row inside the panel', T.isOwnNode(innerRow, grid), false);
check('node outside the grid', T.isOwnNode(el('x'), grid), false);
check('own title row of a load-more nested unit', T.isOwnNode(p3.children[0], loadGrid), true);

section('isUnitNode');
check('kinds', [unit('a'), bare('b'), el('immoadmin-table-row accordion-title-wrapper'), el('immoadmin-table-header'), el('immoadmin-accordion-panel')].map(T.isUnitNode), [true, true, false, false, false]);

section('zebra: phase of the old nth-of-type(even) (1st, 3rd … striped)');
check('5 visible rows', T.zebraFlags(5), [true, false, true, false, true]);
check('none', T.zebraFlags(0), []);

section('sort compare');
check('numeric', [T.compareSortValues('9', '11', 'asc') < 0, T.compareSortValues('9', '11', 'desc') > 0], [true, true]);
check('raw numbers, not German display strings', T.compareSortValues('439800', '1200000', 'asc') < 0, true);
check('natural text', T.compareSortValues('Haus 9', 'Haus 11', 'asc') < 0, true);
check('empty vs number → text compare, empty first asc', T.compareSortValues('', '5', 'asc') < 0, true);
check('floor sort values (GG -10 < EG 0)', T.compareSortValues('-10', '0', 'asc') < 0, true);

section('URL state parsing');
check('query param', T.requestedUnit('https://x.test/p/?unit=15', 'unit'), '15');
check('custom key', T.requestedUnit('https://x.test/p/?top=Haus-2-Top-7&unit=1', 'top'), 'Haus-2-Top-7');
check('hash form', T.requestedUnit('https://x.test/p/#unit-42', 'unit'), '42');
check('absent', T.requestedUnit('https://x.test/p/?other=1', 'unit'), '');
check('garbage URL', T.requestedUnit('not a url', 'unit'), '');

section('word-break safety net: does the grid overflow?');
check('fits', T.tracksOverflow('100px 200px 300px', 'normal', 600), false);
check('rounding tolerance', T.tracksOverflow('100.3px 200.3px 300.3px', '0px', 600), false);
check('overflows', T.tracksOverflow('104px 142px 120px 152px', 'normal', 500), true);
check('column gap counted between tracks', T.tracksOverflow('100px 100px 100px', '20px', 330), true);
check('no resolved tracks → never tight', T.tracksOverflow('none', 'normal', 100), false);

section('interactive selector (keyboard / click guard)');
['a[href]', 'button', 'input', 'select', 'textarea', '[contenteditable]'].forEach(function (s) {
    check('covers ' + s, T.INTERACTIVE.indexOf(s) !== -1, true);
});

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails > 0 ? 1 : 0);
