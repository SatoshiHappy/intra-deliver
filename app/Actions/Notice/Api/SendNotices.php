<?php

namespace App\Actions\Notice\Api;

use App\Models\Notice\Notice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 通知を送る(Notice の公開の口。保証の正本 = docs の 設計/ワークフロー/案件の運用/通知.md の 1 節と、そこが指す ADR 0012 の決定 3)。
 * 認可を見ない(宛先は呼び手が決める)。宛先の重複を除いて 1 人 1 行を書き、宛先が 0 人なら何も書かない。
 * 自分で DB::transaction を持ち、呼び手のトランザクションの中では savepoint で含まれる(呼び手が巻き戻すと通知も残らない)。
 */
class SendNotices
{
    /** 種類「モジュール.種類」の書式(表 notices の検査制約と同じ) */
    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D';

    /** 対象の種類の書式(表 notices の検査制約と同じ) */
    private const TARGET_TYPE_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    /** 種類と対象の種類の長さの上限(列 varchar(100)) */
    private const MAX_LENGTH = 100;

    /**
     * @param  list<int>  $recipientUserIds  宛先のユーザー(users.id)。重ねてよい
     *
     * @throws InvalidArgumentException 種類・対象の書式か params の形が違うとき(呼び手の誤り)
     * @throws QueryException 宛先・起こした人のユーザーが無いとき(外部キー。呼び手の誤り)
     */
    public function execute(NoticeMessage $message, array $recipientUserIds): void
    {
        $this->assertWellFormed($message);
        $recipients = array_values(array_unique($recipientUserIds));

        if ($recipients === []) {
            return;
        }

        DB::transaction(function () use ($message, $recipients): void {
            foreach ($recipients as $userId) {
                Notice::query()->create([
                    'user_id' => $userId,
                    'type' => $message->type,
                    'target_type' => $message->targetType,
                    'target_id' => $message->targetId,
                    'actor_user_id' => $message->actorUserId,
                    // 空の params も JSON のオブジェクト {} で書く(PHP の空の配列は JSON の配列になるため。表の検査制約 = object)
                    'params' => (object) $message->params,
                ]);
            }
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertWellFormed(NoticeMessage $message): void
    {
        if (! self::matches(self::TYPE_PATTERN, $message->type)) {
            throw new InvalidArgumentException("通知の種類「{$message->type}」が「モジュール.種類」の書式でない");
        }

        if (($message->targetType === null) !== ($message->targetId === null)) {
            throw new InvalidArgumentException('通知の対象の種類と id は、両方を渡すか両方を null にする');
        }

        if ($message->targetType !== null && ! self::matches(self::TARGET_TYPE_PATTERN, $message->targetType)) {
            throw new InvalidArgumentException("通知の対象の種類「{$message->targetType}」の書式が違う");
        }

        $this->assertWellFormedParams($message->params);
    }

    /**
     * @param  array<mixed>  $params
     *
     * @throws InvalidArgumentException
     */
    private function assertWellFormedParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("通知の値のキー {$key} が文字列でない");
            }

            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new InvalidArgumentException("通知の値 {$key} が文字列・整数・真偽・null のどれでもない");
            }
        }
    }

    private static function matches(string $pattern, string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match($pattern, $value) === 1;
    }
}
