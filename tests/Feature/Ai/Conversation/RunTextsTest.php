<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\RunTexts;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;
use Tests\TestCase;

/**
 * 文の口の中身(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 送信の種別を指示の組み立ての口の呼び出しの種類に写し、送るときだけ足す文を
 * 置く所で分け、日付の文に中核が渡した日を入れる。
 */
final class RunTextsTest extends TestCase
{
    private const PURPOSE = [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5-5',
        'display_name' => 'Claude Sonnet 5.5',
        'knowledge_cutoff' => null,
        'provider_options' => [],
    ];

    public function test_a_turn_puts_the_date_at_the_start(): void
    {
        $composed = $this->texts()->instructions(SendKind::Turn, ['search_records'], '2026-11-19');

        $this->assertNotSame('', $composed->system);
        $this->assertCount(1, $composed->atStart);
        $this->assertStringContainsString('2026-11-19', $composed->atStart[0]);
        $this->assertSame([], $composed->atEnd);
    }

    /** 要約の依頼は末尾に足し、そのステップに渡す道具の名前を入れる */
    public function test_a_compaction_puts_the_request_at_the_end(): void
    {
        $composed = $this->texts()->instructions(SendKind::Compaction, ['search_records'], '2026-11-19');

        $this->assertCount(1, $composed->atEnd);
        $this->assertNotSame('', $composed->atEnd[0]);
    }

    public function test_the_title_is_not_composed_by_the_loop(): void
    {
        $this->expectException(LogicException::class);

        $this->texts()->instructions(SendKind::Title, [], '2026-11-19');
    }

    /** 定型・要約の文・会話の印の形は組み立ての口のまま */
    public function test_the_other_texts_come_from_the_builder(): void
    {
        $builder = new DefaultInstructionBuilder;
        $texts = $this->texts();

        $this->assertSame(
            $builder->template(AiInstructionTemplate::UserCancelled, []),
            $texts->template(AiInstructionTemplate::UserCancelled, []),
        );
        $this->assertSame($builder->compactSummary('まとめ'), $texts->compactSummary('まとめ'));
        $this->assertSame($builder->conversationMarkerForms(), $texts->markerForms());
    }

    private function texts(): RunTexts
    {
        return new RunTexts(new DefaultInstructionBuilder, '', self::PURPOSE);
    }
}
