import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import typefinder from '@pentacore/vite-plugin-laravel-typefinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Set in containerized/CI builds where wayfinder + typefinder types are produced ahead of time
// and PHP/artisan aren't available to the Vite build itself. Locally unset, so dev/build keep
// regenerating types automatically.
const skipTypeGeneration = process.env.SKIP_TYPE_GENERATION === '1';

// resources/js/typefinder is committed and gated by tests/Feature/GeneratedTypesTest.php, so CI
// builds use it as is: regenerating there would run against a database that is not migrated yet
// and overwrite the committed files with column-less models. Local dev/build still regenerate.
const skipTypefinder = skipTypeGeneration || process.env.CI === 'true';

const confirmMessage =
    'Ask with confirm() from useConfirm() (@/composables/useConfirm), not the browser dialog.';

// Blank line before and after every control statement.
const paddingAroundControl = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
].flatMap((statement) => [
    { blankLine: 'always', prev: '*', next: statement },
    { blankLine: 'always', prev: statement, next: '*' },
]);

export default defineConfig(({ command }) => ({
    // lazyPlugins keeps `vp lint` / `vp fmt` / `vp check` from loading the build plugins (and
    // wayfinder/typefinder from shelling out to artisan) when they only read this file's config.
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia({
            ssr: {
                entry: 'resources/js/ssr.ts',
                host: '0.0.0.0',
                port: 13714,
            },
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
            // No skip flag in the wayfinder plugin — point its command at the shell no-op when
            // types are already on disk. Args still get appended; `:` ignores them.
            ...(skipTypeGeneration ? { command: ':' } : {}),
        }),
        typefinder(skipTypefinder ? { buildCommand: false } : {}),
    ]),
    // Vite externalises every runtime dependency from the SSR build by default, leaving bare
    // `import ... from "vue"` specifiers that need node_modules on disk to resolve. The production
    // image deletes node_modules after `pnpm run build` and ships only the `node` binary, so those
    // imports would fail at boot with ERR_MODULE_NOT_FOUND. Inline them to keep bootstrap/ssr
    // self-contained. Only for the build: under `vp dev` the Inertia SSR dev endpoint would then
    // inline CommonJS packages (vue's index.js) into Vite's ES module runner, which fails with
    // "module is not defined".
    ssr: command === 'build' ? { noExternal: true } : {},
    server: {
        watch: {
            ignored: ['**/.claude/**', '**/.worktrees/**', '**/vendor/**'],
        },
    },
    lint: {
        plugins: ['eslint', 'typescript', 'unicorn', 'oxc', 'import', 'vue'],
        // Oxlint has no Vue template rules; @stylistic runs through Oxlint's JS plugin support.
        jsPlugins: ['@stylistic/eslint-plugin'],
        ignorePatterns: [
            '.claude/**',
            '.worktrees/**',
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/typefinder/**',
            'resources/js/wayfinder/**',
        ],
        // Oxlint reports correctness rules, the type-aware ones among them, as warnings by default.
        categories: {
            correctness: 'error',
        },
        options: {
            denyWarnings: true,
            // Type-aware rules get type info from tsrs and cover .ts files only, not .vue script
            // blocks. vue-tsc stays the type checker, so typeCheck stays off.
            typeAware: true,
        },
        rules: {
            'typescript/no-explicit-any': 'off',
            'typescript/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
            'no-restricted-globals': [
                'error',
                { name: 'confirm', message: confirmMessage },
            ],
            'no-restricted-properties': [
                'error',
                { object: 'window', property: 'confirm', message: confirmMessage },
                {
                    object: 'globalThis',
                    property: 'confirm',
                    message: confirmMessage,
                },
                { object: 'self', property: 'confirm', message: confirmMessage },
            ],
            curly: ['error', 'all'],
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        // Format only resources/, as Prettier did. Oxfmt ignores negated file patterns at the
        // root, so the root config files stay out rather than being listed one by one.
        ignorePatterns: [
            '/*',
            '!/resources/',
            'resources/js/components/ui/*',
            'resources/js/typefinder/*',
            'resources/views/mail/*',
        ],
        sortImports: {
            groups: [
                'builtin',
                'external',
                'internal',
                'parent',
                'sibling',
                'index',
            ],
            ignoreCase: true,
            newlinesBetween: false,
        },
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
}));
