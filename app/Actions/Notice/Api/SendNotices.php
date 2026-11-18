<?php

namespace App\Actions\Notice\Api;

use App\Models\Notice\Notice;
use App\Queries\Notice\NoticeReceivingQuery;
use App\Queries\Notice\NoticeTypeCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * 通知を送る(Notice の公開の口。保証の正本 = docs の 設計/ワークフロー/案件の運用/通知.md の 1 節と、そこが指す ADR 0012 の決定 3・
 * ADR 0050 の決定 3)。認可を見ない(宛先は呼び手が決める)。宛先の重複を除いて 1 人 1 行を書き(ミュートした人の行は既読で作る)、
 * 0 人なら何も書かない。
 * 自分で DB::transaction を持ち、呼び手のトランザクションの中では savepoint で含まれる(呼び手が巻き戻すと通知も残らない)。
 */
class SendNotices
{
    /** 対象の種類の書式(表 notices の検査制約と同じ) */
    private const TARGET_TYPE_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    /** 対象の種類の長さの上限(列 varchar(100)) */
    private const MAX_TARGET_TYPE_LENGTH = 100;

    public function __construct(
        private NoticeTypeCatalog $types,
        private NoticeReceivingQuery $receiving,
    ) {}

    /**
     * 主体の判定をしない。呼び手が宛先を決めてから(除く人と今日に有効でない人を除いてから)呼ぶ。設定と必須の印は呼ばれた時に読む
     * (呼び手のトランザクションの中なら、その中で読む)。
     *
     * 宛先を除かず、未読で作るか既読で作るかを宛先ごとに決める(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/
     * 種類と作る規則.md の 2 節の表。既読の日時 = 作った時刻)。誰の行を既読で作ったかは返さない。
     *
     * @param  list<int>  $recipientUserIds  宛先のユーザー(users.id)。重ねてよい
     *
     * @throws InvalidArgumentException 種類・対象の書式か params の形が違うとき、種類が種類の一覧に無いとき(宛先の数によらない。呼び手の誤り)
     * @throws QueryException 宛先・起こした人のユーザーが無いとき(外部キー。呼び手の誤り)
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function execute(NoticeMessage $message, array $recipientUserIds): void
    {
        $this->assertWellFormed($message);
        if (! $this->types->has($message->type)) {
            throw new InvalidArgumentException("通知の種類「{$message->type}」が種類の一覧に無い");
        }

        $recipients = array_values(array_unique($recipientUserIds));

        if ($recipients === []) {
            return;
        }

        $muted = array_flip($this->receiving->mutedAmong($message->type, $recipients));
        $now = now();

        DB::transaction(function () use ($message, $recipients, $muted, $now): void {
            foreach ($recipients as $userId) {
                $notice = new Notice([
                    'user_id' => $userId,
                    'type' => $message->type,
                    'target_type' => $message->targetType,
                    'target_id' => $message->targetId,
                    'actor_user_id' => $message->actorUserId,
                    // 空の params も JSON のオブジェクト {} で書く(PHP の空の配列は JSON の配列になるため。表の検査制約 = object)
                    'params' => (object) $message->params,
                    'read_at' => isset($muted[$userId]) ? $now : null,
                ]);
                // 既読で作る行の既読の日時を作った時刻と同じにする(作った時刻を 1 回の送信で 1 つに決める)
                $notice->setCreatedAt($now)->setUpdatedAt($now)->save();
            }
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertWellFormed(NoticeMessage $message): void
    {
        if (! NoticeTypeCatalog::isWellFormed($message->type)) {
            throw new InvalidArgumentException("通知の種類「{$message->type}」が「モジュール.種類」の書式でない");
        }

        if (($message->targetType === null) !== ($message->targetId === null)) {
            throw new InvalidArgumentException('通知の対象の種類と id は、両方を渡すか両方を null にする');
        }

        if ($message->targetType !== null && ! self::isWellFormedTargetType($message->targetType)) {
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

    private static function isWellFormedTargetType(string $targetType): bool
    {
        return strlen($targetType) <= self::MAX_TARGET_TYPE_LENGTH
            && preg_match(self::TARGET_TYPE_PATTERN, $targetType) === 1;
    }
}
