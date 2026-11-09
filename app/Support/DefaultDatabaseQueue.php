<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * 既定のキューが既定の DB 接続の表(database ドライバ)にあることの確かめの 1 か所。AI の起動の時の確かめ(ジョブを実行の記録と同じトランザクションで
 * 入れる前提 = docs の 設計/AI/実行と費用.md 4 節、データと失敗と検査.md 8 節)と、毎日の初期化の置き場の確かめ(設計/公開の配備/毎日の初期化.md 5 節)が使う。
 * キューの接続の DB の接続の比べ方 = {@see DefaultDatabaseConnection}。
 * 同じ確かめを、名前を渡したほかのキューの接続にも当てる(AI の会話の接続 = 設計/AI/実行と費用/README.md 4.5)。
 */
final class DefaultDatabaseQueue
{
    /** 既定のキューの接続の名前(config/queue.php の default) */
    public static function connection(): string
    {
        return Config::string('queue.default');
    }

    /**
     * キューの接続(null = 既定のキューの接続)が database ドライバでないか、その DB の接続が既定の DB 接続でないときの文(どちらも合えば空)。
     *
     * @param  string|null  $connection  キューの接続の名前(config/queue.php の connections の鍵)
     * @return list<string>
     */
    public static function storeProblems(?string $connection = null): array
    {
        $key = 'queue.connections.'.($connection ?? self::connection());

        if (Config::get("{$key}.driver") !== 'database') {
            return [$connection === null
                ? '既定のキューの接続(QUEUE_CONNECTION)が DB の表(database)でない'
                : "キューの接続 {$connection}(config/queue.php)が DB の表(database)でない"];
        }

        return DefaultDatabaseConnection::is(Config::get("{$key}.connection"))
            ? []
            : ["設定 {$key}.connection(DB_QUEUE_CONNECTION)が既定の DB の接続でない"];
    }

    /**
     * キューの接続(null = 既定のキューの接続)が、ジョブの投入をコミットの後に回す設定(after_commit)のときの文(偽なら空)。
     *
     * @param  string|null  $connection  キューの接続の名前(config/queue.php の connections の鍵)
     * @return list<string>
     */
    public static function afterCommitProblems(?string $connection = null): array
    {
        $key = 'queue.connections.'.($connection ?? self::connection()).'.after_commit';

        return (bool) Config::get($key)
            ? ["設定 {$key}(config/queue.php)が真(ジョブの投入がコミットの後に回り、実行の記録と同じトランザクションに入らない)"]
            : [];
    }
}
