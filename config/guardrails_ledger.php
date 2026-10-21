<?php

/*
 * 存在型の台帳(⑤ 直接依存)。照合は双方向で、無断追加と水増しを失敗にする。
 *
 * 初期値 = 現状の実体(composer.json・package.json)。依存の追加・削除と同じ変更で更新する。
 * 流用元 = chatpinet_2 の config/guardrails_ledger.php(判定は docs の 設計/chatpinet_2からの流用.md 3 節)。
 */

return [
    // ⑤ 直接依存の許可リスト。推移的依存は列挙しない
    'dependencies' => [
        // composer.json の require と require-dev の全キー(プラットフォーム要件 php を含む)
        'composer' => [
            // require
            'php',
            'inertiajs/inertia-laravel',
            'laravel/ai',
            'laravel/framework',
            'laravel/tinker',
            'laravel/wayfinder',
            // require-dev
            'brianium/paratest',
            'fakerphp/faker',
            'larastan/larastan',
            'laravel/boost',
            'laravel/pail',
            'laravel/pint',
            'laravel/sail',
            'mockery/mockery',
            'nunomaduro/collision',
            'phpat/phpat',
            'phpunit/phpunit',
            'tomasvotruba/cognitive-complexity',
        ],
        // package.json の dependencies・devDependencies・optionalDependencies の全キー
        'npm' => [
            // dependencies
            '@dagrejs/dagre',
            '@dnd-kit/core',
            '@dnd-kit/sortable',
            '@dnd-kit/utilities',
            '@fontsource-variable/inter',
            '@fontsource-variable/noto-sans-jp',
            '@inertiajs/react',
            '@inertiajs/vite',
            '@xyflow/react',
            'class-variance-authority',
            'clsx',
            'cmdk',
            'laravel-vite-plugin',
            'lucide-react',
            'radix-ui',
            'react',
            'react-dom',
            'react-markdown',
            'remark-breaks',
            'remark-gfm',
            'sonner',
            'tailwind-merge',
            'tailwindcss',
            'tw-animate-css',
            // devDependencies
            '@ast-grep/cli',
            '@laravel/vite-plugin-wayfinder',
            '@playwright/test',
            '@tailwindcss/vite',
            '@types/node',
            '@types/react',
            '@types/react-dom',
            '@vitejs/plugin-react',
            'concurrently',
            'dependency-cruiser',
            'knip',
            'typescript',
            'vite',
            'vite-plus',
            'wrangler',
            // optionalDependencies
            '@laravel/multiplex',
            '@rollup/rollup-linux-x64-gnu',
            '@rollup/rollup-win32-x64-msvc',
            '@tailwindcss/oxide-linux-x64-gnu',
            '@tailwindcss/oxide-win32-x64-msvc',
            'lightningcss-linux-x64-gnu',
            'lightningcss-win32-x64-msvc',
        ],
    ],
];
