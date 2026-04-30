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

        function toggleRow(trigger) {
            var willOpen = !trigger.classList.contains('brx-open');
            trigger.classList.toggle('brx-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

            var item = trigger.closest('.accordion-item');
            var unitId = item ? item.getAttribute('data-unit-id') : null;

            // Dispatch Bricks-compat custom event.
            document.dispatchEvent(new CustomEvent(
                willOpen ? 'bricks/accordion/open' : 'bricks/accordion/close',
                { detail: { elementId: table.dataset.bricksQueryId || '', unitId: unitId } }
            ));

            if (urlState && unitId) {
                var url = new URL(window.location.href);
                if (willOpen) {
                    url.searchParams.set('unit', unitId);
                } else if (url.searchParams.get('unit') === unitId) {
                    url.searchParams.delete('unit');
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
        // URL state on load — open ?unit=N or #unit-N and scroll into view.
        // ------------------------------------------------------------------
        var requested = null;
        try {
            var urlObj = new URL(window.location.href);
            requested = urlObj.searchParams.get('unit');
            if (!requested && location.hash.indexOf('#unit-') === 0) {
                requested = location.hash.slice(6);
            }
        } catch (_) {
            requested = null;
        }

        if (requested) {
            var item = table.querySelector('[data-unit-id="' + cssEscape(requested) + '"]');
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

    function initAll() {
        document.querySelectorAll('[data-element="immoadmin-units-table"]').forEach(initImmoUnitsTable);
    }

    // Use BricksFunction wrapper if available (preferred — handles AJAX re-init).
    if (typeof window.BricksFunction === 'function') {
        // eslint-disable-next-line no-new
        new window.BricksFunction({
            parentNode: document,
            selector: '[data-element="immoadmin-units-table"]',
            frontEndOnly: true,
            subscribeEvents: [
                'bricks/ajax/query_result/displayed',
                'bricks/ajax/pagination/completed',
                'bricks/ajax/load_page/completed',
                'bricks/ajax/popup/loaded'
            ],
            eachElement: initImmoUnitsTable
        });
    } else {
        // Fallback bootstrap.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAll);
        } else {
            initAll();
        }
        document.addEventListener('bricks/ajax/query_result/displayed', initAll);
        document.addEventListener('bricks/ajax/pagination/completed', initAll);
        document.addEventListener('bricks/ajax/load_page/completed', initAll);
    }

    // Expose handler name for Bricks $scripts auto-init.
    window.bricksUnitsTableInit = initAll;
})();
