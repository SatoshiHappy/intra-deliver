<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Enums\Ai\AiInstructionTemplate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 会話の保存の口の約束のテスト(③ の公開の側の抽象。docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「守る結果」のうち ① が実装する口の分)。
 * ① は本物の口で、このトレイトを使うテストのクラスを作って流す(この区切りの偽物 = {@see FakeConversationStoreTest})。
 * 入れる約束: 足すが全部か無しか(呼び手のトランザクションの中と外)・会話が無ければ無し・対応の無い実行で無し・読むが最後の要約の境目(を含む)から
 * 後ろを足した順に返す・読むが足した写しを値と鍵の順のまま返す。受け手と作ったファイルの受け手の約束は後の区切り。
 */
trait ConversationStoreContract
{
    /** 試す口 */
    abstract protected function conversationStore(): ConversationStore;

    /** 会話を 1 つ作り、その会話の最初の実行(対応を書いた実行)の id を返す */
    abstract protected function runInNewConversation(): int;

    /** 実行と同じ会話に続く実行を作って対応を書き、その id を返す */
    abstract protected function nextRunInSameConversation(int $runId): int;

    /** 対応の無い実行(会話が消えた実行と同じ扱い)の id を返す */
    abstract protected function runWithoutConversation(): int;

    /** 実行の会話を消す(対応も一緒に消える) */
    abstract protected function deleteConversationOf(int $runId): void;

    /** 次の足すが、$writes 件を書いた後に例外を投げるようにする(途中の項目の失敗を作る) */
    abstract protected function failNextAppendAfter(int $writes): void;

    public function test_append_adds_the_items_in_the_given_order_and_read_returns_them(): void
    {
        $runId = $this->runInNewConversation();
        $items = [ConversationItem::userText('一つ目'), ConversationItem::modelText([ConversationItem::textBlock('二つ目')])];

        $this->assertTrue($this->conversationStore()->append($runId, $items));
        $this->assertTrue($this->conversationStore()->append($runId, [ConversationItem::userText('三つ目')]));

        $this->assertEquals([...$items, ConversationItem::userText('三つ目')], $this->conversationStore()->read($runId));
    }

    /** 呼び手のトランザクションの中で足した項目は、呼び手が巻き戻すと何も残らない */
    public function test_append_inside_the_transaction_of_the_caller_is_rolled_back_with_it(): void
    {
        $runId = $this->runInNewConversation();

        DB::beginTransaction();
        $this->conversationStore()->append($runId, [ConversationItem::userText('巻き戻る')]);
        DB::rollBack();

        $this->assertSame([], $this->conversationStore()->read($runId));
    }

    /** 呼び手のトランザクションの外で、途中の項目が例外になると、前の項目も残らない */
    public function test_append_outside_a_transaction_of_the_caller_writes_nothing_when_an_item_fails(): void
    {
        $runId = $this->runInNewConversation();
        $this->failNextAppendAfter(1);
        $thrown = null;

        try {
            $items = [ConversationItem::userText('一つ目'), ConversationItem::userText('二つ目')];
            $this->conversationStore()->append($runId, $items);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, '途中の項目の失敗が投げられなかった');
        $this->assertSame([], $this->conversationStore()->read($runId));
    }

    public function test_a_deleted_conversation_is_none_and_append_writes_nothing(): void
    {
        $runId = $this->runInNewConversation();
        $this->deleteConversationOf($runId);

        $this->assertFalse($this->conversationStore()->append($runId, [ConversationItem::userText('書かれない')]));
        $this->assertNull($this->conversationStore()->read($runId));
    }

    public function test_a_run_without_a_conversation_is_none(): void
    {
        $runId = $this->runWithoutConversation();

        $this->assertFalse($this->conversationStore()->append($runId, [ConversationItem::userText('書かれない')]));
        $this->assertNull($this->conversationStore()->read($runId));
    }

    /** 読むは、同じ会話のどの実行を渡しても、最後の要約の境目(を含む)から後ろを足した順に返す */
    public function test_read_returns_the_items_from_the_last_compact_boundary_in_the_order_they_were_added(): void
    {
        $first = $this->runInNewConversation();
        $this->conversationStore()->append($first, [
            ConversationItem::userText('前の文'),
            ConversationItem::compactBoundary(),
            ConversationItem::compactSummary('前の要約'),
        ]);
        $second = $this->nextRunInSameConversation($first);
        $after = [
            ConversationItem::compactBoundary(),
            ConversationItem::compactSummary('後の要約'),
            ConversationItem::userText('後の文'),
            ConversationItem::toolResult('call_1', null, [], '結果', false),
        ];
        $this->conversationStore()->append($second, [ConversationItem::userText('境目の前'), ...$after]);

        $this->assertEquals($after, $this->conversationStore()->read($second));
        $this->assertEquals($after, $this->conversationStore()->read($first));
    }

    /**
     * 読むは足した写しを値と鍵の順のまま返す(道具の呼び出しの引数の入れ子と、最上位と入れ子の空の配列を含む。形の版 2 の境目のほかの種類と、
     * 道具の結果の定型の値の鍵の順と列を含む)
     */
    public function test_read_returns_the_copies_with_the_same_values_and_key_order(): void
    {
        $runId = $this->runInNewConversation();
        $arguments = ['zeta' => 1, 'alpha' => ['omega' => [], 'beta' => 'b', 'list' => [3, 1, 2]], 'empty' => []];
        $items = [
            ConversationItem::modelText([
                ConversationItem::toolCallBlock('call_1', 'search_records', $arguments),
                ConversationItem::toolCallBlock('call_2', 'list_modules', []),
                ConversationItem::textBlock('探します'),
            ]),
            ConversationItem::toolResult('call_1', AiInstructionTemplate::LargeResultSearch, [
                'zeta' => '1',
                'alpha' => ['b', 'a'],
                'empty' => [],
            ], '先頭', false),
            ConversationItem::toolResult('c2', AiInstructionTemplate::EmptyResult, ['tool_name' => 'x'], null, false),
            ConversationItem::toolResult('call_3', AiInstructionTemplate::ToolUseRejectedWithReason, [
                ConversationItem::USER_FEEDBACK => '別の件で',
            ], null, true),
            ConversationItem::userText('文'),
            ConversationItem::interruption(true),
            ConversationItem::turnLimit(),
            ConversationItem::compactSummary('要約'),
        ];
        $this->conversationStore()->append($runId, $items);

        $copies = static fn (?array $read): array => array_map(
            fn (ConversationItem $item): array => $item->toArray(),
            $read ?? [],
        );

        $this->assertSame($copies($items), $copies($this->conversationStore()->read($runId)));
    }

    public function test_a_conversation_without_a_compact_boundary_is_read_from_the_start(): void
    {
        $runId = $this->runInNewConversation();
        $items = [ConversationItem::userText('文'), ConversationItem::interruption(false)];
        $this->conversationStore()->append($runId, $items);

        $this->assertEquals($items, $this->conversationStore()->read($runId));
    }
}
