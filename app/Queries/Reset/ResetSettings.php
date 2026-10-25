<?php

namespace App\Queries\Reset;

use App\Support\AccessContext;
use App\Support\DefaultDatabaseConnection;
use App\Support\DefaultDatabaseQueue;
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
        return DefaultDatabaseQueue::connection();
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

        $queueProblems = DefaultDatabaseQueue::storeProblems();

        if ($queueProblems !== []) {
            throw new LogicException("毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、{$queueProblems[0]}");
        }

        if (Config::get('session.driver') !== 'database') {
            throw new LogicException('毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、セッションの置き場(SESSION_DRIVER)が DB の表(database)でない');
        }

        // 表の外の置き場は手順 5 で空にならない。Laravel は表の名前が無いと DB の driver でも記録を捨てる(QueueServiceProvider)
        $failedInTable = in_array(Config::get('queue.failed.driver'), ['database-uuids', 'database'], true)
            && is_string(Config::get('queue.failed.table'));

        if (! $failedInTable) {
            throw new LogicException('毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、'
                .'失敗した仕事の記録の置き場(QUEUE_FAILED_DRIVER と queue.failed.table)が'
                .' DB の表(database-uuids か database と表の名前)でない');
        }

        $this->ensureDefaultConnections($default);
    }

    /**
     * キャッシュ・鎖錠・失敗した仕事の記録・セッションの表が、既定の DB の接続にあること(手順 5 のトランザクションは既定の接続で動き、
     * 別の接続の表は空にならない)。比べ方 = {@see DefaultDatabaseConnection}。キューの表は {@see DefaultDatabaseQueue::storeProblems()} が見る。
     *
     * @throws LogicException 既定でない接続を指しているとき
     */
    private function ensureDefaultConnections(string $cacheStore): void
    {
        $connections = [
            "cache.stores.{$cacheStore}.connection" => 'DB_CACHE_CONNECTION',
            "cache.stores.{$cacheStore}.lock_connection" => 'DB_CACHE_LOCK_CONNECTION',
            'queue.failed.database' => 'DB_CONNECTION',
            'session.connection' => 'SESSION_CONNECTION',
        ];

        foreach ($connections as $key => $variable) {
            if (! DefaultDatabaseConnection::is(Config::get($key))) {
                throw new LogicException("毎日の初期化がオン(DAILY_RESET_ENABLED)なのに、設定 {$key}({$variable})が既定の DB の接続でない");
            }
        }
    }
}
