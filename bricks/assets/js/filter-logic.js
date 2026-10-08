/**
 * ImmoAdmin filter widgets — pure matching + formatting logic (v2.14.0).
 *
 * No DOM access: runs in the browser (window.ImmoAdminFilterLogic) and in
 * plain node for tests (module.exports). bricks/assets/js/filters.js wires it
 * to the page.
 *
 * Criterion shapes (built by filters.js from the filter elements):
 *   { kind: 'set',   key, match: 'text'|'floor'|'list'|'number', values: ['Presto', '1|2|3', '4+', …] }
 *   { kind: 'range', key, min, max, boundMin, boundMax, includeEmpty }
 *
 * Rules:
 *   - OR within one criterion (any selected button matches),
 *     AND across criteria (every active filter element must match).
 *   - An option value may hold alternatives separated by "|".
 *   - A set criterion without selected values, and a range criterion whose
 *     handles sit at the bounds, is inactive and matches everything —
 *     including units without a value.
 *   - An active range never matches a unit without a value, unless
 *     includeEmpty (redacted prices of reserved/sold units are "no value").
 *   - floor: a maisonette (floor + floor_to) matches both of its floors.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.ImmoAdminFilterLogic = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var EPS = 1e-9;

    function toNumber(v) {
        if (v === null || v === undefined || v === '' || typeof v === 'boolean') return null;
        var n = typeof v === 'number' ? v : parseFloat(String(v).replace(',', '.'));
        return isFinite(n) ? n : null;
    }

    function norm(v) {
        return String(v == null ? '' : v).trim().toLowerCase();
    }

    function alternatives(optionValue) {
        return String(optionValue == null ? '' : optionValue)
            .split('|')
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s !== ''; });
    }

    function rowList(v) {
        if (Array.isArray(v)) return v.map(norm);
        if (v === null || v === undefined || v === '') return [];
        return String(v).split(/[\s,;]+/).map(norm).filter(Boolean);
    }

    function matchAlternative(row, c, alt) {
        var v = row ? row[c.key] : undefined;
        switch (c.match) {
            case 'floor': {
                var want = toNumber(alt);
                if (want === null) return false;
                var floors = [row ? row.floor : null, row ? row.floor_to : null]
                    .map(toNumber)
                    .filter(function (f) { return f !== null; });
                for (var i = 0; i < floors.length; i++) {
                    if (Math.abs(floors[i] - want) < EPS) return true;
                }
                return false;
            }
            case 'list':
                return rowList(v).indexOf(norm(alt)) !== -1;
            case 'number': {
                var n = toNumber(v);
                if (n === null) return false;
                var plus = /^\s*(-?[\d.,]+)\s*\+\s*$/.exec(alt);
                if (plus) {
                    var from = toNumber(plus[1]);
                    return from !== null && n >= from - EPS;
                }
                var eq = toNumber(alt);
                return eq !== null && Math.abs(n - eq) < EPS;
            }
            default: // text
                if (v === null || v === undefined) return false;
                return norm(v) === norm(alt);
        }
    }

    function isActive(c) {
        if (!c) return false;
        if (c.kind === 'set') return Array.isArray(c.values) && c.values.length > 0;
        if (c.kind === 'range') {
            var min = toNumber(c.min), max = toNumber(c.max);
            var bMin = toNumber(c.boundMin), bMax = toNumber(c.boundMax);
            if (min === null || max === null) return false;
            return (bMin === null || min > bMin + EPS) || (bMax === null || max < bMax - EPS);
        }
        return false;
    }

    function matchCriterion(row, c) {
        if (!isActive(c)) return true;
        if (c.kind === 'set') {
            for (var i = 0; i < c.values.length; i++) {
                var alts = alternatives(c.values[i]);
                for (var j = 0; j < alts.length; j++) {
                    if (matchAlternative(row, c, alts[j])) return true;
                }
            }
            return false;
        }
        // range
        var v = toNumber(row ? row[c.key] : null);
        if (v === null) return !!c.includeEmpty;
        return v >= toNumber(c.min) - EPS && v <= toNumber(c.max) + EPS;
    }

    function matchRow(row, criteria) {
        if (!Array.isArray(criteria)) return true;
        for (var i = 0; i < criteria.length; i++) {
            if (!matchCriterion(row, criteria[i])) return false;
        }
        return true;
    }

    function activeCriteria(criteria) {
        return (criteria || []).filter(isActive);
    }

    /**
     * Table-level decision for one table's rows.
     *   hiddenByScope: a "Haus" criterion is active and none of the table's
     *                  rows belongs to a selected house → hide the table
     *                  (and its heading wrapper) regardless of the empty mode.
     */
    function evaluateTable(rows, criteria, scopeKeys) {
        var active = activeCriteria(criteria);
        scopeKeys = scopeKeys || ['building_name'];
        var scope = active.filter(function (c) { return scopeKeys.indexOf(c.key) !== -1; });
        var visible = [];
        var anyInScope = false;
        for (var i = 0; i < rows.length; i++) {
            if (!anyInScope && scope.length && matchRow(rows[i], scope)) anyInScope = true;
            visible.push(matchRow(rows[i], active));
        }
        var count = visible.filter(Boolean).length;
        return {
            visible: visible,
            count: count,
            anyActive: active.length > 0,
            hiddenByScope: scope.length > 0 && !anyInScope
        };
    }

    // ---------------------------------------------------------------- format
    // Mirror of ImmoAdmin_Filter_Data::format_value() — same cases are tested
    // against both (tests/fixtures/format-cases.json).

    function numberFormat(n, decimals, decPoint, thousandsSep) {
        var fixed = (Math.round(Math.abs(n) * Math.pow(10, decimals)) / Math.pow(10, decimals)).toFixed(decimals);
        var parts = fixed.split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
        var sign = n < 0 && Number(fixed) !== 0 ? '-' : '';
        return sign + intPart + (parts[1] ? decPoint + parts[1] : '');
    }

    function stripZeros(s) {
        return s.indexOf(',') === -1 ? s : s.replace(/0+$/, '').replace(/,$/, '');
    }

    function format(value, fmt) {
        var v = toNumber(value);
        if (v === null) return '';
        fmt = fmt || {};
        var mode = fmt.mode || 'thousands';
        var decimals = Math.max(0, Math.min(4, parseInt(fmt.decimals, 10) || 0));
        var prefix = fmt.prefix == null ? '' : String(fmt.prefix);
        var suffix = fmt.suffix == null ? '' : String(fmt.suffix);
        var text;
        if (mode === 'k' && Math.abs(v) >= 1000000) {
            text = stripZeros(numberFormat(v / 1000000, 2, ',', '.')) + ' Mio.';
        } else if (mode === 'k' && Math.abs(v) >= 1000) {
            text = stripZeros(numberFormat(v / 1000, 1, ',', '.')) + 'k';
        } else if (mode === 'plain') {
            text = numberFormat(v, decimals, ',', '');
        } else {
            text = numberFormat(v, decimals, ',', '.');
        }
        return prefix + text + suffix;
    }

    /** Snap a value to the slider grid inside [min, max]. */
    function snap(value, min, max, step) {
        var v = toNumber(value), lo = toNumber(min), hi = toNumber(max), st = toNumber(step);
        if (v === null) v = lo;
        if (!st || st <= 0) st = 1;
        var snapped = lo + Math.round((v - lo) / st) * st;
        snapped = Math.min(hi, Math.max(lo, snapped));
        return Math.round(snapped * 1e6) / 1e6;
    }

    return {
        toNumber: toNumber,
        alternatives: alternatives,
        isActive: isActive,
        matchCriterion: matchCriterion,
        matchRow: matchRow,
        activeCriteria: activeCriteria,
        evaluateTable: evaluateTable,
        format: format,
        snap: snap
    };
});
