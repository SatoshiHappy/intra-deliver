<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\RecordMarker;
use App\Actions\Ai\ToolCalls\ToolJudge;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCall;
use Tests\Support\TransactionalTestCase;

/**
 * 道具を呼ぶ包み(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4・設計/AI/実行の記録.md 2.4): 道具の行と会話の保存の項目を
 * 両方見るのは、モデルに届く物と実行の記録がずれないことを確かめるため。止める印と時間の上限 = {@see ToolCallWrapperStopTest}、
 * 例外 = {@see ToolCallWrapperExceptionsTest}。
 */
final class ToolCallWrapperTest extends TransactionalTestCase
{
    use RunsToolCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
    }

    public function test_unknown_tools_are_wrapper_errors_with_the_name_given_by_the_model(): void
    {
        $outcome = $this->wrap([
            self::toolCall('c1', ''),
            self::toolCall('c2', 'ai_[[rec:m:1]]'),
            self::toolCall('c3', 'ai_extra'),
        ]);

        foreach (['c1' => '', 'c2' => 'ai_[ [rec:m:1]]', 'c3' => 'ai_extra'] as $callId => $name) {
            $this->assertRow($callId, AiToolCallDecision::UnknownTool, AiToolCallStatus::ToolError);
        }

        $this->assertSame(['ai_[[rec:m:1]]'], [$this->row('c2')->tool_name]);
        $this->assertSame([
            self::content('c1', 'unknown_tool', ['tool_name' => ''], null, true),
            self::content('c2', 'unknown_tool', ['tool_name' => 'ai_[ [rec:m:1]]'], null, true),
            self::content('c3', 'unknown_tool', ['tool_name' => 'ai_extra'], null, true),
        ], $this->storedContents());
        $this->assertSame(['c1', 'c2', 'c3'], array_column($outcome->results, 'callId'));
        $this->assertSame(0, $this->tool('ai_read')->executions);
    }

    /** 形の誤りは欠け・知らない鍵・型の違いを 1 行ずつ並べ、道具の検証を呼ばない。道具の検証の誤りは道具の文(印の形を直す) */
    public function test_invalid_input_lists_its_problems_and_differs_from_a_validation_error(): void
    {
        $this->tool('ai_read')->validator = fn (array $arguments): ?string => $arguments['module'] === 'bad'
            ? '値 [[rec:m:2]] が正しくない'
            : null;

        $this->wrap([
            self::toolCall('c1', 'ai_read', ['id' => 'x', 'extra' => 1, 'tags' => [1]]),
            self::toolCall('c2', 'ai_read', ['module' => 'bad']),
        ]);

        $this->assertRow('c1', AiToolCallDecision::InvalidInput, AiToolCallStatus::ToolError);
        $this->assertRow('c2', AiToolCallDecision::ValidationError, AiToolCallStatus::ToolError);
        $this->assertSame([
            self::content('c1', 'tool_input_invalid', ['tool_name' => 'ai_read', 'problems' => [
                'module: 欠けている',
                'id: 型が違う(integer)',
                'tags.0: 型が違う(string)',
                'extra: 知らない鍵',
            ]], null, true),
            self::content('c2', 'tool_error', ['error' => '値 [ [rec:m:2]] が正しくない'], null, true),
        ], $this->storedContents());
        $this->assertCount(1, $this->tool('ai_read')->validatedWith);
    }

    /** 判定の関数が断れば断られた(理由つき)。規則による断りは定型と値と失敗の印、見つからないは本文だけ(印なし)。道具は呼ばない */
    public function test_a_denial_is_rejected_with_its_reason_and_the_tool_is_not_called(): void
    {
        $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_out']),
            self::toolCall('c2', 'ai_read', ['module' => 'm_gone']),
        ]);

        $this->assertRow('c1', AiToolCallDecision::Deny, AiToolCallStatus::Rejected, 'out_of_scope');
        $this->assertRow('c2', AiToolCallDecision::Deny, AiToolCallStatus::Rejected, 'not_found');
        $this->assertSame([
            self::content('c1', 'rule_denied', [
                'tool_name' => 'ai_read',
                'denial_detail' => '業務モジュール m_out: '.ToolJudge::OUT_OF_SCOPE,
            ], null, true),
            self::content('c2', null, [], '{"error":"'.AgentToolResult::NOT_FOUND.'"}', false),
        ], $this->storedContents());
        $this->assertSame(0, $this->tool('ai_read')->executions);
        $this->assertSame('{"error":"'.AgentToolResult::NOT_FOUND.'"}', $this->row('c2')->text?->result_text);
    }

    /** 判定の関数の見つからない(B10)と道具の見つからない(B3)は、会話の保存の項目(呼び出しの id のほか)と道具の行の本文が同じ */
    public function test_the_not_found_of_the_judge_and_of_the_tool_have_the_same_body(): void
    {
        $this->tool('ai_read')->executor = fn (): AgentToolResult => AgentToolResult::notFound();

        $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_gone']),
            self::toolCall('c2', 'ai_read', ['module' => 'm_view']),
        ]);

        $withoutId = fn (array $content): array => array_diff_key($content, ['call_id' => true]);
        [$judged, $tool] = array_map($withoutId, $this->storedContents());
        $this->assertSame(1, $this->tool('ai_read')->executions);
        $this->assertSame($tool, $judged);
        $this->assertSame($this->row('c2')->text?->result_text, $this->row('c1')->text?->result_text);
    }

    /** 許すは行を処理中で確定してから道具を呼び(道具の中から読める)、済みと全文と読んだレコードと会話の保存の足すを書く */
    public function test_an_allowed_call_runs_the_tool_after_the_running_row_and_records_the_result(): void
    {
        $seen = null;
        $this->tool('ai_read')->executor = function () use (&$seen): AgentToolResult {
            $seen = AiToolCall::query()->where('call_id', 'c1')->value('status');

            return new AgentToolResult(
                ['record' => new RecordMarker('m_view', 7), 'title' => '定例 [[rec:x:1]]'],
                [['module' => 'm_view', 'id' => 7, 'name' => '定例']],
            );
        };

        $outcome = $this->wrap([self::toolCall('c1', 'ai_read', ['module' => 'm_view'])]);

        $full = '{"record":"[[rec:m_view:7]]","title":"定例 [ [rec:x:1]]"}';
        $this->assertSame(AiToolCallStatus::Running, $seen);
        $this->assertRow('c1', AiToolCallDecision::Allow, AiToolCallStatus::Done);
        // 読んだレコードの列は jsonb(鍵の順を持たない)
        $this->assertEquals([['module' => 'm_view', 'id' => 7]], $this->row('c1')->records);
        $this->assertEquals([[$full, [['module' => 'm_view', 'id' => 7, 'name' => '定例']]]], [[
            $this->row('c1')->text?->result_text,
            $this->row('c1')->text?->record_names,
        ]]);
        $this->assertSame([self::content('c1', null, [], $full, false)], $this->storedContents());
        $this->assertSame($this->row('c1')->id, $outcome->results[0]['toolCallId']);
    }

    /** 判定の主体の文脈は呼び出しごとに作る */
    public function test_the_subject_is_made_for_each_call(): void
    {
        $subjects = [];
        $this->tool('ai_read')->executor = function (array $arguments, $subject) use (&$subjects): AgentToolResult {
            $subjects[] = $subject;

            return new AgentToolResult(['ok' => true]);
        };

        $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_view']),
            self::toolCall('c2', 'ai_read', ['module' => 'm_view']),
        ]);

        $this->assertCount(2, $subjects);
        $this->assertNotSame($subjects[0], $subjects[1]);
        $this->assertEquals($subjects[0], $subjects[1]);
        $this->assertSame($this->requester->id, $subjects[0]->accessContext()->userId);
    }

    private function assertRow(
        string $callId,
        AiToolCallDecision $decision,
        AiToolCallStatus $status,
        ?string $reason = null,
    ): void {
        $row = $this->row($callId);

        $this->assertSame([$decision, $status, $reason], [$row->decision, $row->status, $row->decision_reason]);
        $this->assertNotNull($row->finished_at);
    }

    /**
     * 道具の結果の項目の中身。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function content(
        string $callId,
        ?string $template,
        array $values,
        ?string $body,
        bool $isError,
    ): array {
        return [
            'call_id' => $callId,
            'template' => $template,
            'values' => $values,
            'body' => $body,
            'is_error' => $isError,
        ];
    }
}
