/**
 * ImmoAdmin filter widgets — live "Ziel-Tabellen" list in the Bricks builder
 * (v2.15.0). Loaded in the builder main window only.
 *
 * The select options of the "Ziel-Tabellen" control are rendered by PHP from
 * the SAVED page (ImmoAdmin_Filter_Data::builder_target_options()). This
 * script keeps them current while editing: it reads the unsaved builder
 * state (header / content / footer + components), lists every ImmoAdmin
 * Units Table and writes the list into the control config — the same way
 * Bricks itself refreshes dependent select options (form element:
 * bricksData.elements.form.controls.mailchimpGroups.options + rerenderControls).
 *
 * Purely cosmetic: if Bricks internals change and this script cannot hook
 * in, the PHP list (saved state, refreshed on reload) still works.
 */
(function () {
    'use strict';

    var FILTERS = ['immoadmin-filter-buttons', 'immoadmin-filter-range', 'immoadmin-filter-actions'];
    var TABLE = 'immoadmin-units-table';
    var CONTROL = 'filter_targets';
    var server = null; // { options: {id: label}, external: [id] } from PHP
    var lastSig = '';

    function strip(s) {
        return String(s == null ? '' : s).replace(/<[^>]*>/g, '').trim();
    }

    // Same string as ImmoAdmin_Filter_Data::table_label().
    function tableLabel(el, suffix) {
        var label = strip(el.label) || 'Units Table';
        var b = (el.settings && el.settings.immoadmin_buildings) || [];
        if (!Array.isArray(b)) b = [b];
        b = b.map(strip).filter(Boolean);
        if (b.length) label += ' · ' + b.join(', ');
        label += ' #' + el.id;
        if (suffix) label += ' (' + suffix + ')';
        return label;
    }

    function globals() {
        var root = document.querySelector('.brx-body');
        var app = root && root.__vue_app__;
        return app && app.config && app.config.globalProperties;
    }

    function controlsOf(name) {
        var data = window.bricksData && window.bricksData.elements && window.bricksData.elements[name];
        return data && data.controls && data.controls[CONTROL] ? data.controls[CONTROL] : null;
    }

    function escapeHTML(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** [{id, label}] of all tables in the live builder state. */
    function liveTables(state) {
        var out = [];
        var seen = {};
        var seenComponents = {};
        var components = Array.isArray(state.components) ? state.components : [];
        function walk(list, suffix, depth) {
            if (!Array.isArray(list) || depth > 4) return;
            list.forEach(function (el) {
                if (!el || typeof el !== 'object') return;
                if (el.name === TABLE && el.id && !seen[el.id]) {
                    seen[el.id] = true;
                    out.push({ id: String(el.id), label: tableLabel(el, suffix) });
                }
                if (el.cid && !seenComponents[el.cid]) {
                    seenComponents[el.cid] = true;
                    for (var i = 0; i < components.length; i++) {
                        if (components[i] && components[i].id === el.cid) {
                            walk(components[i].elements, 'Komponente', depth + 1);
                        }
                    }
                }
            });
        }
        walk(state.header, '', 0);
        walk(state.content, '', 0);
        walk(state.footer, '', 0);
        if (state.activeComponent && Array.isArray(state.activeComponent.elements)) {
            walk(state.activeComponent.elements, 'Komponente', 1);
        }
        return out;
    }

    function buildOptions(state) {
        var options = {};
        liveTables(state).forEach(function (t) { options[t.id] = escapeHTML(t.label); });
        // Tables only the server can see (inside Template elements).
        (server.external || []).forEach(function (id) {
            if (!options[id] && server.options[id]) options[id] = server.options[id];
        });
        // Picked tables that are gone: keep them visible so they can be removed.
        var active = state.activeElement;
        var picked = active && active.settings && active.settings[CONTROL];
        if (Array.isArray(picked)) {
            picked.forEach(function (id) {
                if (!options[id]) options[id] = '#' + escapeHTML(id) + ' (nicht auf dieser Seite)';
            });
        }
        return options;
    }

    function refresh(gp) {
        var state = gp.$_state;
        if (!state) return;
        var options = buildOptions(state);
        var active = state.activeElement;
        var sig = JSON.stringify(options) + '|' + (active ? active.id : '');
        if (sig === lastSig) return;
        lastSig = sig;
        FILTERS.forEach(function (name) {
            var c = controlsOf(name);
            if (c) c.options = options;
        });
        if (active && FILTERS.indexOf(active.name) !== -1 && typeof gp.$_rerenderControls === 'function') {
            gp.$_rerenderControls();
        }
    }

    function start(tries) {
        var gp = globals();
        if (!gp || !gp.$_state || typeof gp.$_watch !== 'function' || !controlsOf(FILTERS[0])) {
            if (tries < 120) setTimeout(function () { start(tries + 1); }, 500);
            return;
        }
        var c = controlsOf(FILTERS[0]);
        server = {
            options: (c.options && typeof c.options === 'object' && !Array.isArray(c.options)) ? Object.assign({}, c.options) : {},
            external: Array.isArray(c.immoadminExternal) ? c.immoadminExternal.slice() : []
        };
        var state = gp.$_state;
        // Re-run whenever a table is added / removed / relabelled or another
        // element gets selected. The getter only reads what the list shows.
        gp.$_watch(function () {
            var parts = [];
            try {
                parts.push(state.activeElement ? state.activeElement.id : '');
                parts.push(JSON.stringify(state.activeElement && state.activeElement.settings ? state.activeElement.settings[CONTROL] || null : null));
                liveTables(state).forEach(function (t) { parts.push(t.label); });
            } catch (e) { /* never break the builder */ }
            return parts.join('|');
        }, function () {
            try { refresh(gp); } catch (e) { /* ignore */ }
        });
        try { refresh(gp); } catch (e) { /* ignore */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { start(0); });
    } else {
        start(0);
    }
})();
