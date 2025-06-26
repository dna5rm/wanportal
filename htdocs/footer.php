<!-- Footer Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>

<script>
    $(document).ready(function() {
        $('#tablePager').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, "asc"]],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search records..."
            },
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]]
        });
    });

    $(document).ready(function() {
        $('.searchable-select').each(function() {
            $(this).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $(this).find('option:first').text(), // Uses first option as placeholder
                allowClear: false,
                selectionCssClass: 'form-select',
            });
        });
    });

    // Wait for the document to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
            html: true,
            placement: 'auto'
        }));

        // Initialize all dropdowns
        const dropdownElementList = document.querySelectorAll('.dropdown-toggle');
        const dropdownList = [...dropdownElementList].map(dropdownToggleEl => new bootstrap.Dropdown(dropdownToggleEl));

        // Only initialize filters if the elements exist
        const searchFilter = document.getElementById('searchFilter');
        const protocolFilter = document.getElementById('protocolFilter');
        const showInactive = document.getElementById('showInactive');

        if (searchFilter) {
            searchFilter.addEventListener('input', filterMonitors);
        }
        if (protocolFilter) {
            protocolFilter.addEventListener('change', filterMonitors);
        }
        if (showInactive) {
            showInactive.addEventListener('change', function() {
                // Update URL with new show_inactive state
                const url = new URL(window.location);
                url.searchParams.set('show_inactive', this.checked);
                window.location = url;
            });
        }
    });

    // Filter functionality - only defined if needed
    function filterMonitors() {
        const search = document.getElementById('searchFilter').value.toLowerCase();
        const protocol = document.getElementById('protocolFilter').value.toUpperCase();

        console.log('Search:', search);
        console.log('Protocol:', protocol);

        const rows = document.querySelectorAll('tbody tr');

        rows.forEach(row => {
            if (row.cells.length === 1) return;

            const description = row.cells[0].textContent.toLowerCase();
            const target = row.cells[1].textContent.toLowerCase();
            const rowProtocol = row.cells[2].textContent.toUpperCase();

            console.log('Row Protocol:', rowProtocol);

            const searchMatch = description.includes(search) ||
                            target.includes(search);
            const protocolMatch = !protocol || rowProtocol.includes(protocol);

            row.style.display = (searchMatch && protocolMatch) ? '' : 'none';
        });
    }

    // Optional: Add any page-specific scripts that were defined
    if (typeof pageSpecificScripts === 'function') {
        pageSpecificScripts();
    }
</script>

<?php if (isset($additional_scripts)): ?>
    <?php foreach ($additional_scripts as $script): ?>
        <script src="<?= htmlspecialchars($script) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>