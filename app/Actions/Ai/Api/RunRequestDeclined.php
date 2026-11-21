<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\RunBudget;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * AI の依頼の口が依頼を断った 422(キー ai。docs の 設計/AI/実行と費用/接続先と費用.md 5.2)。呼び手が文を比べずに理由を分けられるよう、理由のコードを持つ
 * (会議は文字起こしの完了の中の議事録の依頼が断られた理由を残す)。HTTP の応答は ValidationException と同じ。
 */
final class RunRequestDeclined extends ValidationException
{
    /** 予算の区分の上限を超える */
    public const BUDGET = 'budget';

    /** 送る文が入力の上限の文字数を超える・音声が 1 本の長さの上限を超える */
    public const TOO_LONG = 'too_long';

    /** 音声の長さを測れない(壊れた音声・音声の流れが無い) */
    public const UNREADABLE = 'unreadable';

    /** 音声の実体が無い(呼び手が選んだ後に消された・期限で消された) */
    public const MISSING = 'missing';

    /** 会話の前の実行が待ち・処理中(設計/AI/公開の口.md 3 節の B11) */
    public const PREVIOUS_RUNNING = 'previous_running';

    /** 確認に答える依頼の前の実行が確認待ちでない終わり方で終わっている(B12) */
    public const CONFIRMATION_GONE = 'confirmation_gone';

    /** 会話の前の実行に続く実行がもうある(B13) */
    public const CONTINUED = 'continued';

    /** 定義の道具の定義の大きさが上限を超える(設計/AI/設定とプロンプト/README.md 11.3) */
    public const SETTING = 'setting';

    /**
     * @param  string  $reason  理由のコード(このクラスの定数)
     * @param  string  $message  画面に出す文(キー ai)
     */
    public function __construct(public readonly string $reason, string $message)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add(RunBudget::ERROR_KEY, $message);

        parent::__construct($validator);
    }
}
