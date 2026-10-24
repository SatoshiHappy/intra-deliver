<?php

namespace Tests\Feature\Reset;

use Tests\Support\BootsWithEnvironmentOverrides;
use Tests\Support\PublishSettingsTemplate;

/**
 * 毎日の初期化をオンにして起動するテストのクラスが使う。値は公開用の雛形 .env.publish.example の app.env の節から取る(公開の配備の値 =
 * docs の 設計/公開の配備/構成/設定.md 3.4。雛形の値で起動が通ることも、この trait を使うテストが確かめる)。オンの起動の置き場の確かめ
 * (設計/公開の配備/毎日の初期化.md 5 節)を通すため、その置き場も置く。キャッシュの表への書き込みはテストのトランザクションの中で巻き戻る。
 */
trait EnablesDailyResetBeforeBoot
{
    use BootsWithEnvironmentOverrides;

    private const RESET_SETTINGS = [
        'DAILY_RESET_ENABLED', 'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE', 'CACHE_STORE', 'QUEUE_CONNECTION',
    ];

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        $template = PublishSettingsTemplate::section('app.env');

        return array_combine(
            self::RESET_SETTINGS,
            array_map(fn (string $name): string => $template[$name] ?? '', self::RESET_SETTINGS),
        );
    }
}
