import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

// The sync runner is a Vue 3 component, but GLPI already ships a Vue runtime on every
// central page (build/vue/app.js -> window._vue / window.Vue). We therefore mark `vue`
// as external and map it to the global `window._vue`, so this bundle reuses GLPI's Vue
// instance instead of bundling a second copy.
//
// The bundle is loaded via $PLUGIN_HOOKS['javascript_module'] (footer), which runs AFTER
// the head's app.js module, so `window._vue` is guaranteed to exist when the IIFE
// captures it. SFC templates are precompiled here (no runtime compiler -> no
// 'unsafe-eval' needed -> CSP-safe).
export default defineConfig({
    plugins: [vue()],
    publicDir: false,
    define: {
        __VUE_OPTIONS_API__: false,
        __VUE_PROD_DEVTOOLS__: false,
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    },
    build: {
        emptyOutDir: true,
        outDir: path.resolve(__dirname, 'public/js/dist'),
        cssCodeSplit: false,
        sourcemap: false,
        lib: {
            entry: path.resolve(__dirname, 'src/vue/main.js'),
            name: 'JamfsyncRunner',
            formats: ['iife'],
            fileName: () => 'jamfsync-vue.js',
        },
        rollupOptions: {
            external: ['vue'],
            output: {
                globals: { vue: 'window._vue' },
                // Stable name for the extracted stylesheet (loaded via add_css).
                assetFileNames: 'jamfsync-vue[extname]',
            },
        },
    },
});
