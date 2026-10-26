/**
 * ESLint-Konfiguration für den Admin-Code des Plugins.
 *
 * Abgeleitet aus shopware/administration 6.6 (.eslintrc.js). Übernommen sind die JS- und Twig-Regeln.
 * Weggelassen sind die kern-internen Teile: TypeScript-Block, Jest/Test-Regeln, webpack-Alias-Resolver sowie
 * require-package-annotation und private-feature-declarations (nur für den Kern gedacht).
 * Zusätzlich aktiv: plugin-rules/no-src-imports, die für Plugins vorgesehene Regel des Kerns.
 */

const baseRules = {
    // Match the max line length with the phpstorm default settings
    'max-len': ['error', 125, { ignoreRegExpLiterals: true }],
    'import/no-useless-path-segments': 0,
    // don't require .vue and .js extensions
    'import/extensions': ['error', 'always', {
        js: 'never',
        ts: 'never',
        vue: 'never',
    }],
    'no-console': ['error', { allow: ['warn', 'error'] }],
    'no-warning-comments': ['error', { location: 'anywhere' }],
    'inclusive-language/use-inclusive-words': 'error',
    'comma-dangle': ['error', 'always-multiline'],
    'sw-core-rules/require-position-identifier': ['error', {
        components: [
            'sw-card',
            'sw-tabs',
            'sw-extension-component-section',
        ],
    }],
    'plugin-rules/no-src-imports': 'error',
    'no-restricted-exports': 'off',
    'filename-rules/match': [2, /^.*(?:\.js|\.ts|\.html|\.html\.twig)$/],
    'vue/multi-word-component-names': ['error', {
        ignores: ['index.html'],
    }],
};

module.exports = {
    root: true,
    extends: [
        '@shopware-ag/eslint-config-base',
    ],
    env: {
        browser: true,
    },

    parserOptions: {
        // Das Plugin bringt keine Babel-Konfiguration mit, der Admin-Build nutzt die des Shops.
        requireConfigFile: false,
    },

    globals: {
        Shopware: true,
        VueJS: true,
    },

    plugins: [
        'twig-vue',
        'inclusive-language',
        'vuejs-accessibility',
        'sw-core-rules',
        'sw-deprecation-rules',
        'plugin-rules',
        'filename-rules',
    ],

    settings: {
        'import/resolver': {
            node: {
                extensions: ['.js', '.json', '.twig'],
            },
        },
    },

    rules: {
        ...baseRules,
    },

    overrides: [
        {
            extends: [
                'plugin:vue/vue3-recommended',
                '@shopware-ag/eslint-config-base',
                'prettier',
            ],
            files: ['**/*.js'],
            excludedFiles: ['.eslintrc.js'],
            rules: {
                ...baseRules,
                'sw-core-rules/require-explicit-emits': 'error',
                'vue/require-prop-types': 'error',
                'vue/require-default-prop': 'error',
                'vue/no-mutating-props': 'error',
                'vue/component-definition-name-casing': ['error', 'kebab-case'],
                'vue/no-boolean-default': ['error', 'default-false'],
                'vue/order-in-components': ['error', {
                    order: [
                        'el',
                        'name',
                        'parent',
                        'functional',
                        ['template', 'render'],
                        'inheritAttrs',
                        ['provide', 'inject'],
                        'emits',
                        'extends',
                        'mixins',
                        'model',
                        ['components', 'directives', 'filters'],
                        ['props', 'propsData'],
                        'data',
                        'metaInfo',
                        'computed',
                        'watch',
                        'LIFECYCLE_HOOKS',
                        'methods',
                        ['delimiters', 'comments'],
                        'renderError',
                    ],
                }],
                'vue/no-deprecated-destroyed-lifecycle': 'error',
                'vue/no-deprecated-events-api': 'error',
                'vue/require-slots-as-functions': 'error',
                'vue/no-deprecated-props-default-this': 'error',
                'sw-deprecation-rules/no-compat-conditions': ['warn', 'disableFix'],
                'sw-deprecation-rules/no-empty-listeners': ['error', 'enableFix'],
                'sw-deprecation-rules/no-vue-options-api': 'off',
            },
        }, {
            extends: [
                'plugin:vue/vue3-recommended',
                'plugin:vue/essential',
                'plugin:vue/recommended',
                'eslint:recommended',
                'plugin:vuejs-accessibility/recommended',
            ],
            processor: 'twig-vue/twig-vue',
            files: [
                'src/**/*.html.twig',
            ],
            rules: {
                'vue/component-name-in-template-casing': ['error', 'kebab-case', {
                    registeredComponentsOnly: true,
                    ignores: [],
                }],
                'vue/html-indent': ['error', 4, {
                    baseIndent: 0,
                }],
                'no-multiple-empty-lines': ['error', { max: 1 }],
                'vue/attribute-hyphenation': 'error',
                'vue/multiline-html-element-content-newline': 'off', // allow more spacy templates
                'vue/html-self-closing': ['error', {
                    html: {
                        void: 'never',
                        normal: 'never',
                        component: 'always',
                    },
                    svg: 'always',
                    math: 'always',
                }],
                'vue/no-parsing-error': ['error', {
                    'nested-comment': false,
                }],
                'vue/valid-v-slot': ['error', {
                    allowModifiers: true,
                }],
                'vue/v-slot-style': 'error',
                'vue/attributes-order': 'error',
                'vue/no-deprecated-slot-attribute': ['error'],
                'vue/no-deprecated-slot-scope-attribute': ['error'],
                // @deprecated v.6.7.0.0 - will be error in v.6.7
                'sw-deprecation-rules/no-deprecated-components': ['warn', 'disableFix'],
                // @deprecated v.6.7.0.0 - will be error in v.6.7
                'sw-deprecation-rules/no-deprecated-component-usage': ['warn', 'disableFix'],
                'vue/no-useless-template-attributes': 'error',
                'vue/no-lone-template': 'error',

                // Disabled rules
                'eol-last': 'off', // no newline required at the end of file
                'max-len': 'off',
                'vue/no-multiple-template-root': 'off',
                'vue/no-unused-vars': 'off',
                'vue/no-template-shadow': 'off',
                'vue/no-v-html': 'off',
                'vue/valid-template-root': 'off',
                'vue/no-v-model-argument': 'off',
                'vue/no-v-for-template-key': 'off',
                'vue/html-closing-bracket-newline': 'error',
                'vue/no-v-for-template-key-on-child': 'error',
                'vue/no-deprecated-filter': 'error',
                'vue/no-deprecated-dollar-listeners-api': 'error',
                'vue/no-deprecated-dollar-scopedslots-api': 'error',
                'vue/no-deprecated-v-on-native-modifier': 'error',
                'vuejs-accessibility/media-has-caption': 'off',
            },
        },
    ],
};
