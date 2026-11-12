<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\ToolCalls\ToolJudge;
use App\Actions\Ai\ToolCalls\ToolJudgement;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolDenialReason;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 判定の関数(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の表 1 の 2 段目)。B10 は止めと業務モジュールの消え方ごとに、読む・作る・直すの
 * それぞれで通す(どの道具でも同じ見つからないになることを見るため)。操作の権限の展開は {@see ToolJudgePermissionsTest}。
 */
final class ToolJudgeTest extends TransactionalTestCase
{
    use ArrangesToolJudgements;

    /** 道具 → その性質が要る操作を許した業務モジュール */
    private const MODULE_OF_TOOL = ['ai_read' => 'm_view', 'ai_create' => 'm_create', 'ai_update' => 'm_update'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeJudgements();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tools(): array
    {
        return ['読む' => ['ai_read'], '作る' => ['ai_create'], '直す' => ['ai_update']];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notFoundCases(): array
    {
        $cases = [];

        foreach (['ai_read', 'ai_create', 'ai_update'] as $tool) {
            foreach (['始めから止め', '途中に立てた止め', '途中に消した', '明細にした', '初めから無い'] as $case) {
                $cases["{$tool}: {$case}"] = [$tool, $case];
            }
        }

        return $cases;
    }

    #[DataProvider('notFoundCases')]
    public function test_a_missing_or_stopped_module_is_not_found(string $tool, string $case): void
    {
        $module = self::MODULE_OF_TOOL[$tool];
        $code = match ($case) {
            '始めから止め' => 'm_stopped',
            '初めから無い' => 'm_never',
            default => $module,
        };
        match ($case) {
            '途中に立てた止め' => $this->stop($this->modules[$module]),
            '途中に消した' => $this->modules[$module]->delete(),
            '明細にした' => $this->modules[$module]->update([
                'parent_record_module_id' => $this->modules['m_out']->id,
                'icon' => null,
                'is_attachment_enabled' => false,
            ]),
            default => null,
        };

        $judgement = $this->judgeCall($tool, $code);

        $expected = [AiToolCallDecision::Deny, AiToolDenialReason::NotFound];
        $this->assertSame($expected, [$judgement->decision, $judgement->reason]);
        $body = '{"error":"'.AgentToolResult::NOT_FOUND.'"}';
        $this->assertSame([null, $body], [$judgement->template, $judgement->body]);
    }

    /** 止めの無い、渡せる範囲の外の業務モジュールは範囲の外で断る(見つからないに当たらない) */
    #[DataProvider('tools')]
    public function test_a_module_out_of_the_range_is_denied_by_rule(string $tool): void
    {
        $judgement = $this->judgeCall($tool, 'm_out');

        $this->assertDenied(AiToolDenialReason::OutOfScope, ToolJudge::OUT_OF_SCOPE, 'm_out', $judgement);
    }

    /** 途中に無効にした依頼者は、操作の判定を呼ばずに断る */
    #[DataProvider('tools')]
    public function test_a_requester_made_inactive_during_the_run_is_denied_without_asking_the_gate(string $tool): void
    {
        $forms = app(CountingRecordFormQuery::class);
        $this->app->instance(RecordFormQuery::class, $forms);
        $this->deactivateRequester();

        $judgement = $this->judgeCall($tool, self::MODULE_OF_TOOL[$tool]);

        $this->assertDenied(AiToolDenialReason::Inactive, ToolJudge::INACTIVE, self::MODULE_OF_TOOL[$tool], $judgement);
        $this->assertSame([], $forms->allowsCalls);
    }

    /** 許すに当たる読むは、判定の主体の文脈(依頼者・実行の選んだ組織・今日の東京の日・ja)を返す */
    public function test_a_read_with_the_operations_is_allowed_with_the_subject(): void
    {
        $judgement = $this->judgeCall('ai_read', 'm_view');

        $this->assertSame(AiToolCallDecision::Allow, $judgement->decision);
        $this->assertEquals(new AccessContext(
            $this->requester->id,
            null,
            $this->run->selected_department_id,
            CarbonImmutable::today(AccessContext::TIMEZONE),
            'ja',
        ), $judgement->subject?->accessContext());
    }

    /** 許すに当たる書く道具は作りの誤り(問うはまだ無い) */
    #[DataProvider('writeTools')]
    public function test_a_write_tool_with_the_operations_is_a_logic_error(string $tool): void
    {
        $this->expectException(LogicException::class);

        $this->judgeCall($tool, self::MODULE_OF_TOOL[$tool]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writeTools(): array
    {
        return ['作る' => ['ai_create'], '直す' => ['ai_update']];
    }

    /** 規則による断りで、添える文が業務モジュールの名前(テストの業務モジュールの名前はコードと同じ)と理由の文 */
    private function assertDenied(
        AiToolDenialReason $reason,
        string $text,
        string $module,
        ToolJudgement $judgement,
    ): void {
        $this->assertSame([AiToolCallDecision::Deny, $reason], [$judgement->decision, $judgement->reason]);
        $this->assertSame(AiInstructionTemplate::RuleDenied, $judgement->template);
        $this->assertSame(['denial_detail' => "業務モジュール {$module}: {$text}"], $judgement->values);
    }
}
