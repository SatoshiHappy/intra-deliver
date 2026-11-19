<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\ToolRowAccessWrites;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Ai\AiToolCallText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Tests\Feature\Ai\Tools\Generic\GenericCreateTool;
use Tests\Feature\Ai\Tools\Generic\GenericUpdateTool;
use Tests\Support\TransactionalTestCase;

/**
 * 問うの包み(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の 5・設計/AI/実行の記録.md 2.4 の T5): 道具を動かさず、道具の行を
 * 判定 = 問う・状態 = 確認待ちで書き、確認の内容(前の件の k を足した物。k の数え方 = {@see ToolWriteChecksTest})と直すの版(文字列のまま)を
 * 残し、会話の保存に足さない。後の呼び出しの
 * 前の呼び出しに判定 = 問うと書く値を決めた引数が入る。直すの B6 は失敗の印なし、B19 は失敗の印ありで道具の検証の誤りと同じ形。
 */
final class ToolCallAsksTest extends TransactionalTestCase
{
    use RunsToolCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
    }

    /** 作るは確認待ちの行と確認の内容を書き、版は無く、会話の保存にも結果にも入らない。後の呼び出しは前の問うを受ける */
    public function test_a_create_waits_for_the_confirmation_without_a_result(): void
    {
        $outcome = $this->wrap([
            self::toolCall('c1', 'ai_create', ['module' => 'm_create']),
            self::toolCall('c2', 'ai_create', ['module' => 'm_create']),
        ]);

        foreach (['c1', 'c2'] as $callId) {
            $row = $this->row($callId);
            $expected = [AiToolCallDecision::Ask, AiToolCallStatus::AwaitingConfirmation];
            $this->assertSame($expected, [$row->decision, $row->status]);
            $this->assertSame([null, null], [$row->confirmation_version, $row->finished_at]);
            $this->assertSame(['next' => ['module' => 'm_create'], 'references' => []], $this->confirmation($callId));
        }

        $this->assertSame([], $this->storedContents());
        $this->assertSame([[], ['c1', 'c2']], [$outcome->results, array_column($outcome->asks, 'callId')]);
        $rowIds = array_map(fn (string $id): int => $this->row($id)->id, ['c1', 'c2']);
        $this->assertSame($rowIds, array_column($outcome->asks, 'toolCallId'));
        $prior = $this->creator()->validatedWith[1][0];
        $this->assertSame([AiToolCallDecision::Ask, ['module' => 'm_create']], [$prior->decision, $prior->arguments]);
        $this->assertSame(0, $this->creator()->executions);
    }

    /** 直すの版は入れ物の版の文字列のまま道具の行に残り、確認の内容には入らない */
    public function test_an_update_keeps_the_version_on_the_row(): void
    {
        $this->wrap([self::toolCall('c1', 'ai_update', ['module' => 'm_update', 'id' => 3])]);

        $row = $this->row('c1');
        $this->assertSame([AiToolCallDecision::Ask, 'v1'], [$row->decision, $row->confirmation_version]);
        $this->assertArrayNotHasKey('version', $this->confirmation('c1'));
    }

    /** 直すの 1 段目の見つからない(B6)は断られたで、本文は見つからないの文、失敗の印なし。会話の保存に結果が入る */
    public function test_a_missing_record_of_an_update_is_rejected_as_not_found(): void
    {
        $this->updater()->reader = fn () => null;

        $outcome = $this->wrap([self::toolCall('c1', 'ai_update', ['module' => 'm_update', 'id' => 3])]);

        $row = $this->row('c1');
        $this->assertSame([AiToolCallDecision::Deny, AiToolCallStatus::Rejected], [$row->decision, $row->status]);
        $this->assertSame(AiToolDenialReason::NotFound->value, $row->decision_reason);
        $this->assertSame([false], array_column($this->storedContents(), 'is_error'));
        $this->assertSame([[], ['c1']], [$outcome->asks, array_column($outcome->results, 'callId')]);
    }

    /** 直すの今の値を使う検証の誤り(B19)は断られたで、理由は入力の誤り、結果は道具の検証の誤りと同じ形で失敗の印あり */
    public function test_an_input_error_after_the_current_values_is_rejected_like_a_validation_error(): void
    {
        $updater = $this->updater();
        $updater->parties = ['row_access' => AiToolPartyForm::InputRowAccess];
        $updater->shape = fn (JsonSchema $s): array => [
            'module' => $s->string()->required(),
            'id' => $s->integer(),
            'row_access' => $s->object(fn (JsonSchema $s): array => ['viewers' => $s->array()]),
        ];
        $viewers = [['type' => 'user', 'id' => $this->requester->id]];

        $this->wrap([self::toolCall('c1', 'ai_update', [
            'module' => 'm_update',
            'id' => 3,
            'row_access' => ['viewers' => $viewers],
        ])]);

        $row = $this->row('c1');
        $this->assertSame([AiToolCallDecision::Deny, AiToolCallStatus::Rejected], [$row->decision, $row->status]);
        $this->assertSame(AiToolDenialReason::InvalidInput->value, $row->decision_reason);
        $this->assertSame([[
            'call_id' => 'c1',
            'template' => 'tool_error',
            'values' => ['error' => ToolRowAccessWrites::VIEWERS_WITHOUT_RESTRICTION],
            'body' => null,
            'is_error' => true,
        ]], array_map(fn (array $content): array => array_intersect_key($content, array_flip([
            'call_id', 'template', 'values', 'body', 'is_error',
        ])), $this->storedContents()));
        $this->assertSame([], $updater->confirmedWith);
    }

    private function creator(): GenericCreateTool
    {
        $tool = $this->tool('ai_create');

        return $tool instanceof GenericCreateTool ? $tool : self::fail('ai_create が偽の作る道具でない');
    }

    private function updater(): GenericUpdateTool
    {
        $tool = $this->tool('ai_update');

        return $tool instanceof GenericUpdateTool ? $tool : self::fail('ai_update が偽の直す道具でない');
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmation(string $callId): array
    {
        $confirmation = AiToolCallText::query()->whereKey($this->row($callId)->id)->sole()->confirmation;

        return is_array($confirmation) ? $confirmation : [];
    }
}
