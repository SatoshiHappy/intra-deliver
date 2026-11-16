<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Records\Api\RecordFormQuery;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 2 段目の判定を本物の探す・読むで通す(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B10・B2 と、実行の途中の権限の変化 =
 * 設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4): 止め・無くなった・明細になった・初めから無い業務モジュールは見つからない、範囲の外・
 * 途中に無効にした依頼者・同じジョブの中で途中に閲覧のロールか所属を外した依頼者は規則による断り。どれも道具の口を呼ばない。
 */
final class RecordToolJudgementsTest extends TransactionalTestCase
{
    use RunsRecordTools;

    /** @var array<string, RecordModule> */
    private array $modules = [];

    /** @var array<string, Closure(): void> 外し方 → 外す関数 */
    private array $revocations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeRequester();
        $read = [RecordModuleAction::Read];

        foreach (['m_view', 'm_out', 'm_stopped'] as $code) {
            $this->modules[$code] = $this->moduleFor($code, $read);
        }

        $this->stop($this->modules['m_stopped']);
        $this->modules['m_role'] = $this->recordModule('m_role', []);
        $role = $this->secondRole('m_role', RecordModuleAction::Read);
        $this->modules['m_department'] = $this->recordModule('m_department', []);
        $membership = $this->departmentGrant('m_department', RecordModuleAction::Read);
        $this->revocations = [
            'm_role' => function () use ($role): void {
                $role->update(['is_deleted' => true]);
            },
            'm_department' => function () use ($membership): void {
                $membership->update(['is_deleted' => true]);
            },
        ];
        $this->logRecordReads();
        $this->startRecordToolRun(['m_view', 'm_stopped', 'm_role', 'm_department']);
    }

    /**
     * 道具ごとの引数(読むは業務モジュールのレコードの id を足す)。
     *
     * @return array<string, mixed>
     */
    private function arguments(string $tool, string $module): array
    {
        $moduleId = RecordModule::query()->where('code', $module)->value('id');
        $id = $moduleId === null ? 1 : Record::factory()->create(['record_module_id' => $moduleId])->id;

        return $tool === 'ai_read_record' ? ['module' => $module, 'id' => $id] : ['module' => $module];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notFoundCases(): array
    {
        $cases = [];

        foreach (['ai_search_records', 'ai_read_record'] as $tool) {
            foreach (['始めから止め', '途中に立てた止め', '途中に消した', '明細にした', '初めから無い'] as $case) {
                $cases["{$tool}: {$case}"] = [$tool, $case];
            }
        }

        return $cases;
    }

    #[DataProvider('notFoundCases')]
    public function test_a_stopped_or_missing_module_is_not_found_without_calling_the_apis(
        string $tool,
        string $case,
    ): void {
        $code = match ($case) {
            '始めから止め' => 'm_stopped',
            '初めから無い' => 'm_never',
            default => 'm_view',
        };
        $arguments = $this->arguments($tool, $code);
        Record::query()->where('record_module_id', $this->modules['m_view']->id)->delete();
        match ($case) {
            '途中に立てた止め' => $this->stop($this->modules['m_view']),
            '途中に消した' => $this->modules['m_view']->delete(),
            '明細にした' => $this->modules['m_view']->update([
                'parent_record_module_id' => $this->modules['m_out']->id,
                'icon' => null,
                'is_attachment_enabled' => false,
            ]),
            default => null,
        };

        $content = $this->callTool($tool, $arguments);

        $this->assertSame(['error' => AgentToolResult::NOT_FOUND], self::body($content));
        $this->assertSame(AiToolDenialReason::NotFound->value, $this->toolRow()->decision_reason);
        $this->assertSame([], $this->reads->calls);
    }

    /**
     * 規則による断り(範囲の外・途中に無効・途中に閲覧のロールか所属を外した)。各行 = 道具・業務モジュール・外し方・理由。
     *
     * @return array<string, array{string, string, string, AiToolDenialReason}>
     */
    public static function ruleDenials(): array
    {
        $cases = [];

        foreach (['ai_search_records', 'ai_read_record'] as $tool) {
            $cases["{$tool}: 範囲の外"] = [$tool, 'm_out', 'none', AiToolDenialReason::OutOfScope];
            $cases["{$tool}: 途中に無効"] = [$tool, 'm_view', 'inactive', AiToolDenialReason::Inactive];
            $cases["{$tool}: 途中にロールを外した"] = [$tool, 'm_role', 'm_role', AiToolDenialReason::NoPermission];
            $cases["{$tool}: 途中に所属を外した"] = [$tool, 'm_department', 'm_department', AiToolDenialReason::NoPermission];
        }

        return $cases;
    }

    #[DataProvider('ruleDenials')]
    public function test_a_call_denied_by_rule_does_not_call_the_apis(
        string $tool,
        string $module,
        string $revocation,
        AiToolDenialReason $reason,
    ): void {
        $arguments = $this->arguments($tool, $module);

        $judgedThenRevoked = function () use ($tool, $module, $revocation, $arguments): array {
            $subject = app(ToolSubjects::class)->for($this->run);
            $forms = app(RecordFormQuery::class);
            $forms->allows($subject->requester(), $module, RecordModuleAction::Read, $subject->date());
            match ($revocation) {
                'inactive' => $this->deactivateRequester(),
                'none' => null,
                default => ($this->revocations[$revocation])(),
            };

            return $this->callTool($tool, $arguments);
        };
        $content = app(AuthorizationReuse::class)->within($judgedThenRevoked);

        $this->assertSame(AiInstructionTemplate::RuleDenied->value, $content['template']);
        $this->assertSame($reason->value, $this->toolRow()->decision_reason);
        $this->assertSame([], $this->reads->calls);
    }

    /** 外さなければ、同じ許し方で道具が呼ばれる(上の断りが外し方によることの確かめ) */
    public function test_the_same_permissions_without_revocation_call_the_apis(): void
    {
        $this->callTool('ai_search_records', ['module' => 'm_role'], 'c1');
        $this->callTool('ai_search_records', ['module' => 'm_department'], 'c2');

        $this->assertSame(['RecordListQuery::list', 'RecordListQuery::list'], $this->reads->calls);
    }
}
