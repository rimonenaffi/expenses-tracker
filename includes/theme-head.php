<script>
// Apply the saved (or system) theme before first paint to avoid a flash
(function () {
    var theme;
    try { theme = localStorage.getItem('theme'); } catch (e) {}
    if (!theme) {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
