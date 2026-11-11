<?php

namespace App\Actions\Ai\Instructions;

use App\Enums\Ai\AiInstructionCall;
use DateTimeInterface;

/**
 * 指示の組み立ての口が 1 つの呼び出しで受け取る物(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「受け取る物」)。接続先とモデルの値は、
 * その呼び出しの種類が送る用途の設定(ConversationSettings::purpose() の値。表示名と知識の期限は無ければ null)。
 */
final readonly class InstructionRequest
{
    /** 答えの言葉(言葉の口に入れる日本語の値 = docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.8) */
    public const JAPANESE = '日本語';

    /**
     * @param  string  $instructions  エージェントの定義の指示文(空でもよい)
     * @param  list<string>  $toolNames  そのステップに渡す道具の名前(渡す順)
     * @param  DateTimeInterface  $startedAt  実行の始めの時刻(日付の文には東京の日を入れる)
     */
    public function __construct(
        public AiInstructionCall $call,
        public string $instructions,
        public array $toolNames,
        public string $provider,
        public string $modelId,
        public ?string $modelDisplayName,
        public ?string $knowledgeCutoff,
        public string $language,
        public DateTimeInterface $startedAt,
    ) {}
}
