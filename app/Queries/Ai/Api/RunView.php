<?php

namespace App\Queries\Ai\Api;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;

/**
 * AI の実行の表示({@see RunQuery::show()} の結果。docs の 設計/AI/公開の口.md 3 節)。呼び手の画面が状態を数秒ごとに読み直すのに使う。
 * 原文・トークン・費用は持たない(T04 の詳細の画面を作るときに見せ方を決める)。失敗の文は画面に出す文(設計/AI/データと失敗と検査.md 7 節)で、例外の文は持たない。
 */
final readonly class RunView
{
    /** 失敗したときに画面に出す文(設計/AI/データと失敗と検査.md 7 節。下の 2 つの失敗の種類のほか) */
    public const FAILURE_MESSAGE = 'AI の呼び出しに失敗しました。時間をおいて依頼し直してください';

    /** 失敗(設定)の文(会話の型) */
    public const SETTING_FAILURE_MESSAGE = 'AI エージェントの設定を使えません。システム管理者に連絡してください';

    /** 失敗(確認)の文(会話の型) */
    public const CONFIRMATION_FAILURE_MESSAGE = 'この確認はもう使えません。もう一度頼んでください';

    /** 止めた(利用者)の文(会話の型) */
    public const STOPPED_BY_USER_MESSAGE = '止めました';

    /** 止めた(時間の上限・回数の上限)の文(会話の型) */
    public const STOPPED_AT_LIMIT_MESSAGE = '長くなったので止めました';

    /** 止めた(長さ)の文(会話の型。会話は終えない) */
    public const STOPPED_FOR_LENGTH_MESSAGE = '会話が長くなり、AI が一度に読める量を超えました。新しい会話で続けてください';

    /**
     * @param  array{code: string, name: string|null}|null  $requester  依頼者のコードと表示名(依頼者の行が無ければ null)
     * @param  string|null  $startedAt  開始(ISO 8601。始まる前は null)
     * @param  string|null  $finishedAt  終了(ISO 8601。終わる前は null)
     * @param  string|null  $failureMessage  失敗の文(失敗のときだけ)
     * @param  string|null  $stopMessage  止めたの文(止めたのときだけ。費用の上限は予算の区分と期間の文)
     * @param  ConversationRunView|null  $conversation  会話の型が加えて返す物(会話の型のときだけ)
     */
    public function __construct(
        public int $id,
        public string $kind,
        public AiRunStatus $status,
        public ?AiRunErrorCode $errorCode,
        public ?array $requester,
        public string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $failureMessage,
        public ?string $stopMessage = null,
        public ?ConversationRunView $conversation = null,
    ) {}
}
