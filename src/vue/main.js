import './jamfsync.css';
import RunnerApp from './RunnerApp.vue';

// Bootstrap for the JAMF Sync runner. This bundle is registered globally as a footer JS
// module ($PLUGIN_HOOKS['javascript_module']), so it runs after GLPI's build/vue/app.js
// (window.Vue / window._vue available). It is a no-op on every page that lacks the runner
// mount node, i.e. everywhere except the plugin's config page.
(function () {
    const MOUNT_ID = 'jamfsync-runner-app';

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

    function mountRunner() {
        const root = document.getElementById(MOUNT_ID);
        if (!root) {
            return false; // Not the config page — nothing to do.
        }
        if (root.__vue_app__ !== undefined) {
            return true; // Already mounted.
        }
        if (!window.Vue || typeof window.Vue.createApp !== 'function') {
            return false; // GLPI Vue runtime not ready yet.
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

    function start() {
        if (mountRunner()) {
            return;
        }
        let tries = 0;
        const timer = window.setInterval(function () {
            tries += 1;
            if (mountRunner() || tries >= 60) {
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
