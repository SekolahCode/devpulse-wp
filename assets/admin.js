(function () {
    function text(config, key, fallback) {
        return typeof config[key] === 'string' && config[key] !== '' ? config[key] : fallback;
    }

    function init() {
        var btn = document.getElementById('devpulse-test');
        var result = document.getElementById('devpulse-result');

        if (!btn || !result || typeof window.ajaxurl !== 'string') {
            return;
        }

        var config = window.devpulseAdmin || {};
        var nonce = typeof config.nonce === 'string' ? config.nonce : '';

        btn.addEventListener('click', function () {
            btn.disabled = true;
            result.textContent = text(config, 'sendingText', 'Sending...');

            fetch(window.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=devpulse_test&nonce=' + encodeURIComponent(nonce),
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.success) {
                        result.textContent = text(config, 'successText', 'Event sent!');
                        return;
                    }

                    var failedText = text(config, 'failedText', 'Failed');
                    var unknownError = text(config, 'unknownError', 'unknown error');
                    var message = data && data.data ? data.data : unknownError;
                    result.textContent = failedText + ': ' + message;
                })
                .catch(function () {
                    result.textContent = text(config, 'networkError', 'Network error');
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
        return;
    }

    init();
}());
