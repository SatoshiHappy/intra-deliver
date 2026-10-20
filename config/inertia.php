<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        // 描画のたびに、ページのファイルが paths の下に extensions のどれかで実在することを確かめる(無ければ例外)。
        // PHPUnit の環境だけで有効にする(phpunit.xml が INERTIA_ENSURE_PAGES_EXIST を true にする)。開発・本番では確かめない
        // (本番には resources/js/pages を置くとは限らない)。docs の 設計/コード構成/README.md 5 節。
        'ensure_pages_exist' => (bool) env('INERTIA_ENSURE_PAGES_EXIST', false),

        'paths' => [
            resource_path('js/pages'),
        ],

        // 画面側が解決できる形(pages の下の .tsx)だけを実在と認める
        'extensions' => [
            'tsx',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
