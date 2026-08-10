/**
 * ImmoAdmin Units Table — frontend init.
 *
 * Wraps in BricksFunction so we get re-init after Bricks AJAX swaps.
 * Pure vanilla JS, no jQuery.
 */
(function () {
    'use strict';

    function initImmoUnitsTable(table) {
        if (!table || table.dataset.immoBound === '1') return;
        table.dataset.immoBound = '1';

        var mode = table.dataset.mode || 'accordion';
        var inlineSort = table.dataset.inlineSort === '1';
        var urlState = table.dataset.urlState === '1';

        // ------------------------------------------------------------------
        // Scroll hint: show the animated swipe overlay only when the table
        // actually overflows, then hide on first scroll or after a timeout.
        // Hint markup is rendered server-side only when both horizontal_scroll
        // and scroll_hint_enabled controls are on.
        // ------------------------------------------------------------------
        initScrollHint(table);

        // ------------------------------------------------------------------
        // Bricks' "load more" appends each AJAX batch INSIDE the last rendered
        // loop item instead of into the grid, so rows from page 2 onwards end
        // up nested one level deep (page 3 inside page 2, and so on). Nothing
        // looks wrong — the rows use display:contents, so the grid lays them
        // out exactly as before — but anything walking grid.children then only
        // ever sees the first batch. Sorting silently applied to page 1 only.
        //
        // Hoisting every row back to be a direct child of the grid keeps the
        // DOM matching what the markup claims, so the sorter needs no special
        // case. Re-appending in document order preserves the current order.
        // ------------------------------------------------------------------
        var gridEl = table.querySelector('.immoadmin-table');
        var rowSelector = mode === 'accordion'
            ? '.accordion-item'
            : '.immoadmin-table-row:not(.immoadmin-table-header)';

        function normalizeRows() {
            if (!gridEl) return;
            var rows = gridEl.querySelectorAll(rowSelector);
            var nested = false;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].parentElement !== gridEl) { nested = true; break; }
            }
            // Bail when nothing is nested — this runs from a MutationObserver,
            // and re-appending on every mutation would both churn the DOM and
            // retrigger the observer forever.
            if (!nested) return;
            for (var j = 0; j < rows.length; j++) {
                gridEl.appendChild(rows[j]);
            }
        }

        if (gridEl && typeof MutationObserver === 'function') {
            normalizeRows();
            new MutationObserver(normalizeRows).observe(gridEl, { childList: true, subtree: true });
        }

        // ------------------------------------------------------------------
        // Accordion toggle (delegated; survives DOM swaps via BricksFunction
        // re-init).
        // ------------------------------------------------------------------
        if (mode === 'accordion') {
            table.addEventListener('click', function (e) {
                var trigger = e.target.closest('.accordion-title-wrapper');
                if (!trigger || !table.contains(trigger)) return;
                // Don't toggle if user clicked an interactive child (link, button).
                if (e.target.closest('a, button, input, select, textarea')) return;
                toggleRow(trigger);
            });
            table.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var trigger = e.target.closest('.accordion-title-wrapper');
                if (!trigger) return;
                e.preventDefault();
                toggleRow(trigger);
            });
        }

        // Reserved / sold / rented rows are rendered without a panel, without
        // tabindex and without .accordion-title-wrapper, so no toggle should
        // ever reach them. Belt & braces: read the status the server wrote and
        // refuse anyway — a stray class from a third-party script (or a Bricks
        // AJAX swap racing us) must not be able to resurrect the toggle.
        var RESTRICTED = ['reserved', 'sold', 'rented'];

        function isRestricted(trigger) {
            // data-status sits on .accordion-item in accordion mode and on the
            // row itself in table mode; closest() covers both.
            var scope = trigger.closest('[data-status]');
            return !!scope && RESTRICTED.indexOf(scope.getAttribute('data-status')) !== -1;
        }

        function toggleRow(trigger) {
            if (isRestricted(trigger)) return;

            var willOpen = !trigger.classList.contains('brx-open');

            // Single-open mode: before opening this row, close any other.
            if (willOpen && table.dataset.singleOpen === '1') {
                var others = table.querySelectorAll('.accordion-title-wrapper.brx-open');
                others.forEach(function (o) {
                    if (o !== trigger) {
                        o.classList.remove('brx-open');
                        o.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            trigger.classList.toggle('brx-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

            var item = trigger.closest('.accordion-item');
            var unitId = item ? item.getAttribute('data-unit-id') : null;
            var urlValue = item ? item.getAttribute('data-url-value') : null;
            var urlKey = table.dataset.urlKey || 'unit';

            // Dispatch Bricks-compat custom event.
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
        if (inlineSort) {
            var headerRow = table.querySelector('.immoadmin-table-header');
            if (headerRow) {
                headerRow.addEventListener('click', function (e) {
                    var th = e.target.closest('.immoadmin-table-cell-header[data-sortable="1"]');
                    if (!th) return;
                    sortByHeader(th);
                });
                headerRow.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    var th = e.target.closest('.immoadmin-table-cell-header[data-sortable="1"]');
                    if (!th) return;
                    e.preventDefault();
                    sortByHeader(th);
                });
            }
        }

        function sortByHeader(th) {
            var grid = table.querySelector('.immoadmin-table');
            if (!grid) return;

            // Belt & braces: the observer normally has this done already, but
            // a click landing in the same tick as a load-more insert would
            // otherwise sort a partial row list.
            normalizeRows();

            var colIndex = parseInt(th.dataset.colIndex || '0', 10);
            var current = th.dataset.sortDirection || '';
            var dir = current === 'asc' ? 'desc' : 'asc';

            // Reset all other headers.
            grid.querySelectorAll('.immoadmin-table-cell-header').forEach(function (h) {
                if (h !== th) h.removeAttribute('data-sort-direction');
            });
            th.setAttribute('data-sort-direction', dir);

            // Pick units to sort: in accordion mode rows live inside .accordion-item.
            var units = Array.prototype.slice.call(grid.children).filter(function (n) {
                return n.classList.contains('accordion-item') ||
                    (n.classList.contains('immoadmin-table-row') && !n.classList.contains('immoadmin-table-header'));
            });
            if (!units.length) return;

            units.sort(function (a, b) {
                var av = readSortValue(a, colIndex);
                var bv = readSortValue(b, colIndex);

                var an = parseFloat(av);
                var bn = parseFloat(bv);
                var bothNumeric = !isNaN(an) && !isNaN(bn) && av !== '' && bv !== '';

                var cmp;
                if (bothNumeric) {
                    cmp = an - bn;
                } else {
                    cmp = String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' });
                }
                return dir === 'asc' ? cmp : -cmp;
            });

            units.forEach(function (u) { grid.appendChild(u); });
        }

        function readSortValue(unit, colIndex) {
            // In accordion mode the row is .accordion-title-wrapper inside .accordion-item;
            // in table mode the unit IS the row.
            var row = unit.classList.contains('immoadmin-table-row')
                ? unit
                : unit.querySelector('.immoadmin-table-row');
            if (!row) return '';
            var cell = row.querySelector('.immoadmin-table-cell[data-col-index="' + colIndex + '"]');
            if (!cell) return '';
            var v = cell.getAttribute('data-sort-value');
            return v == null ? '' : v;
        }

        // ------------------------------------------------------------------
        // URL state on load — open the accordion whose data-url-value matches
        // ?{urlKey}=VALUE (or #{urlKey}-VALUE), then scroll it into view.
        // urlKey is configurable per element (default "unit").
        // ------------------------------------------------------------------
        var urlKeyOnLoad = table.dataset.urlKey || 'unit';
        var requested = null;
        try {
            var urlObj = new URL(window.location.href);
            requested = urlObj.searchParams.get(urlKeyOnLoad);
            if (!requested && location.hash.indexOf('#' + urlKeyOnLoad + '-') === 0) {
                requested = location.hash.slice(urlKeyOnLoad.length + 2);
            }
        } catch (_) {
            requested = null;
        }

        if (requested) {
            // Match by data-url-value (per-row resolved DD); fall back to
            // data-unit-id for backwards compat with older rendered pages.
            var item = table.querySelector('[data-url-value="' + cssEscape(requested) + '"]') ||
                       table.querySelector('[data-unit-id="' + cssEscape(requested) + '"]');
            if (item) {
                var trigger = item.querySelector('.accordion-title-wrapper') ||
                    (item.classList.contains('accordion-title-wrapper') ? item : null);
                if (trigger && !trigger.classList.contains('brx-open')) {
                    trigger.classList.add('brx-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
                item.classList.add('is-highlighted');
                setTimeout(function () { item.classList.remove('is-highlighted'); }, 2000);
                if (typeof item.scrollIntoView === 'function') {
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }
    }

    function cssEscape(s) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(s);
        return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
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

        // Pick the right label for the input device:
        // (hover: hover) + (pointer: fine) ≈ mouse-driven desktop. Touch-only,
        // hybrid (pen), or coarse-pointer devices keep the touch label.
        // Matches at load only — re-checking on the fly would just flip text
        // mid-animation if the user happens to plug in a mouse.
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
                // No overflow yet — keep listening for later layout changes
                // (font load, async data, container resize) but don't show.
                hint.removeAttribute('data-active');
            }
        }

        function onScroll() {
            // Any user-initiated horizontal scroll = user understood. Hide.
            if (scroller.scrollLeft > 2) hideHint();
        }

        scroller.addEventListener('scroll', onScroll, { passive: true });

        // ResizeObserver covers: window resize, container size changes,
        // table swap via Bricks AJAX, font load shifts.
        var resizeObs = null;
        if (typeof ResizeObserver === 'function') {
            resizeObs = new ResizeObserver(checkOverflow);
            resizeObs.observe(scroller);
            // Also observe the inner table so column-width changes (sort, filter
            // result reshuffle) re-trigger the check.
            var inner = scroller.querySelector('.immoadmin-table');
            if (inner) resizeObs.observe(inner);
        }

        // Initial check + auto-hide timer.
        checkOverflow();
        hideTimer = setTimeout(hideHint, AUTO_HIDE_MS);
    }

    function initAll() {
        document.querySelectorAll('[data-element="immoadmin-units-table"]').forEach(initImmoUnitsTable);
    }

    // Bootstrap. initImmoUnitsTable is idempotent (dataset.immoBound flag) so
    // we can re-run on every Bricks AJAX swap without harm.
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
})();
