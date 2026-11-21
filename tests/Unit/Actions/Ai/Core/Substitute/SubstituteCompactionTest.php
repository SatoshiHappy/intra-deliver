<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use App\Actions\Ai\Core\ThinkingStart;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\Data\FinishReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\Contract\CoreContractTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版の要約と足す文(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9 の代わりの版・設計/AI/会話の中核.md 13.2 の足す文)。
 * 読める量 40,000・出力の上限 1,000 で止める量 39,000・発動の量 31,200(字数 × 係数 1.0)。問いの 35,000 字で発動し、止める量には届かない。
 */
final class SubstituteCompactionTest extends CoreContractTestCase
{
    private const LONG = 35000;

    protected function core(): ConversationCore
    {
        return new SubstituteConversationCore;
    }

    public function test_a_long_history_is_compacted_at_the_head_of_the_turn(): void
    {
        $outcome = $this->longLoop([FakeStepGateway::response('まとめ'), FakeStepGateway::response('答え')]);

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame(['compact_boundary', 'compact_summary', 'model_text', 'response_end'], $this->appended());
        $this->assertSame([SendKind::Compaction, SendKind::Turn], $this->sentKinds());
        $appends = $this->receivers->noticesOf(AppendNotice::class);
        $this->assertCount(2, $appends[0]->items);
    }

    /**
     * 思考の文脈の管理(docs の 設計/AI/会話の中核.md 13.2): 要約はそれまでの旗で送り(組んだ回の答えの比べを使わない)、境目は要約の
     * ステップの行を指し、境目の後は旗を下ろして要約の完了から比べる。時計の今 = 12:00、始めの最後の完了 = 8:00。
     *
     * @return array<string, array{bool, list<string|array<string, mixed>>}>
     */
    public static function thinkingStarts(): array
    {
        $last = ['type' => 'thinking_turns', 'value' => 1];

        return [
            '始めの旗が立っている' => [true, [$last, 'all']],
            '旗は無いが 4 時間空いた' => [false, ['all', 'all']],
        ];
    }

    /**
     * @param  list<string|array<string, mixed>>  $keeps
     */
    #[DataProvider('thinkingStarts')]
    public function test_compaction_keeps_the_flag_and_the_boundary_lowers_it(bool $idled, array $keeps): void
    {
        $this->loop(
            [FakeStepGateway::response('まとめ'), FakeStepGateway::response('答え')],
            question: str_repeat('a', self::LONG),
            window: 40000,
            traits: new ProviderTraits(contextManagementModels: '/^claude-/'),
            thinking: new ThinkingStart($idled, new DateTimeImmutable('2026-11-18T08:00:00+09:00')),
            thinks: true,
        );

        $keep = static fn (array $one): mixed => $one['options']['context_management']['edits'][0]['keep'] ?? null;
        $sent = array_map($keep, $this->gateway->sent);
        $this->assertSame($keeps, $sent);
        $boundary = $this->receivers->noticesOf(AppendNotice::class)[0]->items[0];
        $this->assertSame(1, $boundary->content['step_id']);
    }

    /** 文が無い・長すぎる・切れた要約は失敗に数え、予備を送らず落とさずに回を続ける */
    public function test_a_failed_compaction_continues_the_turn_without_it(): void
    {
        $body = 'prompt is too long: 9 tokens > 8 maximum';
        $tooLong = new RequestException(new Response(new Psr7Response(400, [], $body)));
        $traits = new ProviderTraits(tooLongPatterns: ['/prompt is too long/']);

        $failures = [FakeStepGateway::response(''), FakeStepGateway::response('途中', FinishReason::Length), $tooLong];

        foreach ($failures as $failed) {
            $outcome = $this->longLoop([$failed, FakeStepGateway::response('答え')], $traits);

            $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
            $this->assertSame(['model_text', 'response_end'], $this->appended());
            $this->assertSame([SendKind::Compaction, SendKind::Turn], $this->sentKinds());
        }
    }

    /** 要約の失敗が 3 回続くと、その実行の中では試さない */
    public function test_three_failures_in_a_row_stop_trying_in_the_run(): void
    {
        $calls = static fn (string $id): mixed => FakeStepGateway::response(
            '',
            FinishReason::ToolCalls,
            [self::call($id)],
        );

        $this->longLoop([
            FakeStepGateway::response(''), $calls('c1'),
            FakeStepGateway::response(''), $calls('c2'),
            FakeStepGateway::response(''), $calls('c3'),
            FakeStepGateway::response('答え'),
        ]);

        $c = SendKind::Compaction;
        $t = SendKind::Turn;
        $this->assertSame([$c, $t, $c, $t, $c, $t, $t], $this->sentKinds());
    }

    /** 要約の文は、要約の答えを文の口の枠に入れた物(作ったファイルが無ければ一覧を継がない) */
    public function test_the_summary_is_the_answer_in_the_summary_frame(): void
    {
        $this->longLoop([FakeStepGateway::response('まとめ'), FakeStepGateway::response('答え')]);

        $summary = $this->receivers->noticesOf(AppendNotice::class)[0]->items[1];
        $this->assertSame('summary:まとめ', $summary->content['text']);
    }

    public function test_the_unknown_write_notice_is_added_once_in_the_run(): void
    {
        $added = new AddedTextValues('2026-11-18', ['unknown_calls' => ['会議の登録']], ['w1']);

        $this->loop([
            FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]),
            FakeStepGateway::response('答え'),
        ], added: $added);

        $expected = ['added_text', 'model_text', 'response_end', 'model_text', 'response_end'];
        $this->assertSame($expected, $this->appended());
    }

    public function test_a_changed_date_is_added_before_the_turn_answer(): void
    {
        $this->loop([FakeStepGateway::response('答え')], added: new AddedTextValues('2026-11-17'));

        $items = $this->receivers->noticesOf(AppendNotice::class)[0]->items;
        $this->assertSame([['new_date' => '2026-11-18']], array_map(
            static fn (ConversationItem $item): mixed => $item->content['values'],
            $items,
        ));
    }

    public function test_pre_run_calls_run_before_the_first_send_without_counting_a_turn(): void
    {
        $outcome = $this->loop(
            [FakeStepGateway::response('答え')],
            maxTurns: 1,
            preRun: [new CoreToolCall('p1', 'search', [])],
        );

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame(['p1'], $this->tools->ran);
    }

    /**
     * @param  list<mixed>  $scripts
     */
    private function longLoop(array $scripts, ?ProviderTraits $traits = null): LoopOutcome
    {
        return $this->loop($scripts, question: str_repeat('a', self::LONG), window: 40000, traits: $traits);
    }

    /**
     * @return list<SendKind>
     */
    private function sentKinds(): array
    {
        return array_map(
            static fn (BeforeSendNotice $notice): SendKind => $notice->kind,
            $this->receivers->noticesOf(BeforeSendNotice::class),
        );
    }

    /** 発動は「超えたら」(見積もり = 指示の文 11 字 + 先頭の日付の文 15 字 + 問い。発動の量 31,200 ちょうどは要約しない) */
    public function test_compaction_starts_only_above_the_threshold(): void
    {
        $this->loop([FakeStepGateway::response('答え')], question: str_repeat('a', 31174), window: 40000);
        $this->assertSame([SendKind::Turn], $this->sentKinds());

        $this->loop(
            [FakeStepGateway::response('まとめ'), FakeStepGateway::response('答え')],
            question: str_repeat('a', 31175),
            window: 40000,
        );
        $this->assertSame([SendKind::Compaction, SendKind::Turn], $this->sentKinds());
    }
}
