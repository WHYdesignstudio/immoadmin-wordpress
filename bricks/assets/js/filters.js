/**
 * ImmoAdmin filter widgets — page controller (v2.14.0, targeting v2.15.0).
 *
 *   filters  [data-immoadmin-filter="buttons" | "range"]
 *   actions  [data-immoadmin-filter="actions"]  (Suchen / Zurücksetzen)
 *   tables   [data-element="immoadmin-units-table"] that a filter acts on —
 *            server-side marked by data-immoadmin-filter-group (v2.14.0) or
 *            data-immoadmin-filterable (v2.15.0); id = data-bricks-query-id
 *
 * Which tables a filter acts on: data-immoadmin-filter-targets (Ziel-Tabellen)
 * ∪ tables with its data-immoadmin-filter-group; neither → all tables
 * (filter-logic.js appliesTo()). An actions element is responsible for every
 * filter sharing a table with it (all mode: every filter on the page).
 *
 * Client-side only: rows carry their values in data-immoadmin-filter-values
 * (prices already redacted server-side). Matching lives in filter-logic.js.
 *
 * Never touches a table no filter acts on, never touches Bricks' own filters.
 */
(function () {
    'use strict';

    var L = null; // resolved in init(): robust against late / reordered script loading

    var ROW_ATTR = 'data-immoadmin-filter-values';
    var HIDDEN_ATTR = 'data-immoadmin-filter-hidden';
    var filters = []; // { el, impl, spec, committed, pending }
    var actions = []; // { el, spec }

    function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }

    function parseJSON(s, fallback) {
        try { return s ? JSON.parse(s) : fallback; } catch (e) { return fallback; }
    }

    function specOf(el) {
        return {
            targets: L.parseTargets(el.getAttribute('data-immoadmin-filter-targets') || ''),
            group: el.getAttribute('data-immoadmin-filter-group') || ''
        };
    }

    // ------------------------------------------------------------ filters

    function ButtonsFilter(el, onChange) {
        var self = this;
        this.el = el;
        this.onChange = onChange;
        this.config = parseJSON(el.getAttribute('data-immoadmin-filter-config'), {});
        this.id = el.id || ('iaf-' + Math.random().toString(36).slice(2));

        el.addEventListener('click', function (e) {
            var btn = e.target.closest('.immoadmin-filter-option');
            if (!btn || !el.contains(btn)) return;
            e.preventDefault();
            self.toggle(btn);
        });
    }
    ButtonsFilter.prototype.buttons = function () {
        return this.el.querySelectorAll('.immoadmin-filter-option');
    };
    ButtonsFilter.prototype.setActive = function (btn, on) {
        btn.classList.toggle('brx-option-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        var li = btn.closest('.immoadmin-filter-item');
        if (li) li.classList.toggle('brx-option-active', on);
    };
    ButtonsFilter.prototype.toggle = function (btn) {
        var self = this;
        var on = !btn.classList.contains('brx-option-active');
        if (on && !this.config.multiple) {
            each(this.buttons(), function (b) { if (b !== btn) self.setActive(b, false); });
        }
        this.setActive(btn, on);
        this.onChange();
    };
    ButtonsFilter.prototype.criterion = function () {
        var values = [];
        each(this.buttons(), function (b) {
            if (b.classList.contains('brx-option-active')) values.push(b.getAttribute('data-value'));
        });
        return { id: this.id, kind: 'set', key: this.config.key, match: this.config.match || 'text', values: values };
    };
    ButtonsFilter.prototype.reset = function () {
        var self = this;
        each(this.buttons(), function (b) { self.setActive(b, false); });
    };

    function RangeFilter(el, onChange) {
        var self = this;
        this.el = el;
        this.onChange = onChange;
        this.config = parseJSON(el.getAttribute('data-immoadmin-filter-config'), {});
        this.id = el.id || ('iaf-' + Math.random().toString(36).slice(2));
        this.minInput = el.querySelector('input[type="range"].min');
        this.maxInput = el.querySelector('input[type="range"].max');
        this.wrap = el.querySelector('.slider-wrap');
        this.lowerText = el.querySelector('.value-wrap .lower .value');
        this.upperText = el.querySelector('.value-wrap .upper .value');
        if (!this.minInput || !this.maxInput) return;

        var onInput = function (e) {
            self.clamp(e.target === self.minInput ? 'min' : 'max');
            self.paint();
            self.onChange();
        };
        this.minInput.addEventListener('input', onInput);
        this.maxInput.addEventListener('input', onInput);
        this.paint();
    }
    RangeFilter.prototype.bounds = function () {
        return { min: Number(this.config.min), max: Number(this.config.max), step: Number(this.config.step) || 1 };
    };
    RangeFilter.prototype.values = function () {
        var b = this.bounds();
        return {
            lo: L.snap(this.minInput.value, b.min, b.max, b.step),
            hi: L.snap(this.maxInput.value, b.min, b.max, b.step)
        };
    };
    RangeFilter.prototype.clamp = function (moved) {
        var v = this.values();
        if (v.lo > v.hi) {
            if (moved === 'min') this.minInput.value = v.hi;
            else this.maxInput.value = v.lo;
        }
        // The handle that was moved last sits on top, so two handles at the
        // same spot can always be pulled apart again.
        this.minInput.classList.toggle('is-top', moved === 'min');
        this.maxInput.classList.toggle('is-top', moved !== 'min');
    };
    RangeFilter.prototype.paint = function () {
        if (!this.minInput) return;
        var b = this.bounds();
        var v = this.values();
        var span = b.max - b.min || 1;
        if (this.wrap) {
            this.wrap.style.setProperty('--iaf-lo', String((v.lo - b.min) / span));
            this.wrap.style.setProperty('--iaf-hi', String((v.hi - b.min) / span));
        }
        var lo = L.format(v.lo, this.config.format);
        var hi = L.format(v.hi, this.config.format);
        if (this.lowerText) this.lowerText.textContent = lo;
        if (this.upperText) this.upperText.textContent = hi;
        this.minInput.setAttribute('aria-valuetext', lo);
        this.maxInput.setAttribute('aria-valuetext', hi);
    };
    RangeFilter.prototype.criterion = function () {
        if (!this.minInput) return null;
        var b = this.bounds();
        var v = this.values();
        return {
            id: this.id, kind: 'range', key: this.config.key,
            min: v.lo, max: v.hi, boundMin: b.min, boundMax: b.max,
            includeEmpty: !!this.config.includeEmpty
        };
    };
    RangeFilter.prototype.reset = function () {
        if (!this.minInput) return;
        var b = this.bounds();
        this.minInput.value = b.min;
        this.maxInput.value = b.max;
        this.paint();
    };

    // ------------------------------------------------------------ tables

    // Tables some filter acts on (marked server-side), never builder previews.
    function pageTables() {
        var out = [];
        each(document.querySelectorAll('[data-element="immoadmin-units-table"]'), function (t) {
            if (t.getAttribute('data-builder') === '1') return;
            var group = t.getAttribute('data-immoadmin-filter-group');
            if (!group && !t.hasAttribute('data-immoadmin-filterable')) return;
            out.push({
                el: t,
                id: t.getAttribute('data-bricks-query-id') || String(t.id || '').replace(/^brxe-/, ''),
                group: group || ''
            });
        });
        return out;
    }

    // Same row set as units-table.js collectRows(): an .accordion-item, or a
    // bare row (reserved/sold/rented, table mode) — never the title row that
    // sits inside an accordion item.
    function rowsOf(table) {
        var out = [];
        var grid = table.querySelector('.immoadmin-table');
        if (!grid) return out;
        each(grid.querySelectorAll('[' + ROW_ATTR + ']'), function (n) { out.push(n); });
        return out;
    }

    // Unit rows without filter values: rows Bricks re-rendered over AJAX
    // (its own filter / pagination) for a table that only joined via a
    // filter on the page. Such a table is left alone instead of hidden.
    function hasRowsWithoutValues(table) {
        var grid = table.querySelector('.immoadmin-table');
        return !!(grid && grid.querySelector('[data-unit-id]:not([' + ROW_ATTR + ']):not(.accordion-title-wrapper)'));
    }

    function rowValues(row) {
        if (!row._immoadminValues) row._immoadminValues = parseJSON(row.getAttribute(ROW_ATTR), {});
        return row._immoadminValues;
    }

    function closeRow(row) {
        var trigger = row.classList.contains('accordion-title-wrapper') ? row : row.querySelector('.accordion-title-wrapper.brx-open');
        if (trigger && trigger.classList.contains('brx-open')) {
            trigger.classList.remove('brx-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function hideWrapperOf(table) {
        var mode = table.getAttribute('data-immoadmin-filter-hide');
        if (!mode) return null;
        var parent = table.parentElement;
        if (!parent) return null;
        try {
            if (mode === 'parent') return parent;
            if (mode === 'container') return parent.closest('.brxe-container');
            if (mode === 'section') return parent.closest('section, .brxe-section');
            if (mode === 'custom') {
                var sel = table.getAttribute('data-immoadmin-filter-hide-selector');
                return sel ? parent.closest(sel) : null;
            }
        } catch (e) {
            return null; // invalid selector typed in the builder
        }
        return null;
    }

    function setHidden(el, hidden) {
        if (!el) return;
        if (hidden) el.setAttribute(HIDDEN_ATTR, '');
        else el.removeAttribute(HIDDEN_ATTR);
    }

    function emptyMessage(table, show) {
        var grid = table.querySelector('.immoadmin-table');
        if (!grid) return;
        var msg = grid.querySelector(':scope > .immoadmin-filter-empty');
        if (!show) {
            if (msg) setHidden(msg, true);
            return;
        }
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'immoadmin-table-empty immoadmin-filter-empty';
            msg.setAttribute('role', 'row');
            var cell = document.createElement('div');
            cell.className = 'immoadmin-table-cell';
            cell.setAttribute('role', 'cell');
            cell.textContent = table.getAttribute('data-immoadmin-filter-empty-text') || 'Keine passenden Wohnungen';
            msg.appendChild(cell);
            grid.appendChild(msg);
        }
        setHidden(msg, false);
        grid.appendChild(msg); // keep it below the (hidden) rows after a sort
    }

    function updateCounts(scope, count) {
        if (!scope) return;
        each(scope.querySelectorAll('[data-immoadmin-count]'), function (el) {
            var tpl = (count === 1 && el.hasAttribute('data-immoadmin-count-one'))
                ? el.getAttribute('data-immoadmin-count-one')
                : (count === 0 && el.hasAttribute('data-immoadmin-count-zero'))
                    ? el.getAttribute('data-immoadmin-count-zero')
                    : el.getAttribute('data-immoadmin-count');
            tpl = tpl || '';
            var text = tpl.indexOf('{count}') !== -1
                ? tpl.split('{count}').join(String(count))
                : (tpl ? count + ' ' + tpl : String(count));
            if (el.textContent !== text) el.textContent = text;
        });
    }

    // ------------------------------------------------------------ apply

    function commit(f) {
        f.committed = f.impl.criterion();
        f.pending = false;
    }

    function coveringActions(f, tables) {
        return actions.filter(function (a) { return L.actionCovers(a.spec, f.spec, tables); });
    }

    function coveredFilters(a, tables) {
        return filters.filter(function (f) { return L.actionCovers(a.spec, f.spec, tables); });
    }

    function isDeferred(f, tables) {
        // An actions element on "Beim Klick auf Suchen" (default) defers;
        // no responsible actions element → instant.
        var mine = coveringActions(f, tables);
        for (var i = 0; i < mine.length; i++) {
            if (mine[i].el.getAttribute('data-apply-on') !== 'change') return true;
        }
        return false;
    }

    // Show / hide rows and tables from the COMMITTED state of the filters
    // (what the visitor searched), never from unsaved changes.
    function render() {
        var tables = pageTables();
        var present = L.groupsPresent(tables);
        var wrappers = []; // [{ el, hidden: [bool] }]
        var summary = [];

        tables.forEach(function (t) {
            var table = t.el;
            var rows = rowsOf(table);
            if (!rows.length && hasRowsWithoutValues(table)) {
                setHidden(table, false);
                return;
            }
            var criteria = [];
            filters.forEach(function (f) {
                if (f.committed && L.appliesTo(f.spec, t, present)) criteria.push(f.committed);
            });
            var values = rows.map(rowValues);
            var res = L.evaluateTable(values, criteria);

            rows.forEach(function (row, i) {
                var hide = !res.visible[i];
                if (hide) closeRow(row);
                setHidden(row, hide);
            });

            var emptyMode = table.getAttribute('data-immoadmin-filter-empty') || 'hide';
            var noHits = res.anyActive && res.count === 0;
            var tableHidden = res.hiddenByScope || (noHits && emptyMode === 'hide');
            setHidden(table, tableHidden);
            emptyMessage(table, !tableHidden && noHits && emptyMode === 'message');

            var wrapper = hideWrapperOf(table);
            if (wrapper) {
                var entry = null;
                for (var w = 0; w < wrappers.length; w++) if (wrappers[w].el === wrapper) entry = wrappers[w];
                if (!entry) { entry = { el: wrapper, hidden: [] }; wrappers.push(entry); }
                entry.hidden.push(tableHidden);
            }

            updateCounts(wrapper || table.parentElement, res.count);
            summary.push({ table: table, id: t.id, count: res.count, hidden: tableHidden });
        });

        // A wrapper holding several tables only disappears with the last one.
        wrappers.forEach(function (entry) {
            setHidden(entry.el, entry.hidden.every(Boolean));
        });

        syncActions(tables);
        document.dispatchEvent(new CustomEvent('immoadmin/filter/applied', {
            detail: {
                criteria: L.activeCriteria(filters.map(function (f) { return f.committed; })),
                tables: summary
            }
        }));
    }

    function syncActions(tables) {
        tables = tables || pageTables();
        actions.forEach(function (a) {
            var mine = coveredFilters(a, tables);
            var pending = mine.some(function (f) { return f.pending; });
            var anyActive = mine.some(function (f) { return L.isActive(f.impl.criterion()) || L.isActive(f.committed); });
            each(a.el.querySelectorAll('[data-immoadmin-filter-action="submit"]'), function (b) {
                b.classList.toggle('has-pending', pending);
            });
            each(a.el.querySelectorAll('[data-immoadmin-filter-action="reset"]'), function (b) {
                b.classList.toggle('immoadmin-no-active-filter', !anyActive);
            });
        });
    }

    function changed(f) {
        var tables = pageTables();
        if (isDeferred(f, tables)) {
            f.pending = true;
            syncActions(tables);
        } else {
            commit(f);
            render();
        }
    }

    function runAction(a, kind) {
        coveredFilters(a, pageTables()).forEach(function (f) {
            if (kind === 'reset') f.impl.reset();
            commit(f);
        });
        render();
    }

    // ------------------------------------------------------------ init

    function bindActions(a) {
        a.el.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-immoadmin-filter-action]');
            if (!btn || !a.el.contains(btn)) return;
            e.preventDefault();
            runAction(a, btn.getAttribute('data-immoadmin-filter-action') === 'reset' ? 'reset' : 'submit');
        });
    }

    function init() {
        L = L || window.ImmoAdminFilterLogic;
        if (!L) return; // filter-logic.js not there (yet) — the next trigger retries
        each(document.querySelectorAll('[data-immoadmin-filter]'), function (el) {
            if (el._immoadminBound) return;
            var type = el.getAttribute('data-immoadmin-filter');
            if (type !== 'buttons' && type !== 'range' && type !== 'actions') return;
            el._immoadminBound = true;
            if (type === 'actions') {
                var a = { el: el, spec: specOf(el) };
                actions.push(a);
                bindActions(a);
                return;
            }
            var f = { el: el, spec: specOf(el), pending: false, committed: null };
            var onChange = function () { changed(f); };
            f.impl = type === 'buttons' ? new ButtonsFilter(el, onChange) : new RangeFilter(el, onChange);
            commit(f); // state on arrival (normally: nothing selected)
            filters.push(f);
        });
        // Drop elements Bricks removed (AJAX popups etc.).
        filters = filters.filter(function (f) { return document.contains(f.el); });
        actions = actions.filter(function (a) { return document.contains(a.el); });
        // Re-apply the committed state — also to rows Bricks just swapped in.
        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // Safety net: scripts deferred / reordered by an optimisation plugin.
    window.addEventListener('load', init);
    // Rows swapped by Bricks (native filter/pagination on a single table):
    // re-apply the current state to the new rows.
    ['bricks/ajax/query_result/displayed', 'bricks/ajax/pagination/completed', 'bricks/ajax/load_page/completed', 'bricks/ajax/popup/loaded']
        .forEach(function (ev) { document.addEventListener(ev, init); });

    window.immoadminFiltersInit = init;
})();
