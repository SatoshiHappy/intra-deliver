<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\BuiltInstructions;
use App\Actions\Ai\Instructions\HistoryText;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Enums\Ai\AiInstructionCall;
use App\Enums\Ai\AiInstructionTemplate;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * 指示の組み立ての口の約束のテスト(③ の公開の側の抽象。docs の 設計/AI/設定とプロンプト/README.md 11.4 の「守ること」と「約束のテスト」・
 * 使う文と組み立て.md 11.6 の約束のテスト)。2 つの版がこのトレイトを使うテストのクラスで流す(代わりの版 = {@see DefaultInstructionBuilderTest})。
 * 文の中身は写さず、渡した値が入ること・返す形・保存と送るだけの分けを見る。版は容器から引かずに直に作る。
 * 置き換え口の当て方の分は {@see InstructionPlaceholderContract} に、会話の印の形の一覧の分は {@see InstructionMarkerContract} に分けて置き、
 * このトレイトが使う(使う側はこのトレイトだけを使う)。
 */
trait InstructionBuilderContract
{
    use InstructionMarkerContract;
    use InstructionPlaceholderContract;

    /** 言葉の口に入れる見分けの値(文の中に出ない語。言葉の口に値が入ることと入らないことをこの値で見る) */
    private const LANGUAGE_MARKER = 'language-marker-x';

    /** 試す版 */
    abstract protected function instructionBuilder(): InstructionBuilder;

    public function test_every_call_returns_one_non_empty_system_text_and_the_same_text_for_the_same_values(): void
    {
        foreach (AiInstructionCall::cases() as $call) {
            $built = $this->instructionBuilder()->build(self::request($call));

            $this->assertNotSame('', trim($built->system), $call->value);
            $this->assertEquals($built, $this->instructionBuilder()->build(self::request($call)), $call->value);
        }
    }

    /** 会話のステップ・要約・ファイルを作るでは、日付の文は送るだけで先頭 */
    #[TestWith([AiInstructionCall::ConversationStep])]
    #[TestWith([AiInstructionCall::Compaction])]
    #[TestWith([AiInstructionCall::CreateFile])]
    public function test_the_date_text_is_sent_only_at_the_start(AiInstructionCall $call): void
    {
        $built = $this->instructionBuilder()->build(self::request($call));
        $dated = self::textsContaining($built, '2026-11-10');

        $this->assertNotSame([], $dated);
        $this->assertTrue($dated[0]->atStart);
        $this->assertFalse($dated[0]->stored);
        $this->assertSame($dated[0], $built->history[0]);
    }

    /** 要約と要約の予備は末尾に送るだけの文(要約の指示。③ の決まった追加の指示を含む)を足し、どれも送るだけ */
    #[TestWith([AiInstructionCall::Compaction])]
    #[TestWith([AiInstructionCall::CompactionFallback])]
    public function test_compaction_ends_the_history_with_a_sent_only_text(AiInstructionCall $call): void
    {
        $history = $this->instructionBuilder()->build(self::request($call))->history;
        $last = end($history);

        $this->assertNotFalse($last);
        $this->assertFalse($last->atStart);
        $this->assertFalse($last->stored);
        $custom = (new SharedInstructionTemplates)->compactionCustomInstructions();
        $this->assertStringContainsString($custom, $last->text);
        $this->assertSame([], array_filter($history, fn (HistoryText $text): bool => $text->stored));
    }

    /** 要約の予備と題では、日付の文は送る履歴にもシステムの文にも無い(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5 のほかの用途の呼び出し) */
    #[TestWith([AiInstructionCall::CompactionFallback])]
    #[TestWith([AiInstructionCall::Title])]
    public function test_the_compaction_fallback_and_the_title_have_no_date_text(AiInstructionCall $call): void
    {
        $built = $this->instructionBuilder()->build(self::request($call));

        $this->assertSame([], self::textsContaining($built, '2026-11-10'));
        $this->assertStringNotContainsString('2026-11-10', $built->system);
    }

    public function test_the_compact_summary_contains_the_answer(): void
    {
        $answer = 'Asked for overdue deals';

        $this->assertStringContainsString($answer, $this->instructionBuilder()->compactSummary($answer));
    }

    /** 題の欄は名前を持つ。説明は空でもよい(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「題の答えの形」。文字列であることは型が持つ) */
    public function test_the_title_field_has_a_name(): void
    {
        $this->assertNotSame('', $this->instructionBuilder()->titleField()->name);
    }

    /** 定義の指示文と言葉は会話のステップと要約のシステムの文に入る */
    #[TestWith([AiInstructionCall::ConversationStep])]
    #[TestWith([AiInstructionCall::Compaction])]
    public function test_the_instructions_and_the_language_are_in_the_conversation_text(AiInstructionCall $call): void
    {
        $system = $this->systemOf(self::request($call));

        $this->assertStringContainsString('Answer as the sales desk.', $system);
        $this->assertStringContainsString(self::LANGUAGE_MARKER, $system);
    }

    /** 言葉は要約の予備・題・ファイルを作るのシステムの文に入らない(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.8) */
    #[TestWith([AiInstructionCall::CompactionFallback])]
    #[TestWith([AiInstructionCall::Title])]
    #[TestWith([AiInstructionCall::CreateFile])]
    public function test_the_language_is_not_in_the_other_system_texts(AiInstructionCall $call): void
    {
        $this->assertStringNotContainsString(self::LANGUAGE_MARKER, $this->systemOf(self::request($call)));
    }

    public function test_empty_instructions_still_return_a_text(): void
    {
        $system = $this->systemOf(self::request(AiInstructionCall::ConversationStep, instructions: ''));

        $this->assertNotSame('', trim($system));
    }

    /**
     * モデルの行: 表示名を渡せば表示名、無ければモデルの ID(会話のステップのシステムの文にちょうど 1 回)。知識の期限は渡したときだけ入る
     * (docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6)。
     */
    public function test_the_model_line_uses_the_display_name_or_the_model_id_and_the_cutoff_only_when_given(): void
    {
        $call = AiInstructionCall::ConversationStep;
        $named = $this->systemOf(self::request($call, displayName: 'Model Display X'));
        $unnamed = $this->systemOf(self::request($call, displayName: null));
        $withCutoff = $this->systemOf(self::request($call, cutoff: 'March 2031'));
        $withoutCutoff = $this->systemOf(self::request($call, cutoff: null));

        $this->assertStringContainsString('Model Display X', $named);
        $this->assertSame(1, substr_count($unnamed, 'model-id-x'));
        $this->assertStringContainsString('March 2031', $withCutoff);
        $this->assertStringNotContainsString('March 2031', $withoutCutoff);
        $this->assertLessThan(strlen($withCutoff), strlen($withoutCutoff));
    }

    /** 日付の文には実行の始めの東京の日が入る(境 = 東京の 0 時 = 協定世界時の 15 時) */
    #[TestWith(['2026-11-09T14:59:59Z', '2026-11-09'])]
    #[TestWith(['2026-11-09T15:00:00Z', '2026-11-10'])]
    #[TestWith(['2026-11-09T15:00:01Z', '2026-11-10'])]
    public function test_the_date_text_has_the_tokyo_day_of_the_run_start(string $startedAt, string $day): void
    {
        $request = self::request(AiInstructionCall::ConversationStep, startedAt: $startedAt);

        $this->assertNotSame([], self::textsContaining($this->instructionBuilder()->build($request), $day));
    }

    public function test_every_template_returns_a_text_and_shared_ones_match_the_shared_texts(): void
    {
        $shared = new SharedInstructionTemplates;

        foreach (AiInstructionTemplate::cases() as $template) {
            $text = $this->instructionBuilder()->template($template, self::templateValues());

            $this->assertNotSame('', trim($text), $template->value);

            if ($template->isShared()) {
                $this->assertSame($shared->text($template, self::templateValues()), $text, $template->value);
            }
        }
    }

    public function test_the_reason_of_a_rejection_is_put_in_as_it_is_and_required(): void
    {
        $reason = 'Please use the March figures instead.';
        $template = AiInstructionTemplate::ToolUseRejectedWithReason;

        $text = $this->instructionBuilder()->template($template, ['user_feedback' => $reason]);

        $this->assertStringContainsString($reason, $text);

        $this->expectException(LogicException::class);
        $this->instructionBuilder()->template($template, []);
    }

    /**
     * 呼び出しの値(指定の無い値は試す値の既定)。
     *
     * @param  list<string>  $toolNames
     */
    private static function request(
        AiInstructionCall $call,
        string $instructions = 'Answer as the sales desk.',
        ?string $displayName = 'Model Display X',
        ?string $cutoff = 'March 2031',
        string $startedAt = '2026-11-10T01:00:00Z',
        array $toolNames = ['search_records', 'read_record'],
    ): InstructionRequest {
        return new InstructionRequest(
            call: $call,
            instructions: $instructions,
            toolNames: $toolNames,
            provider: 'test-provider',
            modelId: 'model-id-x',
            modelDisplayName: $displayName,
            knowledgeCutoff: $cutoff,
            language: self::LANGUAGE_MARKER,
            startedAt: CarbonImmutable::parse($startedAt),
        );
    }

    /**
     * どの定型にも足りる値(呼び手が渡す鍵の全部)。
     *
     * @return array<string, string|list<string>>
     */
    private static function templateValues(): array
    {
        return [
            'user_feedback' => 'Use the other customer.',
            'tool_name' => 'search_records',
            'denial_detail' => 'Deals: you cannot edit this record.',
            'problems' => ['The module field is required.'],
            'error' => 'The record was changed by someone else.',
            'original_length' => '120000',
            'preview_length' => '20000',
            'preview' => 'first part of the result',
            'created_files' => ['report.xlsx (file 7)'],
            'unknown_calls' => ['update_record (call 3)'],
        ];
    }

    /**
     * 送る履歴に足す文のうち、文字を含む物。
     *
     * @return list<HistoryText>
     */
    private static function textsContaining(BuiltInstructions $built, string $needle): array
    {
        $containing = array_filter($built->history, fn (HistoryText $text): bool => str_contains($text->text, $needle));

        return array_values($containing);
    }

    /** 組んだシステムの文 */
    private function systemOf(InstructionRequest $request): string
    {
        return $this->instructionBuilder()->build($request)->system;
    }
}
