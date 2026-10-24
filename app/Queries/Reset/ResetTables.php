<?php

namespace App\Queries\Reset;

use Illuminate\Support\Facades\DB;

/**
 * 初期化が見る DB の表と処理中のジョブ(docs の 設計/公開の配備/毎日の初期化.md 1 節・2 節)。表は DB の一覧から決める(ADR 0024 の決定 7)。
 */
class ResetTables
{
    public function __construct(private ResetSettings $settings) {}

    /**
     * 今のスキーマの表の名前(名前の順)。
     *
     * @return list<string>
     */
    public function all(): array
    {
        $names = DB::table('pg_tables')->where('schemaname', DB::raw('current_schema()'))
            ->orderBy('tablename')->pluck('tablename');

        return array_values($names->map(fn (mixed $name): string => (string) $name)->all());
    }

    /**
     * 処理中のジョブの数(手順 2・4): キューの表の予約のうち、予約から retry_after を過ぎていないもの(過ぎた予約は落ちた worker が残したもので、
     * キューが配り直す = Illuminate\Queue\DatabaseQueue の isReservedButExpired と同じ境目)。
     */
    public function processingJobs(): int
    {
        $connection = $this->settings->queueConnection();
        $table = (string) config("queue.connections.{$connection}.table");
        $dbConnection = config("queue.connections.{$connection}.connection");

        return DB::connection(is_string($dbConnection) ? $dbConnection : null)->table($table)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>', now()->getTimestamp() - $this->settings->retryAfter())
            ->count();
    }
}
