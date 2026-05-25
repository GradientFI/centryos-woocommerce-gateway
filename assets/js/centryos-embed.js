(function () {
    'use strict';

    if (typeof window.CentryOSEmbed === 'undefined') {
        return;
    }

    var cfg = window.CentryOSEmbed;
    var redirected = false;
    var attempts = 0;
    var pollTimer = null;

    function redirect() {
        if (redirected) return;
        redirected = true;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        try {
            window.top.location.assign(cfg.returnUrl);
        } catch (e) {
            window.location.assign(cfg.returnUrl);
        }
    }

    function isSuccessMessage(data) {
        if (!data) return false;
        if (typeof data === 'string') {
            return data === 'centryos:payment_success' || data === 'payment_success';
        }
        if (typeof data !== 'object') return false;
        if (data.type === 'centryos:payment_success' || data.type === 'payment_success') return true;
        if (data.status === 'success' || data.status === 'SUCCESS') return true;
        if (data.event === 'payment.success' || data.event === 'payment_completed') return true;
        return false;
    }

    window.addEventListener('message', function (event) {
        if (cfg.centryosOrigin && event.origin !== cfg.centryosOrigin) {
            return;
        }
        if (isSuccessMessage(event.data)) {
            redirect();
        }
    }, false);

    function buildStatusUrl() {
        var params = [
            'action=centryos_check_order_status',
            'order_id=' + encodeURIComponent(cfg.orderId),
            'nonce=' + encodeURIComponent(cfg.nonce)
        ].join('&');
        var sep = cfg.statusEndpoint.indexOf('?') === -1 ? '?' : '&';
        return cfg.statusEndpoint + sep + params;
    }

    function poll() {
        if (redirected) return;
        attempts += 1;
        if (attempts > cfg.maxPollAttempts) {
            return;
        }
        fetch(buildStatusUrl(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (body) {
                if (body && body.success && body.data && body.data.paid === true) {
                    redirect();
                    return;
                }
                pollTimer = setTimeout(poll, cfg.pollIntervalMs);
            })
            .catch(function () {
                pollTimer = setTimeout(poll, cfg.pollIntervalMs);
            });
    }

    pollTimer = setTimeout(poll, cfg.pollIntervalMs);
})();
