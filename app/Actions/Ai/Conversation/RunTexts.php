<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\ComposedInstructions;
use App\Actions\Ai\Core\LoopTexts;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Instructions\HistoryText;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Enums\Ai\AiInstructionCall;
use App\Enums\Ai\AiInstructionTemplate;
use App\Queries\Ai\ConversationSettings;
use App\Support\AccessContext;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * ループと 1 回の送信が使う文の口の中身(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。指示の組み立ての口
 * (設計/AI/設定とプロンプト/README.md 11.4)を呼ぶだけで、文を持たない。
 *
 * @phpstan-import-type ConversationPurpose from ConversationSettings
 */
final readonly class RunTexts implements LoopTexts
{
    /**
     * @param  string  $instructions  エージェントの定義の指示文
     * @param  ConversationPurpose  $purpose  会話の用途の設定
     */
    public function __construct(
        private InstructionBuilder $builder,
        private string $instructions,
        private array $purpose,
    ) {}

    public function instructions(SendKind $kind, array $toolNames, string $date): ComposedInstructions
    {
        $call = match ($kind) {
            SendKind::Turn, SendKind::Continuation => AiInstructionCall::ConversationStep,
            SendKind::Compaction => AiInstructionCall::Compaction,
            SendKind::CompactionFallback => AiInstructionCall::CompactionFallback,
            SendKind::Title => throw new LogicException('題の指示の文はループで組まない'),
        };
        $built = $this->builder->build(new InstructionRequest(
            $call,
            $this->instructions,
            $toolNames,
            $this->purpose['provider'],
            $this->purpose['model'],
            $this->purpose['display_name'],
            $this->purpose['knowledge_cutoff'],
            InstructionRequest::JAPANESE,
            new DateTimeImmutable($date, new DateTimeZone(AccessContext::TIMEZONE)),
        ));

        return new ComposedInstructions(
            $built->system,
            self::texts($built->history, true),
            self::texts($built->history, false),
        );
    }

    public function template(AiInstructionTemplate $template, array $values): string
    {
        return $this->builder->template($template, $values);
    }

    public function compactSummary(string $answer): string
    {
        return $this->builder->compactSummary($answer);
    }

    public function markerForms(): array
    {
        return $this->builder->conversationMarkerForms();
    }

    /**
     * 送るときだけ足す文を、置く所で分ける(会話の保存に書く文は、ループの送信の指示には無い = 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5)。
     *
     * @param  list<HistoryText>  $history
     * @return list<string>
     *
     * @throws LogicException 会話の保存に書く文があるとき
     */
    private static function texts(array $history, bool $atStart): array
    {
        $texts = [];

        foreach ($history as $text) {
            if ($text->stored) {
                throw new LogicException('ループの送信の指示に、会話の保存に書く文がある');
            }

            if ($text->atStart === $atStart) {
                $texts[] = $text->text;
            }
        }

        return $texts;
    }
}
