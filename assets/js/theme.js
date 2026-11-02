// Light / dark theme toggle with a "color shift" ripple that grows from the button.
(function () {
    const root = document.documentElement;
    const button = document.getElementById('themeToggle');
    if (!button) return;

    function updateIcon() {
        const dark = root.getAttribute('data-theme') === 'dark';
        button.innerHTML = dark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    }

    function setTheme(theme) {
        root.setAttribute('data-theme', theme);
        try { localStorage.setItem('theme', theme); } catch (e) {}
        updateIcon();
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme } }));
    }

    button.addEventListener('click', function () {
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!document.startViewTransition || reduceMotion) {
            // Fallback: CSS transitions on colors handle the shift
            root.classList.add('theme-shifting');
            setTheme(next);
            setTimeout(() => root.classList.remove('theme-shifting'), 600);
            return;
        }

        const rect = button.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));

        const transition = document.startViewTransition(() => setTheme(next));
        transition.ready.then(() => {
            root.animate(
                { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
                { duration: 650, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', pseudoElement: '::view-transition-new(root)' }
            );
        });
    });

    // Follow the system setting while the user has not picked a theme
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (event) {
        let saved = null;
        try { saved = localStorage.getItem('theme'); } catch (e) {}
        if (!saved) {
            root.setAttribute('data-theme', event.matches ? 'dark' : 'light');
            updateIcon();
            document.dispatchEvent(new CustomEvent('themechange'));
        }
    });

    updateIcon();
})();
