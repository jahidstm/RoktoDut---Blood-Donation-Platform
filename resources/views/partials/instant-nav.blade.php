{{-- Shared by the four dashboards. The browser loads a sidebar page as soon as the pointer rests
     on its link, so the click is instant and every page runs its own scripts normally. Browsers
     without support simply navigate as usual. Only sidebar links (data-tab) qualify: they are
     list pages, while opening a detail page can change data. The admin map is only prefetched,
     so its entry animation still plays when it opens. --}}
<script type="speculationrules">
{
    "prerender": [{ "where": { "selector_matches": "a[data-tab]:not([data-tab=heatmap])" }, "eagerness": "moderate" }],
    "prefetch":  [{ "where": { "selector_matches": "a[data-tab=heatmap]" }, "eagerness": "moderate" }]
}
</script>

{{-- A full page load resets the sidebar scroll; keep the current item in view. --}}
<script>
    (function () {
        const active = document.querySelector('a[data-tab].active');
        const bar = active?.closest('aside');
        if (bar) bar.scrollTop = active.offsetTop - bar.offsetTop - bar.clientHeight / 2;
    })();
</script>
