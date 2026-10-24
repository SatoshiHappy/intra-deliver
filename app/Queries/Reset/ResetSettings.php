<?php

namespace App\Queries\Reset;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * 毎日の初期化の設定(config/reset.php。docs の 設計/公開の配備/毎日の初期化.md 5 節)と、初期化が扱う置き場(キャッシュの表・キューの接続)。
 * 起動の時の置き場の確かめ({@see self::ensureSharedStores()})もここに置く。
 */
class ResetSettings
{
    /** 設定がオンか(true のほかはオフ) */
    public function enabled(): bool
    {
        return Config::get('reset.enabled') === true;
    }

    /** $at が属する「その日」(2 節)。Y-m-d */
    public function dayOf(CarbonImmutable $at): string
    {
        return $at->setTimezone(AccessContext::TIMEZONE)->subHours($this->seconds('day_start_hour'))->toDateString();
    }

    /** config/reset.php の整数の値(秒・時・鍵) */
    public function seconds(string $key): int
    {
        return Config::integer("reset.{$key}");
    }

    public function disk(): string
    {
        return Config::string('reset.disk');
    }

    /** キューの停止と処理中のジョブを見る接続(既定の接続)と、その名前のキュー */
    public function queueConnection(): string
    {
        return Config::string('queue.default');
    }

    public function queueName(): string
    {
        return Config::string("queue.connections.{$this->queueConnection()}.queue");
    }

    /** ジョブを失ったと見なすまでの秒(手順 2 の最長の待ち) */
    public function retryAfter(): int
    {
        return Config::integer("queue.connections.{$this->queueConnection()}.retry_after");
    }

    /**
     * キャッシュの表(既定のキャッシュの置き場の表とその鎖錠の表。鎖錠の表の既定は CacheManager と同じ cache_locks)。
     *
     * @return list<string>
     */
    public function cacheTables(): array
    {
        $store = Config::string('cache.default');
        $lockTable = Config::get("cache.stores.{$store}.lock_table");

        return [Config::string("cache.stores.{$store}.table"), is_string($lockTable) ? $lockTable : 'cache_locks'];
    }

    /** マイグレーションの記録の表(空にしない) */
    public function migrationsTable(): string
    {
        $table = Config::get('database.migrations');

        return is_array($table) ? (string) $table['table'] : Config::string('database.migrations');
    }

    /**
     * 設定がオンのとき、置き場を確かめて、違えば起動を止める(5 節の「置き場の確かめ」)。
     *
     * @throws LogicException 違うとき(起動を止める)
     */
    public function ensureSharedStores(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $default = Config::get('cache.default');
        $driver = is_string($default) ? Config::get("cache.stores.{$default}.driver") : null;

        $maintenance = [Config::get('app.maintenance.driver'), Config::get('app.maintenance.store')];

        if ($maintenance !== ['cache', $default] || $driver !== 'database') {
            throw new LogicException('毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、メンテナンスの状態の置き場'
                .'(APP_MAINTENANCE_DRIVER=cache・APP_MAINTENANCE_STORE)と既定のキャッシュの置き場(CACHE_STORE)が同じ DB のキャッシュでない');
        }

        if (Config::get("queue.connections.{$this->queueConnection()}.driver") !== 'database') {
            throw new LogicException('毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、'
                .'既定のキューの接続(QUEUE_CONNECTION)が DB の表(database)でない');
        }
    }
}
