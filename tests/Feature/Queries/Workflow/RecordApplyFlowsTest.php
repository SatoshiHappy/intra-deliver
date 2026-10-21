<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicableFlows;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\RecordApplyFlow;
use App\Queries\Workflow\RecordApplyFlows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請をできる人の判定の 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 1 節・8 節): 申請のノードの対象者 +
 * 業務モジュールの read と update(create は求めない)。flowsFor・allowsAny は自分か代理元のどれか、allows はその人だけ。申請者の候補と既定の申請者
 * (設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)は allows で 1 人ずつ判定する。フローと業務モジュールはテストで作る。
 */
final class RecordApplyFlowsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;
    use RegistersProxies;

    private const DATE = '2026-10-13';

    /**
     * create だけの人は既存のデータからの申請をできず新規はでき、read と update だけの人はその逆。read だけ・update だけはできない。
     */
    public function test_from_a_record_needs_read_and_update_and_not_create(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $flows = [
            'create_only' => $this->flow('create_only', [RecordModuleAction::Create], $u0001),
            'read_update' => $this->flow('read_update', [RecordModuleAction::Read, RecordModuleAction::Update], $u0001),
            'read_only' => $this->flow('read_only', [RecordModuleAction::Read], $u0001),
            'update_only' => $this->flow('update_only', [RecordModuleAction::Update], $u0001),
        ];
        $requester = $this->requester($u0001);
        $judge = fn (WorkflowFlow $flow) => [
            app(ApplicableFlows::class)->isApplicable($requester, self::day(), $flow->id),
            $this->flows()->allows($requester, $flow->id, self::day()),
            $this->flows()->allowsAny($requester, $flow->id, self::day()),
            $this->codesFor($requester, "m_{$flow->code}"),
        ];

        $this->assertSame([
            'create_only' => [true, false, false, []],
            'read_update' => [false, true, true, ['read_update']],
            'read_only' => [false, false, false, []],
            'update_only' => [false, false, false, []],
        ], array_map($judge, $flows));
    }

    /**
     * 代理(自分は申請のノードの対象者でなく、代理元が対象者): flowsFor・allowsAny は通り、allows は代理元で通り自分では通らない。
     * create だけの代理元は出ない。承認の代理・期間の外の代理は効かない。
     */
    public function test_a_principal_counts_for_flows_for_and_allows_any_but_not_for_allows(): void
    {
        [$principal, $proxy, $other] = $this->seededUsers('u0009', 'u0002', 'u0003');
        $flow = $this->flow('by_principal', [RecordModuleAction::Read, RecordModuleAction::Update], $principal);
        $createOnly = $this->flow('create_principal', [RecordModuleAction::Create], $principal);
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $flow->id);
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $createOnly->id);
        $this->addProxy($principal, $other, WorkflowProxyKind::Approve, $flow->id);
        $this->addProxy($principal, $other, WorkflowProxyKind::Apply, $flow->id, start: '2026-10-14');
        $day = self::day();

        $this->assertSame(['by_principal'], $this->codesFor($this->requester($proxy), 'm_by_principal'));
        $this->assertSame([true, false, true], [
            $this->flows()->allowsAny($this->requester($proxy), $flow->id, $day),
            $this->flows()->allows($this->requester($proxy), $flow->id, $day),
            $this->flows()->allows($this->requester($principal), $flow->id, $day),
        ]);
        $this->assertSame([$principal->id], $this->flows()->applicantIds($this->requester($proxy), $flow->id, $day));
        $this->assertSame([false, []], [
            $this->flows()->allowsAny($this->requester($proxy), $createOnly->id, $day),
            $this->flows()->applicantIds($this->requester($proxy), $createOnly->id, $day),
        ]);
        $newApplication = app(ApplicableFlows::class)
            ->isApplicable($this->requester($principal), $day, $createOnly->id);
        $this->assertTrue($newApplication);
        $byOther = $this->requester($other);
        $byOtherAny = $this->flows()->allowsAny($byOther, $flow->id, $day);
        $this->assertSame([false, []], [$byOtherAny, $this->codesFor($byOther, 'm_by_principal')]);
    }

    /**
     * flowsFor の並びはフローの一覧と同じ(sort_key・code)。今日の版が有効なフローだけ、業務モジュールのコードで絞る。値はフローの名前と説明。
     */
    public function test_flows_for_follows_the_flow_list_order_and_filters_by_the_module_and_the_version(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $all = [RecordModuleAction::Create, RecordModuleAction::Read, RecordModuleAction::Update];
        $this->flow('b_flow', $all, $u0001, 'm_shared', sortKey: 2, description: '説明');
        $this->flow('z_flow', $all, $u0001, 'm_shared', sortKey: 1);
        $this->flow('a_flow', $all, $u0001, 'm_shared', sortKey: 1);
        $future = $this->flow('future', $all, $u0001, 'm_shared');
        WorkflowFlowVersion::query()->where('workflow_flow_id', $future->id)->update(['start_date' => '2026-10-14']);
        $this->flow('other_module', $all, $u0001);

        $listed = $this->flows()->flowsFor($this->requester($u0001), 'm_shared', self::day());
        $context = new AccessContext($u0001->id, null, null, self::day(), 'ja');
        $flowList = array_values(array_filter(
            array_column(app(ApplicableFlows::class)->flows($context), 'code'),
            fn (string $code) => in_array($code, ['a_flow', 'b_flow', 'z_flow', 'future'], true),
        ));

        $codes = array_map(fn (RecordApplyFlow $flow) => $flow->code, $listed);
        $this->assertSame(['a_flow', 'z_flow', 'b_flow'], $codes);
        $this->assertSame($flowList, $codes);
        $this->assertSame([['b_flow の名前', '説明']], array_map(
            fn (RecordApplyFlow $flow) => [$flow->name, $flow->description],
            array_values(array_filter($listed, fn (RecordApplyFlow $flow) => $flow->code === 'b_flow')),
        ));
        $this->assertSame([], $this->flows()->flowsFor($this->requester(null), 'm_shared', self::day()));
    }

    /**
     * 同じ組で、allowsAny と flowsFor(ボタン・ダイアログ・GET・入口)は申請者の候補が空でないことと同じで、候補の各人は allows(申請基準日の (4)・POST の 0)が通る。
     */
    public function test_allows_any_agrees_with_the_applicants_judged_by_allows(): void
    {
        [$principal, $proxy, $u0001] = $this->seededUsers('u0009', 'u0002', 'u0001');
        $actions = [RecordModuleAction::Read, RecordModuleAction::Update];
        $flows = [
            $this->flow('self_only', $actions, $proxy),
            $this->flow('principal_only', $actions, $principal),
            $this->flow('nobody', $actions, $u0001),
            $this->flow('create_only', [RecordModuleAction::Create], $principal),
        ];

        foreach ($flows as $flow) {
            $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $flow->id);
            $requester = $this->requester($proxy);
            $applicants = $this->flows()->applicantIds($requester, $flow->id, self::day());
            $listed = $this->codesFor($requester, "m_{$flow->code}");
            $allowsAny = $this->flows()->allowsAny($requester, $flow->id, self::day());

            $this->assertSame($applicants !== [], $allowsAny, $flow->code);
            $this->assertSame($applicants === [] ? [] : [$flow->code], $listed, $flow->code);

            foreach ($applicants as $userId) {
                $allows = $this->flows()->allows(new Requester($userId, null), $flow->id, self::day());
                $this->assertTrue($allows, $flow->code);
            }
        }
    }

    /**
     * 既定の申請者(案件の画面.md の 6.1 節): クエリの代理元が候補にあればその人、自分が候補にあれば自分(null)、ほかは候補の先頭の代理元。
     * 候補が無ければ 403。
     */
    public function test_the_default_applicant_from_a_record(): void
    {
        [$a, $b, $proxy, $u0001] = $this->seededUsers('u0003', 'u0009', 'u0002', 'u0001');
        $actions = [RecordModuleAction::Read, RecordModuleAction::Update];
        $withSelf = $this->flow('with_self', $actions, [$proxy, $a, $b], 'm_default');
        $principalsOnly = $this->flow('principals_only', $actions, [$a, $b], 'm_default');
        $none = $this->flow('none', $actions, [$u0001], 'm_default');

        foreach ([$withSelf, $principalsOnly, $none] as $flow) {
            $this->addProxy($a, $proxy, WorkflowProxyKind::Apply, $flow->id);
            $this->addProxy($b, $proxy, WorkflowProxyKind::Apply, $flow->id);
        }

        $context = new AccessContext($proxy->id, null, null, self::day(), 'ja');
        $forms = app(ApplicationFormQuery::class);
        $default = fn (WorkflowFlow $flow, ?int $query) => $forms->recordApplicant($context, $flow->id, $query);
        $firstPrincipal = min($a->id, $b->id);
        $secondPrincipal = max($a->id, $b->id);

        $this->assertSame([null, $secondPrincipal, null], [
            $default($withSelf, null), $default($withSelf, $secondPrincipal), $default($withSelf, $u0001->id),
        ]);
        $this->assertSame(
            [$firstPrincipal, $secondPrincipal],
            [$default($principalsOnly, null), $default($principalsOnly, $secondPrincipal)],
        );
        $applicants = $this->flows()->applicantIds($this->requester($proxy), $withSelf->id, self::day());
        $this->assertSame([$proxy->id, $firstPrincipal, $secondPrincipal], $applicants);
        $this->expectException(AuthorizationException::class);
        $default($none, null);
    }

    private function flows(): RecordApplyFlows
    {
        return app(RecordApplyFlows::class);
    }

    /**
     * @return list<string> flowsFor のフローのコード(並びのまま)
     */
    private function codesFor(Requester $requester, string $moduleCode): array
    {
        $flows = $this->flows()->flowsFor($requester, $moduleCode, self::day());

        return array_map(fn (RecordApplyFlow $flow) => $flow->code, $flows);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }

    private function requester(?User $user): Requester
    {
        return new Requester($user?->id, null);
    }

    /**
     * フロー(コンテンツの業務モジュール = $moduleCode(無ければ m_<コード>。作るときに全員へ $permitted を許す)、版 1 つ(2000-01-01 から上限なし)の
     * ルート = 申請(渡した人が対象者)+ 承認)。
     *
     * @param  list<RecordModuleAction>  $permitted
     * @param  User|list<User>  $applicants
     */
    private function flow(
        string $code,
        array $permitted,
        User|array $applicants,
        ?string $moduleCode = null,
        int $sortKey = 0,
        ?string $description = null,
    ): WorkflowFlow {
        $moduleCode ??= "m_{$code}";
        $module = RecordModule::query()->where('code', $moduleCode)->first()
            ?? $this->recordModule($moduleCode, $permitted);
        $flow = WorkflowFlow::factory()->create([
            'code' => $code,
            'name' => "{$code} の名前",
            'description' => $description,
            'workflow_content_id' => WorkflowContent::factory()->create(['record_module_id' => $module->id])->id,
            'sort_key' => $sortKey,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        WorkflowRouteNode::factory()->create([
            'workflow_flow_version_id' => $version->id,
            'position' => 1,
            'authz_subject_group_id' => app(CreateSelectionSubjectGroup::class)->execute(array_map(
                fn (User $user) => new SelectionCondition(SelectionConditionKind::User, $user->id),
                is_array($applicants) ? $applicants : [$applicants],
            ), ['ja' => '申請']),
        ]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 2]);

        return $flow;
    }
}
