import './jamfsync.css';
import RunnerApp from './RunnerApp.vue';
import StatusBanner from './StatusBanner.vue';

// Bootstrap for the JAMF Sync Vue components. This bundle is registered globally as a
// footer JS module ($PLUGIN_HOOKS['javascript_module']), so it runs after GLPI's
// build/vue/app.js (window.Vue / window._vue available). Each mount is a no-op on pages
// that lack its mount node:
//   - #jamfsync-runner-app     -> the full sync runner (config runner page only)
//   - #jamfsync-status-banner  -> the persistent "sync running" strip (all plugin pages)
//   - [data-jamfsync-test-connection] -> "Test connection" button of the instance form
//   - [data-jamfsync-takeover-preview] -> "Check takeover" button of the instance form
(function () {
    function readJson(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            return JSON.parse(value);
        } catch {
            return fallback;
        }
    }

    function vueReady() {
        return window.Vue && typeof window.Vue.createApp === 'function';
    }

    // Returns true when handled (mounted, already mounted, or node absent),
    // false only when the node is present but GLPI's Vue runtime is not ready yet.
    function mountRunner() {
        const root = document.getElementById('jamfsync-runner-app');
        if (!root) {
            return true;
        }
        if (root.__vue_app__ !== undefined) {
            return true;
        }
        if (!vueReady()) {
            return false;
        }

        const instances = readJson(root.dataset.instances, []);
        const labels = readJson(root.dataset.labels, {});
        const apiBase = root.dataset.apiBase || '';

        window.Vue.createApp(RunnerApp, { instances, labels, apiBase }).mount(root);

        return true;
    }

    function mountStatusBanner() {
        const root = document.getElementById('jamfsync-status-banner');
        if (!root) {
            return true;
        }
        if (root.__vue_app__ !== undefined) {
            return true;
        }
        if (!vueReady()) {
            return false;
        }

        const labels = readJson(root.dataset.labels, {});
        const apiBase = root.dataset.apiBase || '';
        window.Vue.createApp(StatusBanner, { labels, apiBase }).mount(root);

        return true;
    }

    // Posts the instance form's current values to the test route and shows the answer
    // next to the button (plain DOM, no Vue needed).
    function bindTestConnection() {
        document.querySelectorAll('[data-jamfsync-test-connection]').forEach((button) => {
            if (button.dataset.jamfsyncBound === '1') {
                return;
            }
            button.dataset.jamfsyncBound = '1';
            const result = button.parentElement.querySelector('[data-jamfsync-test-result]');
            const show = (text, ok) => {
                if (result) {
                    result.textContent = text;
                    result.className = 'small ' + (ok ? 'text-success' : 'text-danger');
                }
            };

            button.addEventListener('click', async () => {
                const form = button.closest('form');
                if (!form) {
                    return;
                }
                const body = new URLSearchParams();
                ['id', 'jamf_base_url', 'jamf_api_username', 'jamf_api_key', 'verify_tls', 'request_timeout'].forEach((name) => {
                    const field = form.elements.namedItem(name);
                    if (field && 'value' in field) {
                        body.set(name, field.value);
                    }
                });

                button.disabled = true;
                if (result) {
                    result.textContent = button.dataset.labelRunning || '';
                    result.className = 'small text-muted';
                }
                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString(),
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();
                    show(String(payload.message || button.dataset.labelFailed || ''), Boolean(payload.ok));
                } catch (error) {
                    show(button.dataset.labelFailed || String(error), false);
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    // "Check takeover": the server answers rendered (escaped) HTML for the result area.
    function bindTakeoverPreview() {
        document.querySelectorAll('[data-jamfsync-takeover-preview]').forEach((button) => {
            if (button.dataset.jamfsyncBound === '1') {
                return;
            }
            button.dataset.jamfsyncBound = '1';
            const result = document.querySelector('[data-jamfsync-takeover-result]');
            const showText = (text, cssClass) => {
                if (result) {
                    result.textContent = text;
                    result.className = 'col-12 small ' + cssClass;
                }
            };

            button.addEventListener('click', async () => {
                button.disabled = true;
                showText(button.dataset.labelRunning || '', 'text-muted');
                try {
                    const response = await fetch(button.dataset.url, { method: 'POST', credentials: 'same-origin' });
                    const payload = await response.json();
                    if (payload.ok && result) {
                        result.className = 'col-12';
                        result.innerHTML = String(payload.html || '');
                    } else {
                        showText(String(payload.message || button.dataset.labelFailed || ''), 'text-danger');
                    }
                } catch (error) {
                    showText(button.dataset.labelFailed || String(error), 'text-danger');
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    function start() {
        bindTestConnection();
        bindTakeoverPreview();
        let doneRunner = mountRunner();
        let doneBanner = mountStatusBanner();
        if (doneRunner && doneBanner) {
            return;
        }

        let tries = 0;
        const timer = window.setInterval(function () {
            tries += 1;
            doneRunner = doneRunner || mountRunner();
            doneBanner = doneBanner || mountStatusBanner();
            if ((doneRunner && doneBanner) || tries >= 60) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
