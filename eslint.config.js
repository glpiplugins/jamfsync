import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

// Flat config (ESLint 9+), replacing the former .eslintrc.cjs with the same rule set.
export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['src/vue/**/*.{js,vue}'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-indent': 'off',
            'vue/html-self-closing': 'off',
            'vue/attributes-order': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/first-attribute-linebreak': 'off',
        },
    },
];
