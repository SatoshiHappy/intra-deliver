<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Actions\Ai\ToolCalls\ToolJudge;
use App\Actions\Ai\ToolCalls\ToolJudgement;
use App\Actions\Ai\ToolCalls\ToolResultText;
use App\Actions\Ai\ToolCalls\ToolRowAccessWrites;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Ai\Tools\Generic\GenericUpdateTool;
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

    /** 権限のある作るは、確認の内容を作る口を呼んで問う(B5。版は無い。書く値を決めた引数を持つ) */
    public function test_a_create_with_the_operation_asks_with_the_confirmation(): void
    {
        $judgement = $this->judgeCall('ai_create', 'm_create');

        $this->assertSame(AiToolCallDecision::Ask, $judgement->decision);
        $this->assertSame(['next' => ['module' => 'm_create']], $judgement->confirmation?->content);
        $this->assertSame([null, ['module' => 'm_create']], [$judgement->version, $judgement->arguments]);
        $this->assertFalse($judgement->isError);
    }

    /** 権限のある直すは、1 段目で今の値と版を読み、2 段目で確認の内容を作って問う(B7) */
    public function test_an_update_with_the_operations_reads_then_asks(): void
    {
        $tool = $this->updateTool();

        $judgement = $this->judgeCall('ai_update', 'm_update', ['id' => 3]);

        $this->assertSame(AiToolCallDecision::Ask, $judgement->decision);
        $content = ['id' => 3, 'next' => ['module' => 'm_update', 'id' => 3]];
        $this->assertSame($content, $judgement->confirmation?->content);
        $this->assertSame(['v1', 1, 1], [$judgement->version, $tool->reads, count($tool->confirmedWith)]);
    }

    /**
     * 1 段目の見つからない(null・業務モジュールを問わない見つからないの例外)は B6 で、2 段目を呼ばず失敗の印を付けない。
     *
     * @return array<string, array{Closure(): ?RecordCurrentValues}>
     */
    public static function missingCurrents(): array
    {
        return [
            '見つからない' => [fn (): ?RecordCurrentValues => null],
            '判定の後に消えた' => [
                fn (): ?RecordCurrentValues => throw (new ModelNotFoundException)->setModel(RecordModule::class),
            ],
        ];
    }

    /**
     * @param  Closure(): ?RecordCurrentValues  $reader
     */
    #[DataProvider('missingCurrents')]
    public function test_an_update_without_the_current_values_is_not_found(Closure $reader): void
    {
        $tool = $this->updateTool();
        $tool->reader = $reader;

        $judgement = $this->judgeCall('ai_update', 'm_update', ['id' => 3]);

        $expected = [AiToolCallDecision::Deny, AiToolDenialReason::NotFound];
        $this->assertSame($expected, [$judgement->decision, $judgement->reason]);
        $this->assertSame(ToolResultText::full(AgentToolResult::notFound()), $judgement->body);
        $this->assertSame([false, []], [$judgement->isError, $tool->confirmedWith]);
    }

    /** 1 段目のほかの例外は判定の外へ出る(包みの中の例外になる) */
    public function test_another_exception_of_the_first_stage_is_thrown(): void
    {
        $this->updateTool()->reader = fn (): ?RecordCurrentValues => throw new RuntimeException('読めない');

        $this->expectExceptionObject(new RuntimeException('読めない'));

        $this->judgeCall('ai_update', 'm_update', ['id' => 3]);
    }

    /**
     * 今の値を使う検証の誤り(元のチェックが外れのレコードに見せる人だけ = W3)は B19: 断る・理由は入力の誤り・道具の検証の誤りと同じ形の文・
     * 失敗の印あり。2 段目を呼ばない。
     */
    public function test_an_update_against_the_current_values_is_an_input_error(): void
    {
        $tool = $this->updateTool();
        $tool->parties = ['row_access' => AiToolPartyForm::InputRowAccess];

        $judgement = $this->judgeCall('ai_update', 'm_update', ['id' => 3, 'row_access' => [
            'viewers' => [['type' => 'user', 'id' => $this->requester->id]],
        ]]);

        $expected = [AiToolCallDecision::Deny, AiToolDenialReason::InvalidInput];
        $this->assertSame($expected, [$judgement->decision, $judgement->reason]);
        $this->assertSame(AiInstructionTemplate::ToolError, $judgement->template);
        $this->assertSame(['error' => ToolRowAccessWrites::VIEWERS_WITHOUT_RESTRICTION], $judgement->values);
        $this->assertSame([true, []], [$judgement->isError, $tool->confirmedWith]);
    }

    private function updateTool(): GenericUpdateTool
    {
        $tool = $this->granted->find('ai_update')?->tool;

        return $tool instanceof GenericUpdateTool ? $tool : throw new LogicException('ai_update が偽の直す道具でない');
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
