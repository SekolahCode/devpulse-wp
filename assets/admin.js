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

    function initRepair() {
        var btn     = document.getElementById('devpulse-repair');
        var result  = document.getElementById('devpulse-repair-result');
        var details = document.getElementById('devpulse-repair-details');
        var list    = document.getElementById('devpulse-repair-list');

        if (!btn || !result || typeof window.ajaxurl !== 'string') {
            return;
        }

        var config      = window.devpulseAdmin || {};
        var repairNonce = typeof config.repairNonce === 'string' ? config.repairNonce : '';

        btn.addEventListener('click', function () {
            btn.disabled    = true;
            result.className = 'devpulse-result devpulse-loading';
            result.textContent = text(config, 'sendingText', 'Repairing...');
            details.style.display = 'none';
            list.innerHTML = '';

            fetch(window.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=devpulse_repair&nonce=' + encodeURIComponent(repairNonce),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        result.className   = 'devpulse-result devpulse-success';
                        result.textContent = data.data.message;

                        if (data.data.repairs && data.data.repairs.length) {
                            data.data.repairs.forEach(function (msg) {
                                var li = document.createElement('li');
                                li.textContent = msg;
                                list.appendChild(li);
                            });
                            details.style.display = 'block';
                        }
                        return;
                    }

                    result.className   = 'devpulse-result devpulse-error';
                    result.textContent = (data && data.data) ? data.data : text(config, 'failedText', 'Failed');
                })
                .catch(function () {
                    result.className   = 'devpulse-result devpulse-error';
                    result.textContent = text(config, 'networkError', 'Network error');
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); initRepair(); });
        return;
    }

    init();
    initRepair();
}());
