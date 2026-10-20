<?php

namespace Tests\Feature\Demo;

use Tests\Support\BootsWithEnvironmentOverrides;

/**
 * デモユーザー切替をオンにして起動するテストのクラスが使う(docs の 設計/デモユーザー切替/デモの取り付け.md 6.4)。phpunit.xml は
 * DEMO_ENABLED を false に固定するので、オンにする値(DEMO_ENABLED=true)を渡す。仕組み(3 か所に置く・Env のリポジトリの作り直し・
 * 戻す・キャッシュの trait の禁止)は main の Tests\Support\BootsWithEnvironmentOverrides。
 */
trait EnablesDemoBeforeBoot
{
    use BootsWithEnvironmentOverrides;

    private const ENVIRONMENT_VARIABLE = 'DEMO_ENABLED';

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        return [self::ENVIRONMENT_VARIABLE => 'true'];
    }
}
