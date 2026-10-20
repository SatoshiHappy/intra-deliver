import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        // 端末の時刻帯で計算する誤りを落とすため、Asia/Tokyo 以外で走らせる(docs の 設計/コード構成/検査.md 6 節)
        env: { TZ: 'UTC' },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            // 検査の自己証明の fixture(わざと違反を含む)
            'tests/Architecture/fixtures/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            // Laravel Boost が生成するファイル(php artisan boost:install / boost:update で上書きされる)
            '.agents/**',
            '.claude/**',
            '.codex/**',
            '.mcp.json',
            'AGENTS.md',
            'CLAUDE.md',
            'boost.json',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // 印の生成物(正本は resources/brand/mark.svg。scripts/brand/build.mjs が書き、--check が文字列で比べる)。整形でバイト列が変わると --check と衝突する。
            // lint は除外しない(違反が出たら生成器 scripts/brand/mark.mjs を直す)
            'resources/js/components/AppLogoIcon.tsx',
            'resources/brand/generated.json',
            // ast-grep test が生成する snapshot と、検査の自己証明の fixture
            '.ast-grep/tests/__snapshots__/**',
            'tests/Architecture/fixtures/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
