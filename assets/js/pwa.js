// Install as an app (phone and desktop) + service worker registration
(function () {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {});
        });
    }

    var button = document.getElementById('installApp');
    var help = document.getElementById('installModal');
    var deferredPrompt = null;
    var ua = navigator.userAgent;
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

    if (!button || standalone) return;

    // Which manual steps to show when the browser has no install prompt of its own
    function platform() {
        if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
        if (/Android/.test(ua)) return 'android';
        if (/Firefox\//.test(ua)) return 'firefox';
        if (/Safari\//.test(ua) && !/Chrome\/|Chromium\/|Edg\//.test(ua)) return 'mac_safari';
        return 'desktop';
    }

    function show() { button.hidden = false; }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        show();
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        button.hidden = true;
        if (help) help.style.display = 'none';
    });

    // Without the help dialog (login page) the button only appears when the browser can install directly
    if (help) {
        var step = help.querySelector('[data-platform="' + platform() + '"]');
        if (step) step.hidden = false;
        show();
    }

    button.addEventListener('click', function () {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice.outcome === 'accepted') button.hidden = true;
                deferredPrompt = null;
            });
        } else if (help) {
            help.style.display = 'flex';
        }
    });
})();
