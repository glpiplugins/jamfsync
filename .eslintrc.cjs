module.exports = {
    root: true,
    env: {
        browser: true,
        es2022: true,
    },
    parserOptions: {
        ecmaVersion: 2022,
        sourceType: 'module',
    },
    extends: [
        'eslint:recommended',
        'plugin:vue/vue3-recommended',
    ],
    globals: {
        window: 'readonly',
        document: 'readonly',
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
};
