/**
 * ImmoAdmin filter widgets — page controller (v2.14.0).
 *
 * Connects every element with data-immoadmin-filter-group="X":
 *   - filters  [data-immoadmin-filter="buttons" | "range"]
 *   - actions  [data-immoadmin-filter="actions"]  (Suchen / Zurücksetzen)
 *   - tables   [data-element="immoadmin-units-table"][data-immoadmin-filter-group]
 *
 * Client-side only: rows carry their values in data-immoadmin-filter-values
 * (prices already redacted server-side). Matching lives in filter-logic.js.
 *
 * Never touches a table without a group, never touches Bricks' own filters.
 */
(function () {
    'use strict';

    var L = window.ImmoAdminFilterLogic;
    if (!L) return;

    var ROW_ATTR = 'data-immoadmin-filter-values';
    var HIDDEN_ATTR = 'data-immoadmin-filter-hidden';
    var groups = {};

    function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }

    function parseJSON(s, fallback) {
        try { return s ? JSON.parse(s) : fallback; } catch (e) { return fallback; }
    }

    function getGroup(name) {
        if (!groups[name]) {
            groups[name] = { name: name, filters: [], actions: [], pending: false, applied: [] };
        }
        return groups[name];
    }

    function isDeferred(group) {
        // Any actions element set to "Beim Klick auf Suchen" (default) defers.
        // No actions element at all → instant.
        for (var i = 0; i < group.actions.length; i++) {
            if (group.actions[i].getAttribute('data-apply-on') !== 'change') return true;
        }
        return false;
    }

    // ------------------------------------------------------------ filters

    function ButtonsFilter(el, group) {
        var self = this;
        this.el = el;
        this.group = group;
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
        changed(this.group);
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

    function RangeFilter(el, group) {
        var self = this;
        this.el = el;
        this.group = group;
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
            changed(self.group);
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

    function tablesOf(groupName) {
        var out = [];
        each(document.querySelectorAll('[data-element="immoadmin-units-table"][data-immoadmin-filter-group]'), function (t) {
            if (t.getAttribute('data-immoadmin-filter-group') === groupName && t.getAttribute('data-builder') !== '1') out.push(t);
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

    function currentCriteria(group) {
        return group.filters.map(function (f) { return f.criterion(); }).filter(Boolean);
    }

    // criteria omitted → take the filters' current state (Suchen / instant
    // change). Passed in → re-apply what was applied before (after Bricks
    // swapped rows), without committing changes the visitor has not searched.
    function apply(group, criteria) {
        if (!criteria) {
            criteria = currentCriteria(group);
            group.applied = criteria;
            group.pending = false;
        }

        var wrappers = []; // [{ el, tables: [{hidden}] }]
        var summary = [];

        tablesOf(group.name).forEach(function (table) {
            var rows = rowsOf(table);
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
            summary.push({ table: table, count: res.count, hidden: tableHidden });
        });

        // A wrapper holding several tables only disappears with the last one.
        wrappers.forEach(function (entry) {
            setHidden(entry.el, entry.hidden.every(Boolean));
        });

        syncActions(group);
        document.dispatchEvent(new CustomEvent('immoadmin/filter/applied', {
            detail: { group: group.name, criteria: L.activeCriteria(criteria), tables: summary }
        }));
    }

    function syncActions(group) {
        var anyActive = L.activeCriteria(currentCriteria(group)).length > 0 || L.activeCriteria(group.applied).length > 0;
        group.actions.forEach(function (a) {
            each(a.querySelectorAll('[data-immoadmin-filter-action="submit"]'), function (b) {
                b.classList.toggle('has-pending', group.pending);
            });
            each(a.querySelectorAll('[data-immoadmin-filter-action="reset"]'), function (b) {
                b.classList.toggle('immoadmin-no-active-filter', !anyActive);
            });
        });
    }

    function changed(group) {
        if (isDeferred(group)) {
            group.pending = true;
            syncActions(group);
        } else {
            apply(group);
        }
    }

    function reset(group) {
        group.filters.forEach(function (f) { f.reset(); });
        apply(group);
    }

    // ------------------------------------------------------------ init

    function bindActions(el, group) {
        el.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-immoadmin-filter-action]');
            if (!btn || !el.contains(btn)) return;
            e.preventDefault();
            if (btn.getAttribute('data-immoadmin-filter-action') === 'reset') reset(group);
            else apply(group);
        });
    }

    function init() {
        var touched = {};
        each(document.querySelectorAll('[data-immoadmin-filter][data-immoadmin-filter-group]'), function (el) {
            var name = el.getAttribute('data-immoadmin-filter-group');
            if (!name) return;
            var group = getGroup(name);
            touched[name] = true;
            if (el._immoadminBound) return;
            el._immoadminBound = true;
            var type = el.getAttribute('data-immoadmin-filter');
            if (type === 'buttons') group.filters.push(new ButtonsFilter(el, group));
            else if (type === 'range') group.filters.push(new RangeFilter(el, group));
            else if (type === 'actions') { group.actions.push(el); bindActions(el, group); }
        });
        // Tables that joined a group without any filter still get counts.
        each(document.querySelectorAll('[data-element="immoadmin-units-table"][data-immoadmin-filter-group]'), function (t) {
            touched[t.getAttribute('data-immoadmin-filter-group')] = true;
        });
        Object.keys(touched).forEach(function (name) {
            var group = getGroup(name);
            // Drop filter elements Bricks removed (AJAX popups etc.).
            group.filters = group.filters.filter(function (f) { return document.contains(f.el); });
            group.actions = group.actions.filter(function (a) { return document.contains(a); });
            if (group.initialized) {
                apply(group, group.applied);
            } else {
                group.initialized = true;
                apply(group);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // Rows swapped by Bricks (native filter/pagination on a single table):
    // re-apply the current state to the new rows.
    ['bricks/ajax/query_result/displayed', 'bricks/ajax/pagination/completed', 'bricks/ajax/load_page/completed', 'bricks/ajax/popup/loaded']
        .forEach(function (ev) { document.addEventListener(ev, init); });

    window.immoadminFiltersInit = init;
})();
