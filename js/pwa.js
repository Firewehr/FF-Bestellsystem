(function () {
    'use strict';

    if ('serviceWorker' in navigator) {
        var hasReloadedForSw = false;

        window.addEventListener('load', function () {
            var swUrl = window.FF_PWA_SW_URL || './sw.js';

            navigator.serviceWorker.addEventListener('controllerchange', function () {
                if (hasReloadedForSw) {
                    return;
                }
                hasReloadedForSw = true;
                window.location.reload();
            });

            navigator.serviceWorker.register(swUrl, {
                scope: './',
                updateViaCache: 'none'
            }).then(function (registration) {
                registration.update().catch(function () {});
            }).catch(function () {});
        });
    }
}());