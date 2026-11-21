<?php

namespace App\Actions\Ai\Core;

/**
 * 確かめの位置(docs の 設計/AI/会話の中核.md 13.5)。位置ごとの起きない組の止める制約を、両方の版が使う 1 か所としてここに置く:
 * 返せる止める区分({@see self::allows()})・続けるの欄({@see self::accepts()})・送信の種別({@see self::receives()})。中核は、起きない組を受けたら
 * 作りの誤りとして投げる(終わり方を返さない)。
 */
enum CheckPoint: string
{
    /** 送る前(どの種別の送信も) */
    case BeforeSend = 'before_send';

    /** 道具を呼ぶ前(呼び出しごと) */
    case BeforeTool = 'before_tool';

    /** 応答を読む途中(途中の文を書く間隔ごと) */
    case WhileReading = 'while_reading';

    /** 応答を読み終えた後 */
    case AfterReading = 'after_reading';

    /** 道具の後 */
    case AfterTools = 'after_tools';

    /** 送り直しの待ちの前 */
    case BeforeRetryWait = 'before_retry_wait';

    /** 送り直しの待ちの間 */
    case DuringRetryWait = 'during_retry_wait';

    /**
     * この位置で受け手が止める区分を返してよいか(13.5 のデシジョン表)。$sendKind は送る前(題の送信は費用の上限だけ)と読み終えた後
     * (回の答えと続きだけ費用の上限も = 5.3)の位置で見る。
     */
    public function allows(StopKind $kind, SendKind $sendKind): bool
    {
        $answering = $sendKind === SendKind::Turn || $sendKind === SendKind::Continuation;

        return match ($this) {
            self::BeforeSend => $sendKind !== SendKind::Title || $kind === StopKind::CostLimit,
            self::BeforeTool, self::BeforeRetryWait => $kind !== StopKind::CostLimit,
            self::AfterReading => $kind === StopKind::User || ($answering && $kind === StopKind::CostLimit),
            self::WhileReading, self::AfterTools, self::DuringRetryWait => $kind === StopKind::User,
        };
    }

    /**
     * この位置で中核が確かめの口を呼ぶ送信の種別か(13.5 の確かめの口を呼ぶ送信の種別の表)。
     */
    public function receives(SendKind $kind): bool
    {
        return match ($this) {
            self::BeforeSend => true,
            self::BeforeTool, self::AfterTools => $kind === SendKind::Turn || $kind === SendKind::Continuation,
            self::WhileReading, self::AfterReading => $kind !== SendKind::Title,
            self::BeforeRetryWait, self::DuringRetryWait => true,
        };
    }

    /**
     * この位置で受けてよい返り値か(止めるは {@see self::allows()}、続けるは 13.5 の続けるの欄の表)。
     */
    public function accepts(CheckReply $reply, SendKind $kind): bool
    {
        if ($reply->stop !== null) {
            return $this->allows($reply->stop, $kind);
        }

        return match ($this) {
            self::BeforeSend => $reply->stepId !== null,
            default => $reply->stepId === null,
        };
    }
}
