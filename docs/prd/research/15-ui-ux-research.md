# UI And UX Research

> **⚠ Superseded — historical reference only.**
> This file is the original Bootstrap 5 + jQuery research that informed the pre-pivot PRD §15. The frontend stack was pivoted to **Django + React islands via django-vite + Mantine + Radix gaps + LinguiJS** during the 2026-05-25 library survey; the current stack is documented in [docs/architecture/2026-05-25-django-library-survey.md](../../architecture/2026-05-25-django-library-survey.md) §20 and in the rewritten PRD §15.
>
> Nothing here is normative anymore. The references below are preserved as a record of *what was considered* during the original PRD authoring; do not adopt anything from this file without first checking the post-pivot survey.

## Bootstrap

Bootstrap 5.x provides responsive grids, form layouts, tables, modals, and component patterns. For RackLab, Bootstrap supports a dense operational UI with less custom frontend code. Bootstrap 5 removed the jQuery dependency for its own components but remains the dominant pairing with jQuery for theming and third-party widget ecosystems.

Sources:

- Bootstrap grid: https://getbootstrap.com/docs/5.3/layout/grid/
- Bootstrap form layout: https://getbootstrap.com/docs/5.3/forms/layout/
- Bootstrap tables: https://getbootstrap.com/docs/5.3/content/tables/
- Bootstrap modals: https://getbootstrap.com/docs/5.3/components/modal/

## jQuery

jQuery 3.x is the standard client-side framework for the UI, chosen for stability, the largest plugin ecosystem in web history (DataTables, Select2, jQuery UI, Bootstrap-aware components), and accessibility for student maintainers. jQuery is in maintenance mode but continues to receive security patches and remains broadly compatible across browsers. Partial-page updates are handled by jQuery-driven AJAX fragment swaps against Django views that return HTML — a well-trodden Django pattern that any developer with a Django-and-jQuery background can pick up immediately.

Sources:

- jQuery API: https://api.jquery.com/
- DataTables: https://datatables.net/
- Select2: https://select2.org/

## Recommended Frontend Plugins

The blessed list of well-known libraries for RackLab's frontend needs, with project URLs for plugin authors and operators.

jQuery-flavored:

- DataTables: <https://datatables.net/>
- Select2: <https://select2.org/>
- blueimp jQuery File Upload: <https://github.com/blueimp/jQuery-File-Upload>
- jQuery Validate: <https://jqueryvalidation.org/>
- jstree: <https://www.jstree.com/>
- Toastr: <https://github.com/CodeSeven/toastr>
- bootbox.js: <https://bootboxjs.com/>

Vanilla but routinely used alongside jQuery:

- Flatpickr: <https://flatpickr.js.org/>
- SortableJS: <https://github.com/SortableJS/Sortable>
- Chart.js: <https://www.chartjs.org/>
- Cytoscape.js: <https://js.cytoscape.org/>
- Prism.js: <https://prismjs.com/>
- clipboard.js: <https://clipboardjs.com/>
- marked: <https://marked.js.org/>
- DOMPurify: <https://github.com/cure53/DOMPurify>

Non-jQuery exceptions (deliberate, deeply specialized):

- noVNC for KVM graphical console embedding: <https://novnc.com/>
- xterm.js for LXC and serial console embedding: <https://xtermjs.org/>
- TipTap (ProseMirror) for the docs-plugin editor: <https://tiptap.dev/>

## SSE In UI

MDN and WHATWG define SSE as one-way server-to-browser streaming through EventSource and `text/event-stream`. RackLab uses the native `EventSource` API directly and updates the DOM through jQuery handlers. There is no third-party SSE library required.

Sources:

- MDN SSE: https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events
- WHATWG SSE: https://html.spec.whatwg.org/dev/server-sent-events.html

## Design Impact

- Use server-rendered pages for normal workflows.
- Use jQuery-driven AJAX fragment swaps for forms, filters, wizard steps, status panes, approvals, and partial refresh.
- Use SSE for live timelines and logs via the native `EventSource` API + jQuery DOM updates.
- Reserve custom JS for console embedding, topology visualization, markdown preview, and event-stream glue.
- The docs plugin's TipTap editor is a deliberate exception to the jQuery default because rich-text editors need a ProseMirror-style schema model.
- Avoid SPA-only state because it complicates RBAC and duplicates API behavior.
