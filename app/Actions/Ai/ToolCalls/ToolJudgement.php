<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolDenialReason;

/**
 * 判定の関数の答え(docs の 設計/AI/道具と権限/README.md 10.1 の判定の関数。許す = 判定の主体の文脈つき、問う = 確認の内容と直すの版と
 * 書く値を決めた引数つき、断る = 文と理由つき)。断るの文は、規則による断りの定型と判定の側が添える文か、見つからないの文(規則による断りの定型を
 * 使わない = 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5)か、今の値を読んだ後の入力の誤りの文(道具の検証の誤りと同じ形 = 10.3 の B19)。
 */
final readonly class ToolJudgement
{
    /**
     * @param  array<string, string>  $values  定型に入れる値(規則による断りの道具の名前は包みが足す)
     * @param  bool  $isError  会話の保存の項目の失敗の印(見つからないは道具が返す見つからないと同じく付けない = 9.4)
     * @param  array<string, mixed>|null  $arguments  問うの書く値を決めた引数
     */
    private function __construct(
        public AiToolCallDecision $decision,
        public ?AgentToolSubject $subject,
        public ?AiToolDenialReason $reason,
        public ?AiInstructionTemplate $template,
        public array $values,
        public ?string $body,
        public bool $isError,
        public ?RecordConfirmation $confirmation = null,
        public ?string $version = null,
        public ?array $arguments = null,
    ) {}

    public static function allow(AgentToolSubject $subject): self
    {
        return new self(AiToolCallDecision::Allow, $subject, null, null, [], null, false);
    }

    /**
     * 問う(10.3 の B5・B7)。道具を動かさず、包みが確認の内容と版を道具の行に書く。
     *
     * @param  array<string, mixed>  $arguments  書く値を決めた引数(相手を id に直し、参照制限の 2 つのキーを決めた物)
     * @param  string|null  $version  直すの呼ばれた時の版(作るは null)
     */
    public static function ask(RecordConfirmation $confirmation, ?string $version, array $arguments): self
    {
        $decision = AiToolCallDecision::Ask;

        return new self($decision, null, null, null, [], null, false, $confirmation, $version, $arguments);
    }

    /** 今の値を読んだ後の入力の誤り(10.3 の B19。結果は道具の検証の誤りと同じ形で、確認を出さない) */
    public static function invalidInput(string $error): self
    {
        $reason = AiToolDenialReason::InvalidInput;
        $template = AiInstructionTemplate::ToolError;

        return new self(AiToolCallDecision::Deny, null, $reason, $template, ['error' => $error], null, true);
    }

    /** 規則による断り(業務モジュールの名前と理由の文を添える) */
    public static function deniedByRule(AiToolDenialReason $reason, string $detail): self
    {
        return new self(AiToolCallDecision::Deny, null, $reason, AiInstructionTemplate::RuleDenied, [
            'denial_detail' => $detail,
        ], null, true);
    }

    /**
     * 見つからない(10.3 の B10)。本文は道具が返す見つからない(B3)の結果の本文と同じ JSON(10.3 の「見つからないは道具が返す結果の文」。
     * 形が分かれると、どの断りに当たったかがモデルに分かる)。
     */
    public static function notFound(): self
    {
        $reason = AiToolDenialReason::NotFound;
        $body = ToolResultText::full(AgentToolResult::notFound());

        return new self(AiToolCallDecision::Deny, null, $reason, null, [], $body, false);
    }
}
