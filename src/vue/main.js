import './jamfsync.css';
import RunnerApp from './RunnerApp.vue';
import StatusBanner from './StatusBanner.vue';

// Bootstrap for the JAMF Sync Vue components. This bundle is registered globally as a
// footer JS module ($PLUGIN_HOOKS['javascript_module']), so it runs after GLPI's
// build/vue/app.js (window.Vue / window._vue available). Each mount is a no-op on pages
// that lack its mount node:
//   - #jamfsync-runner-app     -> the full sync runner (config runner page only)
//   - #jamfsync-status-banner  -> the persistent "sync running" strip (all plugin pages)
(function () {
    function readJson(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            return JSON.parse(value);
        } catch (e) {
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
        const csrf = root.dataset.csrf || '';

        const app = window.Vue.createApp(RunnerApp, { instances, labels, csrf, apiUrl: 'sync.ajax.php' });
        const vm = app.mount(root);

        // Wire the server-rendered per-row "Run" / "Full run" buttons to the same runner.
        document.querySelectorAll('[data-jamf-run]').forEach((button) => {
            if (button.dataset.jamfRunnerBound === '1') {
                return;
            }
            button.dataset.jamfRunnerBound = '1';
            button.addEventListener('click', () => {
                const instanceId = Number(button.getAttribute('data-instance-id') || 0);
                const full = Number(button.getAttribute('data-full') || 0) === 1;
                vm.startSafe(full, instanceId);
            });
        });

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
        window.Vue.createApp(StatusBanner, { labels, apiUrl: 'sync.ajax.php' }).mount(root);

        return true;
    }

    function start() {
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
