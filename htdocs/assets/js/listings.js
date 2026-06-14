// assets/js/listings.js
//
// DataTables initialization for the wanportal's listing pages
// (agents, targets, monitors, users, credentials). This is a small
// wrapper that gives every listing a consistent UX:
//   - Sortable column headers (click to sort, shift-click to multi-sort)
//   - Global search box (DataTables' built-in; covers every column)
//   - Pagination at 25/50/100 per page
//   - URL-driven state (page, search, sort survive reloads via
//     stateSave; we don't currently serialize to URL but the option
//     is there if you want it later)
//   - Consistent "Showing X to Y of Z" footer
//
// Usage: each listing page renders a <table id="tablePager" class="...">
// and the footer auto-initializes it. To customize per-page, the page
// can add data-* attributes to the table:
//   data-page-length="50"       default page size
//   data-order='[[3, "desc"]]'  default sort column
//                               (NOTE: column index is 0-based and
//                                MUST be in range [0, N-1] where N
//                                is the number of <th> cells. An
//                                out-of-range index causes DataTables
//                                to throw "Cannot read properties of
//                                undefined (reading 'aDataSort')"
//                                at init, which silently kills all
//                                DataTables features on the page --
//                                the table renders as raw HTML with
//                                no search/length/pagination. To
//                                verify, hit the page in a real
//                                browser and confirm `.dataTables_length`
//                                is present in the DOM.)
//   data-empty-message="..."    body message shown when the table
//                               starts with zero records (replaces
//                               a hand-rolled <td colspan> row)
//   data-no-search="true"        disable the search box
//   data-no-paginate="true"      disable pagination (e.g. for a small list)

(function () {
    'use strict';

    // Shared site-wide "Show X per page" preference. Stored under
    // a single localStorage key (not DataTables' own per-page state)
    // so the user's choice carries across every listing page in the
    // app. Read by getSharedPageLength() during init, written by
    // setSharedPageLength() on every change of the length <select>.
    // The key namespace matches the existing wanportal-* convention
    // used by the dark-mode toggle in footer.php.
    var PAGE_LENGTH_KEY = 'wanportal-page-length';
    var DEFAULT_PAGE_LENGTH = 25;

    function getSharedPageLength() {
        try {
            var raw = localStorage.getItem(PAGE_LENGTH_KEY);
            var n = parseInt(raw, 10);
            // A valid length is a positive integer; -1 (DataTables'
            // "all" sentinel) is also accepted and means "show all".
            if (n === -1 || (n > 0 && isFinite(n))) {
                return n;
            }
        } catch (e) { /* localStorage may be disabled; fall through */ }
        return DEFAULT_PAGE_LENGTH;
    }

    function setSharedPageLength(value) {
        try {
            localStorage.setItem(PAGE_LENGTH_KEY, String(value));
        } catch (e) { /* localStorage may be disabled; ignore */ }
    }

    function initTable(table) {
        if (!table || !window.jQuery || !window.jQuery.fn.DataTable) {
            return;
        }
        // Don't double-initialize if the page already initialized it.
        if (window.jQuery(table).data('dt-initialized')) {
            return;
        }

        var $t = window.jQuery(table);
        var opts = {
            responsive: true,
            // Persist per-page state (sort, search, current page) to
            // localStorage, scoped per-table-per-page. The page
            // length is NOT handled by stateSave -- we override it
            // below from a shared key so the user's "Show X per
            // page" choice is site-wide across all listing pages.
            stateSave: table.dataset.stateSave !== 'false',
            pageLength: getSharedPageLength(),
            order: parseOrderAttr(table.dataset.order),
            language: {
                search: '',
                searchPlaceholder: 'Search...',
                lengthMenu: 'Show _MENU_ per page',
                info: 'Showing _START_ to _END_ of _TOTAL_',
                infoEmpty: 'No records',
                // Shown in the body when the table starts with zero
                // records. Per-page override via the
                // `data-empty-message` attribute on the <table>.
                // (Previously each page hand-rolled a
                // `<td colspan="N">No X found</td>` row, but
                // DataTables 1.13 raises an "Incorrect column
                // count" warning (tn/18) when any body row has a
                // different cell count than the header. Using
                // `emptyTable` instead is the DataTables-idiomatic
                // way to show a custom empty-state message.)
                emptyTable: table.dataset.emptyMessage || 'No records',
                zeroRecords: 'No matching records',
                paginate: { previous: '‹', next: '›' }
            },
            // Bootstrap 5 integration classes
            dom: "<'row'<'col-md-6'l><'col-md-6'f>>" +
                 "<'row'<'col-12'tr>>" +
                 "<'row'<'col-md-5'i><'col-md-7'p>>",
        };
        if (table.dataset.noSearch === 'true') {
            // Strip the 'f' (filter) element from the dom
            opts.dom = opts.dom.replace(/<\'col-md-6\'f>/, '');
        }
        if (table.dataset.noPaginate === 'true') {
            // Strip the 'p' (pagination) element
            opts.dom = opts.dom.replace(/<\'col-md-7\'p>/, '');
            opts.paging = false;
        }

        $t.DataTable(opts);
        $t.data('dt-initialized', true);

        // Persist the user's "Show X per page" choice to a single
        // shared localStorage key (wanportal-page-length) so the
        // choice is site-wide: pick "10 per page" on agent.php, then
        // go to target.php, home, etc. -- they all show 10 per page.
        // The shared key is read at init time via getSharedPageLength()
        // above. We hook the change event on the length <select> the
        // DataTables plugin renders; this fires for both the dropdown
        // AND the -1 (all) sentinel.
        var lengthSelect = $t.closest('.dataTables_wrapper').find('select[name^="tablePager_length"], select[name$="_length"]').first();
        if (lengthSelect.length) {
            lengthSelect.on('change', function () {
                setSharedPageLength(this.value);
            });
        }

        // Hook the "show inactive" toggle if the page has one. The
        // toggle is a checkbox with id="showInactive" that, when
        // toggled, hides rows whose first cell text equals the
        // "Inactive" badge (or whose class is table-secondary).
        var toggle = document.getElementById('showInactive');
        if (toggle) {
            toggle.addEventListener('change', function () {
                var showAll = this.checked;
                // We use DataTables' column search API to filter
                // to either "all" or "active only" by hiding the
                // table-secondary rows. Simpler: redraw the table
                // with a regex search.
                if (showAll) {
                    $t.DataTable().column(0).search('').draw();
                } else {
                    // Match rows whose status cell text is "Active".
                    // The status column is the one with the
                    // "Active"/"Inactive" badge.
                    $t.DataTable().column(0).search('', false, false).draw();
                    // Crude: iterate rendered rows and hide the
                    // table-secondary ones.
                    $t.find('tbody tr.table-secondary').hide();
                }
            });
            // Initialize in the right state on page load.
            if (!toggle.checked) {
                $t.find('tbody tr.table-secondary').hide();
            }
        }
    }

    // data-order attribute parser. Format: '[[3, "desc"], [0, "asc"]]'
    // is a JSON array of [column, dir] pairs. Default to sorting by
    // the first column ascending if the attribute is missing.
    function parseOrderAttr(s) {
        if (!s) { return [[0, 'asc']]; }
        try {
            var parsed = JSON.parse(s);
            if (Array.isArray(parsed) && parsed.length > 0) {
                return parsed;
            }
        } catch (e) { /* fall through */ }
        return [[0, 'asc']];
    }

    // Auto-init every table with id="tablePager" or class
    // "table-dt" on DOMContentLoaded.
    function initAll() {
        var tables = document.querySelectorAll('#tablePager, table.table-dt');
        for (var i = 0; i < tables.length; i++) {
            initTable(tables[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
