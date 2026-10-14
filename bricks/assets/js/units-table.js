/**
 * ImmoAdmin Units Table — frontend init.
 *
 * Wraps in BricksFunction so we get re-init after Bricks AJAX swaps.
 * Pure vanilla JS, no jQuery.
 *
 * The DOM-free helpers at the top (Logic) are exported for plain-node tests
 * (tests/units-table-logic.test.js); the browser part below only runs when
 * there is a document.
 */
(function (root) {
    'use strict';

    // ======================================================================
    // Pure helpers — no document access. They only read .classList,
    // .children and .parentElement, so tests can feed plain objects.
    // ======================================================================

    function hasClass(node, cls) {
        return !!(node && node.classList && node.classList.contains(cls));
    }

    // A unit is one of two shapes:
    //   .accordion-item                 — the normal, expandable row
    //   .immoadmin-table-row (bare)     — reserved / sold / rented, or table
    //                                     mode; no accordion wrapper
    // The row nested inside an .accordion-item is that item's own title row
    // (.accordion-title-wrapper), not a unit of its own.
    function isUnitNode(node) {
        if (hasClass(node, 'accordion-item')) return true;
        return hasClass(node, 'immoadmin-table-row')
            && !hasClass(node, 'accordion-title-wrapper')
            && !hasClass(node, 'immoadmin-table-header');
    }

    /**
     * This grid's own units, in document order.
     *
     * Walks .children instead of querySelectorAll(): the detail panel can
     * hold anything — a Bricks "Accordion" element (.accordion-item /
     * .accordion-title-wrapper), even another units table — and none of that
     * may be mistaken for a row of THIS table. Panels, nested tables and the
     * header are never entered.
     *
     * Bricks' "load more" appends each AJAX batch right after the last
     * `.brxe-{id}` element (its query trail), which is the title row INSIDE
     * the last .accordion-item. So a unit can sit one level deep per page in
     * another unit; descending into units (not into panels) finds those.
     */
    function collectUnits(grid) {
        var out = [];
        (function walk(parent) {
            var kids = parent && parent.children ? parent.children : [];
            for (var i = 0; i < kids.length; i++) {
                var n = kids[i];
                if (hasClass(n, 'immoadmin-accordion-panel') || hasClass(n, 'immoadmin-table')
                    || hasClass(n, 'immoadmin-table-header') || hasClass(n, 'accordion-title-wrapper')) {
                    continue;
                }
                if (isUnitNode(n)) {
                    out.push(n);
                    walk(n);
                }
            }
        })(grid);
        return out;
    }

    /**
     * True if node belongs to THIS grid's row structure: walking up to the
     * grid crosses no accordion panel and no other table.
     */
    function isOwnNode(node, grid) {
        var n = node;
        while (n && n !== grid) {
            if (n !== node && (hasClass(n, 'immoadmin-accordion-panel') || hasClass(n, 'immoadmin-table'))) {
                return false;
            }
            n = n.parentElement;
        }
        return n === grid && !!grid;
    }

    /**
     * Zebra flags for the VISIBLE units, in order. Striped = 1st, 3rd, 5th …
     * visible unit — the same phase the old `.accordion-item:nth-of-type(even)`
     * produced on saved pages (the header was sibling #1, so the first unit
     * was "even"), now without hidden / filtered / restricted rows breaking it.
     */
    function zebraFlags(count) {
        var out = [];
        for (var i = 0; i < count; i++) out.push(i % 2 === 0);
        return out;
    }

    function compareSortValues(av, bv, dir) {
        av = av == null ? '' : String(av);
        bv = bv == null ? '' : String(bv);
        var an = parseFloat(av);
        var bn = parseFloat(bv);
        var cmp;
        if (!isNaN(an) && !isNaN(bn) && av !== '' && bv !== '') {
            cmp = an - bn;
        } else {
            cmp = av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
        }
        return dir === 'desc' ? -cmp : cmp;
    }

    /** ?key=VALUE or #key-VALUE → VALUE ('' when absent). */
    function requestedUnit(href, key) {
        key = key || 'unit';
        try {
            var url = new URL(href);
            var v = url.searchParams.get(key);
            if (v) return v;
            var prefix = '#' + key + '-';
            if (url.hash.indexOf(prefix) === 0) return decodeURIComponent(url.hash.slice(prefix.length));
        } catch (_) {
            return '';
        }
        return '';
    }

    /**
     * Does a grid with these resolved tracks (getComputedStyle → px list),
     * column gap and content width overflow? 1px tolerance for rounding.
     */
    function tracksOverflow(templateColumns, columnGap, available) {
        var sum = 0, n = 0;
        String(templateColumns || '').split(/\s+/).forEach(function (t) {
            var v = parseFloat(t);
            if (!isNaN(v)) { sum += v; n++; }
        });
        if (!n) return false;
        var gap = parseFloat(columnGap);
        if (!isNaN(gap) && n > 1) sum += gap * (n - 1);
        return sum > available + 1;
    }

    var INTERACTIVE = 'a[href], button, input, select, textarea, summary, label, [contenteditable]:not([contenteditable="false"])';

    var Logic = {
        isUnitNode: isUnitNode,
        collectUnits: collectUnits,
        isOwnNode: isOwnNode,
        zebraFlags: zebraFlags,
        compareSortValues: compareSortValues,
        requestedUnit: requestedUnit,
        tracksOverflow: tracksOverflow,
        INTERACTIVE: INTERACTIVE
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = Logic;
    }
    if (typeof document === 'undefined') {
        return;
    }
    root.ImmoAdminUnitsTableLogic = Logic;

    // ======================================================================
    // Browser part
    // ======================================================================

    var RESTRICTED = ['reserved', 'sold', 'rented'];
    var FILTER_HIDDEN = 'data-immoadmin-filter-hidden';

    function initImmoUnitsTable(table) {
        if (!table || table.dataset.immoBound === '1') return;
        table.dataset.immoBound = '1';

        var mode = table.dataset.mode || 'accordion';
        var inlineSort = table.dataset.inlineSort === '1';
        var urlState = table.dataset.urlState === '1';

        initScrollHint(table);

        // The grid of THIS element (direct path, never one nested in a panel).
        var gridEl = table.querySelector(':scope > .immoadmin-table, :scope > .immoadmin-table-scroll > .immoadmin-table');
        if (!gridEl) return;

        function units() {
            return collectUnits(gridEl);
        }

        // Title row of a unit (the unit itself for a bare row).
        function rowOf(unit) {
            if (hasClass(unit, 'immoadmin-table-row')) return unit;
            var kids = unit.children;
            for (var i = 0; i < kids.length; i++) {
                if (hasClass(kids[i], 'immoadmin-table-row')) return kids[i];
            }
            return null;
        }

        function ownCells(row) {
            var out = [];
            if (!row) return out;
            for (var i = 0; i < row.children.length; i++) {
                if (hasClass(row.children[i], 'immoadmin-table-cell')) out.push(row.children[i]);
            }
            return out;
        }

        function toggleButtonOf(row) {
            var cells = ownCells(row);
            for (var i = 0; i < cells.length; i++) {
                var b = cells[i].querySelector(':scope > .immoadmin-row-toggle');
                if (b) return b;
            }
            return null;
        }

        // Hoist units Bricks' load-more nested into another unit back to be
        // direct grid children (in document order = current order).
        function normalizeRows() {
            var list = units();
            var nested = false;
            for (var i = 0; i < list.length; i++) {
                if (list[i].parentElement !== gridEl) { nested = true; break; }
            }
            // Bail when nothing is nested — this runs from a MutationObserver,
            // and re-appending on every mutation would churn the DOM forever.
            if (!nested) return;
            for (var j = 0; j < list.length; j++) gridEl.appendChild(list[j]);
        }

        function isHidden(unit) {
            if (unit.hasAttribute(FILTER_HIDDEN)) return true;
            var st = window.getComputedStyle ? window.getComputedStyle(unit) : null;
            return !!(st && st.display === 'none');
        }

        // Zebra (v2.15.4): class on the visual row, recomputed after init,
        // sort, filter and load-more, so hidden rows never shift the pattern.
        function applyZebra() {
            var visible = [];
            units().forEach(function (u) {
                var row = rowOf(u);
                if (!row) return;
                if (isHidden(u)) { row.classList.remove('is-even'); return; }
                visible.push(row);
            });
            var flags = zebraFlags(visible.length);
            for (var i = 0; i < visible.length; i++) visible[i].classList.toggle('is-even', flags[i]);
        }

        // Keep the toggle button's aria-expanded in line with the row state
        // (.brx-open on the title row is the single source of truth — the
        // filter script closes rows by removing it).
        // Closed panels get `inert` (v2.15.4): visibility:hidden alone is
        // not enough — e.g. Splide sets .splide.is-initialized{visibility:
        // visible}, which un-hides slider arrows inside a closed panel for Tab
        // and screen readers. Not in the builder, where the first panel is
        // shown open for styling and must stay clickable.
        var inBuilder = table.getAttribute('data-builder') === '1'
            || (document.body && document.body.getAttribute('data-builder-window') === 'iframe');

        function panelOf(row) {
            var n = row ? row.nextElementSibling : null;
            return hasClass(n, 'immoadmin-accordion-panel') ? n : null;
        }

        function setPanelInert(row, open) {
            var panel = panelOf(row);
            if (!panel) return;
            if (open || inBuilder) panel.removeAttribute('inert');
            else panel.setAttribute('inert', '');
        }

        // Keep button + panel in line with the row state (.brx-open on the
        // title row is the single source of truth — the filter script closes
        // rows by removing it).
        function syncToggles() {
            units().forEach(function (u) {
                var row = rowOf(u);
                if (!row) return;
                var open = hasClass(row, 'brx-open');
                var btn = toggleButtonOf(row);
                if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (row.hasAttribute('aria-expanded')) row.removeAttribute('aria-expanded');
                setPanelInert(row, open);
            });
        }

        // The toggle button must stay reachable when its cell's column is
        // hidden at this width ("Ausblenden auf" / mobile): move it into the
        // row's first visible cell.
        function relocateToggles() {
            units().forEach(function (u) {
                var row = rowOf(u);
                var btn = row ? toggleButtonOf(row) : null;
                if (!btn || btn.parentElement.getClientRects().length) return;
                var cells = ownCells(row);
                for (var i = 0; i < cells.length; i++) {
                    if (cells[i].getClientRects().length) {
                        cells[i].insertBefore(btn, cells[i].firstChild);
                        return;
                    }
                }
            });
        }

        // Whole words (v2.15.4). The CSS default breaks anywhere (the old
        // look — can't overflow, also without JS). .is-roomy switches to
        // whole words, but only when every column then still fits. The test
        // adds the class, reads the resolved tracks and takes it away again
        // in the same task, so nothing paints in between and the class only
        // changes when the result does. Not in horizontal-scroll mode (no
        // wrapping in max-content / auto tracks anyway).
        var isScroll = hasClass(gridEl.parentElement, 'immoadmin-table-scroll');
        function fitCheck() {
            if (isScroll || !window.getComputedStyle) return;
            gridEl.classList.add('is-roomy');
            var cs = window.getComputedStyle(gridEl);
            var available = gridEl.clientWidth
                - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
            var roomy = available > 0 && !tracksOverflow(cs.gridTemplateColumns, cs.columnGap, available);
            if (!roomy) gridEl.classList.remove('is-roomy');
        }

        // One layout pass per frame at most.
        var layoutQueued = false;
        function scheduleLayout() {
            if (layoutQueued) return;
            layoutQueued = true;
            var run = function () { layoutQueued = false; relocateToggles(); fitCheck(); };
            if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(run);
            else setTimeout(run, 16);
        }

        function refresh() {
            normalizeRows();
            applyZebra();
            syncToggles();
            scheduleLayout();
        }

        var pending = false;
        function scheduleRefresh() {
            if (pending) return;
            pending = true;
            var run = function () { pending = false; refresh(); };
            if (typeof Promise === 'function') Promise.resolve().then(run); else setTimeout(run, 0);
        }

        refresh();

        if (typeof MutationObserver === 'function') {
            // subtree is needed for load-more (batches land inside the last
            // .accordion-item); only mutations at grid level or one below
            // count — anything inside a panel (nested accordion, slider …)
            // is ignored.
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    var t = records[i].target;
                    if (t === gridEl || (t && t.parentElement === gridEl && !hasClass(t, 'immoadmin-table-header'))) {
                        scheduleRefresh();
                        return;
                    }
                }
            }).observe(gridEl, { childList: true, subtree: true });
        }
        document.addEventListener('immoadmin/filter/applied', scheduleRefresh);
        if (typeof ResizeObserver === 'function') {
            // Watch the element's WIDTH only: .is-roomy changes the height
            // (rows re-wrap), which must not trigger another pass. Work is
            // deferred to rAF, never done inside the observer callback.
            var lastWidth = -1;
            new ResizeObserver(function (entries) {
                var w = entries[0] && entries[0].contentRect ? Math.round(entries[0].contentRect.width) : -1;
                if (w === lastWidth) return;
                lastWidth = w;
                scheduleLayout();
            }).observe(table);
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(scheduleLayout);
        }

        // ------------------------------------------------------------------
        // Accordion toggle. The focusable element is the visually hidden
        // <button class="immoadmin-row-toggle"> in each openable row (rows
        // are display:contents and can't take focus); Enter / Space on it
        // fire a native click, so one click handler covers mouse + keyboard.
        // ------------------------------------------------------------------
        if (mode === 'accordion') {
            table.addEventListener('click', function (e) {
                var target = e.target;
                if (!target || !target.closest) return;
                var trigger = target.closest('.accordion-title-wrapper');
                if (!trigger || !isOwnNode(trigger, gridEl)) return;
                var toggleBtn = target.closest('.immoadmin-row-toggle');
                if (!toggleBtn) {
                    // Don't toggle if the user clicked an interactive child.
                    var interactive = target.closest(INTERACTIVE);
                    if (interactive && trigger.contains(interactive)) return;
                }
                toggleRow(trigger);
            });
        }

        function isRestricted(trigger) {
            var scope = trigger.closest('[data-status]');
            return !!scope && RESTRICTED.indexOf(scope.getAttribute('data-status')) !== -1;
        }

        function setOpen(row, open) {
            row.classList.toggle('brx-open', open);
            var btn = toggleButtonOf(row);
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            setPanelInert(row, open);
        }

        function toggleRow(trigger) {
            if (isRestricted(trigger)) return;

            var willOpen = !trigger.classList.contains('brx-open');

            // Single-open: close this table's other rows only.
            if (willOpen && table.dataset.singleOpen === '1') {
                units().forEach(function (u) {
                    var r = rowOf(u);
                    if (r && r !== trigger && hasClass(r, 'brx-open')) setOpen(r, false);
                });
            }

            setOpen(trigger, willOpen);

            var item = trigger.closest('.accordion-item');
            var unitId = item ? item.getAttribute('data-unit-id') : null;
            var urlValue = item ? item.getAttribute('data-url-value') : null;
            var urlKey = table.dataset.urlKey || 'unit';

            document.dispatchEvent(new CustomEvent(
                willOpen ? 'bricks/accordion/open' : 'bricks/accordion/close',
                { detail: { elementId: table.dataset.bricksQueryId || '', unitId: unitId } }
            ));

            if (urlState && urlValue) {
                var url = new URL(window.location.href);
                if (willOpen) {
                    url.searchParams.set(urlKey, urlValue);
                } else if (url.searchParams.get(urlKey) === urlValue) {
                    url.searchParams.delete(urlKey);
                }
                history.replaceState(null, '', url.toString());
            }
        }

        // ------------------------------------------------------------------
        // Inline column sort — client-side reorder.
        // ------------------------------------------------------------------
        var headerRow = gridEl.querySelector(':scope > .immoadmin-table-header');
        if (inlineSort && headerRow) {
            headerRow.addEventListener('click', function (e) {
                var th = e.target.closest('.immoadmin-table-cell-header[data-sortable="1"]');
                if (th) sortByHeader(th);
            });
            headerRow.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var th = e.target.closest('.immoadmin-table-cell-header[data-sortable="1"]');
                if (!th) return;
                var interactive = e.target.closest(INTERACTIVE);
                if (interactive && th.contains(interactive)) return;
                e.preventDefault();
                sortByHeader(th);
            });
        }

        function sortByHeader(th) {
            normalizeRows();

            var colIndex = parseInt(th.dataset.colIndex || '0', 10);
            var dir = th.dataset.sortDirection === 'asc' ? 'desc' : 'asc';

            if (headerRow) {
                for (var i = 0; i < headerRow.children.length; i++) {
                    var h = headerRow.children[i];
                    if (h === th) continue;
                    h.removeAttribute('data-sort-direction');
                    if (h.getAttribute('data-sortable') === '1') h.setAttribute('aria-sort', 'none');
                }
            }
            th.setAttribute('data-sort-direction', dir);
            th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');

            var list = units();
            if (!list.length) return;

            list.sort(function (a, b) {
                return compareSortValues(readSortValue(a, colIndex), readSortValue(b, colIndex), dir);
            });
            list.forEach(function (u) { gridEl.appendChild(u); });
            applyZebra();
        }

        function readSortValue(unit, colIndex) {
            var cells = ownCells(rowOf(unit));
            for (var i = 0; i < cells.length; i++) {
                if (cells[i].getAttribute('data-col-index') === String(colIndex)) {
                    var v = cells[i].getAttribute('data-sort-value');
                    return v == null ? '' : v;
                }
            }
            return '';
        }

        // ------------------------------------------------------------------
        // URL state on load — only when "URL-State aktiv" is on: open the
        // unit whose data-url-value matches ?{urlKey}=VALUE (or
        // #{urlKey}-VALUE), highlight it and scroll its first visible cell
        // into view (the row itself is display:contents and has no box).
        // ------------------------------------------------------------------
        if (urlState) {
            var requested = requestedUnit(window.location.href, table.dataset.urlKey || 'unit');
            if (requested) {
                var match = null;
                var list = units();
                // data-url-value first; data-unit-id = older rendered pages.
                ['data-url-value', 'data-unit-id'].some(function (attr) {
                    for (var i = 0; i < list.length; i++) {
                        if (list[i].getAttribute(attr) === requested) { match = list[i]; return true; }
                    }
                    return false;
                });
                if (match) {
                    var row = rowOf(match);
                    if (mode === 'accordion' && row && hasClass(row, 'accordion-title-wrapper')
                        && !hasClass(row, 'brx-open') && !isRestricted(row)) {
                        setOpen(row, true);
                    }
                    match.classList.add('is-highlighted');
                    setTimeout(function () { match.classList.remove('is-highlighted'); }, 2000);
                    var cells = ownCells(row);
                    for (var c = 0; c < cells.length; c++) {
                        if (cells[c].getClientRects().length) {
                            if (typeof cells[c].scrollIntoView === 'function') {
                                cells[c].scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     * Wire up the scroll-hint overlay for one units-table element.
     *
     * Activates the hint only when the scrollable wrapper actually overflows
     * (scrollWidth > clientWidth + tolerance). Hides it on first user scroll,
     * after AUTO_HIDE_MS, or as soon as overflow disappears (resize, font
     * load, table swap). Safe to call multiple times — re-runs the overflow
     * check against the current DOM state.
     */
    function initScrollHint(table) {
        var AUTO_HIDE_MS = 6000;
        var OVERFLOW_TOLERANCE_PX = 4; // sub-pixel rounding guard

        var scroller = table.querySelector('.immoadmin-table-scroll[data-scroll-hint="1"]');
        var hint = table.querySelector('.immoadmin-table-scroll-hint');
        if (!scroller || !hint) return;

        // (hover: hover) + (pointer: fine) ≈ mouse-driven desktop. Matches at
        // load only — re-checking on the fly would flip text mid-animation.
        var labelEl = hint.querySelector('.immoadmin-table-scroll-hint__label');
        if (labelEl && window.matchMedia) {
            var isDesktopMouse = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            var desktopLabel = hint.getAttribute('data-label-desktop');
            var touchLabel = hint.getAttribute('data-label-touch');
            var picked = isDesktopMouse ? desktopLabel : touchLabel;
            if (picked != null && picked !== '') {
                labelEl.textContent = picked;
            }
        }

        var hidden = false;
        var hideTimer = null;
        var resizeObs = null;

        function hideHint() {
            if (hidden) return;
            hidden = true;
            hint.removeAttribute('data-active');
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
            scroller.removeEventListener('scroll', onScroll);
            if (resizeObs) resizeObs.disconnect();
        }

        function showHint() {
            if (hidden) return;
            hint.setAttribute('data-active', '1');
        }

        function checkOverflow() {
            if (hidden) return;
            var overflows = scroller.scrollWidth - scroller.clientWidth > OVERFLOW_TOLERANCE_PX;
            if (overflows) {
                showHint();
            } else {
                hint.removeAttribute('data-active');
            }
        }

        function onScroll() {
            if (scroller.scrollLeft > 2) hideHint();
        }

        scroller.addEventListener('scroll', onScroll, { passive: true });

        if (typeof ResizeObserver === 'function') {
            resizeObs = new ResizeObserver(checkOverflow);
            resizeObs.observe(scroller);
            var inner = scroller.querySelector('.immoadmin-table');
            if (inner) resizeObs.observe(inner);
        }

        checkOverflow();
        hideTimer = setTimeout(hideHint, AUTO_HIDE_MS);
    }

    function initAll() {
        document.querySelectorAll('[data-element="immoadmin-units-table"]').forEach(initImmoUnitsTable);
    }

    // initImmoUnitsTable is idempotent (dataset.immoBound flag), so it can
    // re-run on every Bricks AJAX swap without harm.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    document.addEventListener('bricks/ajax/query_result/displayed', initAll);
    document.addEventListener('bricks/ajax/pagination/completed', initAll);
    document.addEventListener('bricks/ajax/load_page/completed', initAll);
    document.addEventListener('bricks/ajax/popup/loaded', initAll);

    // Expose handler name for Bricks $scripts auto-init.
    window.bricksUnitsTableInit = initAll;
})(typeof window !== 'undefined' ? window : this);
