<?php

namespace Tests\Architecture\Policy;

/**
 * デモユーザー切替(モジュール Demo)の持ち物と取り付けの行(docs の 設計/デモユーザー切替/デモの取り付け.md 6.1・6.4。取り外しの手順 6.6 の正本)。
 * 照合 = tests/Architecture/DemoFootprintTest.php: 持ち物の外のファイルに、語 demo が取り付けの行のほかに現れない。どのモジュールも Demo を
 * 依存先に書かない({@see Modules::DECLARATION})。
 */
final class DemoFootprint
{
    /** 照合する語(大文字小文字を区別しない) */
    public const WORD = 'demo';

    /** モジュールの名前(宣言の Demo) */
    public const MODULE = 'Demo';

    /**
     * 持ち物(アプリのルート起点のパスのパターン。Engine\Paths の書き方 = `**` は配下すべて)。取り外すときに消す。
     *
     * @var list<string>
     */
    public const OWNED = [
        'app/Providers/DemoServiceProvider.php',
        'config/demo.php',
        'routes/demo.php',
        'app/Http/Controllers/Web/Demo/**',
        'app/Http/Requests/Demo/**',
        'app/Queries/Demo/**',
        'database/seeders/DemoSeeder.php',
        'tests/Feature/Demo/**',
        'tests/Architecture/DemoFootprintTest.php',
        'tests/Architecture/Policy/DemoFootprint.php',
        'tests/Architecture/fixtures/DemoFootprint/**',
        'tests/e2e/demo/**',
    ];

    /**
     * 照合しないもの(例外 1 件)。ベースライン(tests/Architecture/baselines)は数えた結果で、Demo を外せばベースラインの照合 1 が
     * その行を消すことを求めるので、取り外しの漏れはそちらが止める。
     *
     * @var list<string>
     */
    public const UNCHECKED = ['tests/Architecture/baselines/**'];

    /**
     * 取り付けの行(持ち物の外のファイル → 語を含んでよい行。行は前後の空白を除いて比べる)。取り外すときに消す。
     *
     * @var array<string, list<string>>
     */
    public const ATTACHMENTS = [
        'bootstrap/providers.php' => [
            'use App\Providers\DemoServiceProvider;',
            'DemoServiceProvider::class,',
        ],
        'tests/Architecture/Policy/Modules.php' => [
            '* Demo = 取り外せるデモユーザー切替(既定オフ。ホームの節の口を実装するので Menu に、守るルートの口を実装するので Authz に依存する。',
            "'Demo' => ['Master', 'Authz', 'Account', 'Menu'],",
        ],
        '.env.example' => [
            'DEMO_ENABLED=false',
        ],
        'phpunit.xml' => [
            '<env name="DEMO_ENABLED" value="false" force="true"/>',
        ],
        'scripts/ci/front' => [
            'export DEMO_ENABLED=true',
            'php artisan db:seed --class=DemoSeeder --force --no-interaction',
        ],
        '.env.publish.example' => [
            'DEMO_ENABLED=true',
        ],
        'scripts/deploy/migrate-and-seed' => [
            '"${dc}" "${env_file}" run --rm --no-deps -T app php artisan db:seed --class=DemoSeeder --force --no-interaction',
        ],
    ];
}
