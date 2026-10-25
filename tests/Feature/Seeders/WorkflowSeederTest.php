<?php

namespace Tests\Feature\Seeders;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Workflow\RouteDefinitionValidator;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseNumbers;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの初期データ(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 7 節の検査。見せるもの = 設計/ワークフロー/初期データ.md 4 節を追従させたもの): 定義の件数・版の期間と状態・
 * 一本道 A の版の中身、案件の見本 17 の状態・現在のノード・履歴、関連する案件、再申請の差分、未完了の案件のレコードの持ち主、ルートのリソース。
 * 版 3(分岐 B)の中身と足した見本の細部は {@see WorkflowSeederRouteDepthTest}、案件の運用の見本は {@see WorkflowSeederOperationSamplesTest}、
 * 利用者ごとのタブは {@see WorkflowSeederTabsTest}。初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederTest extends TransactionalTestCase
{
    /** 未完了の案件の番号(設計/ワークフロー/案件の運用/初期データ.md 5 節の未完了 10) */
    private const ACTIVE_NUMBERS = [
        'WF-000002', 'WF-000003', 'WF-000004', 'WF-000005', 'WF-000009', 'WF-000010', 'WF-000013', 'WF-000014', 'WF-000015', 'WF-000016',
    ];

    /**
     * 定義: コンテンツ 2・フロー 2・版 4(計画の版 1 だけユーザー無効。期間に重複と空白なし)・ノード 16・辺 13・差戻しできる先 16(設計/ワークフロー/ルートの深さ/初期データ.md 3.1・6 節)・対象者 6
     * (一本道 A の版のノードが同じ対象者を指す。確認する人の 1 つ = 設計/ワークフロー/案件の運用/初期データ.md 5 節)。一本道 A の 3 版は辺が一本道、差戻しできる先は承認のノードごとに前の全部、申請者承認防止はオフ。どの版も定義の検査を通る。
     */
    public function test_seeds_the_definitions_of_the_design(): void
    {
        $flows = WorkflowFlow::query()->with(['workflowContent.recordModule', 'workflowFlowVersions'])->orderBy('sort_key')->get()->map(fn (WorkflowFlow $flow) => [
            $flow->code, $flow->name, $flow->workflowContent?->code, $flow->workflowContent?->recordModule?->code, $flow->sort_key,
            $this->conditionOf((int) $flow->authz_subject_group_id),
            $flow->workflowFlowVersions->sortBy('number')->values()->map(fn (WorkflowFlowVersion $version) => [$version->number, $version->start_date->toDateString(), $version->end_date?->toDateString(), $version->is_deleted])->all(),
        ])->all();

        $this->assertSame([2, 4, 16, 13, 16], [
            WorkflowContent::query()->count(), WorkflowFlowVersion::query()->count(), WorkflowRouteNode::query()->count(), WorkflowRouteEdge::query()->count(), WorkflowRouteSendBack::query()->count(),
        ]);
        $this->assertSame([
            ['project_plan', 'プロジェクト計画の承認', 'project_plan', 'project', 10, 'department accounting', [[1, '2025-04-01', '2026-04-01', true], [2, '2026-04-01', '2026-10-01', false], [3, '2026-10-01', null, false]]],
            ['issue_resolution', '課題の対応の承認', 'issue_resolution', 'project_issue', 20, 'company_post manager', [[1, '2026-04-01', null, false]]],
        ], $flows);
        $this->assertSame([WorkflowSeeder::DATES['plan_version_1'], WorkflowSeeder::DATES['plan_version_2'], WorkflowSeeder::DATES['plan_version_3'], WorkflowSeeder::DATES['issue_version_1']], array_map(fn (array $version) => $version[1], [...$flows[0][6], ...$flows[1][6]]));

        foreach (WorkflowFlowVersion::query()->orderBy('id')->get() as $version) {
            $this->assertSame([], app(RouteDefinitionValidator::class)->violations($version->id), "版 {$version->id}");
        }

        foreach (WorkflowFlowVersion::query()->whereNot(fn ($query) => $query->whereRelation('workflowFlow', 'code', 'project_plan')->where('number', 3))->orderBy('id')->get() as $version) {
            $this->assertSame([
                [1, 'apply', '申請', 'apply', 'fixed', 'role general_user', [], false],
                [2, 'section_chief', '課長の承認', 'approve', 'fixed', 'user u0002', ['apply'], false],
                [3, 'manager', '部長の承認', 'approve', 'fixed', 'user u0003', ['apply', 'section_chief'], false],
            ], $this->routeOf($version));
        }

        $this->assertSame(6, AuthzSubjectGroup::query()->where('purpose', SubjectGroupPurpose::Selection)->count());
    }

    /**
     * 案件の見本 17 = 申請の順に WF-000001〜WF-000016 と一時保存 1(一時保存 1・未完了 10・完了 6 = 設計/ワークフロー/案件の運用/初期データ.md 5 節)。状態・完了の理由・
     * 現在のノード(表示名・状態・処理対象者)・案件名(空で渡した既定)・履歴(種別と処理者)。申請基準日と版は {@see WorkflowSeederRouteDepthTest}。
     */
    public function test_seeds_the_sample_cases_of_the_design(): void
    {
        $cases = WorkflowCase::query()->with(['workflowFlow', 'applicant'])->orderByRaw('case_number nulls last')->get()->map(fn (WorkflowCase $case) => [
            $case->case_number === null ? null : CaseNumbers::format($case->case_number),
            $case->workflowFlow?->code, $case->applicant?->code, $case->name, $case->state->value, $case->completion_reason?->value, $this->currentNodesOf($case), $this->historiesOf($case),
        ])->all();

        $this->assertSame([
            ['WF-000001', 'project_plan', 'u0001', 'プロジェクト計画の承認 営業支援ツールの導入', 'completed', 'approved', [], ['apply u0001', 'approve u0002', 'approve u0003']],
            ['WF-000002', 'issue_resolution', 'u0001', '課題の対応の承認 導入先の部署が決定していない', 'active', null, [['課長の承認', 'waiting', ['u0002']]], ['apply u0001']],
            ['WF-000003', 'project_plan', 'u0001', 'プロジェクト計画の承認 展示会への出展', 'active', null, [['部長の承認', 'waiting', ['u0003']]], ['apply u0001', 'approve u0002']],
            ['WF-000004', 'project_plan', 'u0001', 'プロジェクト計画の承認 販促資料の刷新', 'active', null, [['申請', 'reapply', ['u0001']]], ['apply u0001', 'send_back u0002']],
            ['WF-000005', 'project_plan', 'u0001', 'プロジェクト計画の承認 代理店向け説明会の開催', 'active', null, [['課長の承認', 'waiting', ['u0002']]], ['apply u0001', 'send_back u0002', 'reapply u0001']],
            ['WF-000006', 'issue_resolution', 'u0010', '課題の対応の承認 経費の入力画面が分かりにくい', 'completed', 'rejected', [], ['apply u0010', 'reject u0002']],
            ['WF-000007', 'issue_resolution', 'u0009', '課題の対応の承認 人事の申請の入口が分かれている', 'completed', 'approved_early', [], ['apply u0009', 'approve_early u0002']],
            ['WF-000008', 'project_plan', 'u0001', 'プロジェクト計画の承認 社内勉強会の開催', 'completed', 'withdrawn', [], ['apply u0001', 'send_back u0002', 'withdraw u0001']],
            ['WF-000009', 'project_plan', 'u0001', 'プロジェクト計画の承認 新規顧客開拓の訪問強化', 'active', null, [['関係部署の合議 承認 2', 'waiting', ['u0010']], ['関係部署の合議 承認 3', 'waiting', ['u0016']]], ['apply u0001', 'approve u0002', 'approve u0003', 'approve u0009']],
            ['WF-000010', 'project_plan', 'u0001', 'プロジェクト計画の承認 社内研修の刷新', 'active', null, [['関係部署の合議 承認 1', 'waiting', ['u0009']], ['関係部署の合議 承認 2', 'waiting', ['u0010']]], ['apply u0001', 'approve u0002']],
            ['WF-000011', 'project_plan', 'u0001', 'プロジェクト計画の承認 海外展示会への出展', 'completed', 'rejected', [], ['apply u0001', 'approve u0002', 'approve u0003', 'reject u0009', 'reject u0010']],
            ['WF-000012', 'project_plan', 'u0001', 'プロジェクト計画の承認 営業資料の多言語化', 'completed', 'approved', [], ['apply u0001', 'approve u0002', 'approve u0009', 'approve u0010', 'approve u0003']],
            ['WF-000013', 'project_plan', 'u0001', 'プロジェクト計画の承認 新製品の発売計画', 'active', null, [['部長', 'hold', ['u0003']]], ['apply u0001', 'approve u0002', 'hold u0003']],
            ['WF-000014', 'project_plan', 'u0001', 'プロジェクト計画の承認 第二四半期の営業計画', 'active', null, [['部長', 'waiting', ['u0003']]], ['apply u0001', 'approve u0002']],
            ['WF-000015', 'issue_resolution', 'u0010', '課題の対応の承認 在庫システムの表示不具合', 'active', null, [['課長の承認', 'waiting', ['u0002']]], ['apply u0010']],
            ['WF-000016', 'issue_resolution', 'u0001', '課題の対応の承認 ログイン画面の文言修正', 'active', null, [['課長の承認', 'waiting', ['u0009']]], ['apply u0001', 'transfer u0002']],
            [null, 'issue_resolution', 'u0001', null, 'draft', null, [], []],
        ], $cases);
    }

    /**
     * WF-000002(課題)のレコードは WF-000001(計画)のレコードを参照する: 詳細の関連する案件に参照先・参照元として出る。
     */
    public function test_the_issue_case_and_the_plan_case_are_related(): void
    {
        $issue = $this->show('u0001', 'WF-000002')['related'];
        $plan = $this->show('u0001', 'WF-000001')['related'];

        $this->assertSame([['WF-000001'], []], [array_column($issue['referenced'], 'number'), array_column($issue['referencing'], 'number')]);
        $this->assertSame([[], ['WF-000002']], [array_column($plan['referenced'], 'number'), array_column($plan['referencing'], 'number')]);
    }

    /**
     * WF-000005 の再申請の履歴の差分は予算と終了日(モックの例)。
     */
    public function test_the_reapplication_of_the_fifth_case_changes_the_budget_and_the_end_date(): void
    {
        $histories = $this->show('u0001', 'WF-000005')['histories'];
        $reapplied = array_values(array_filter($histories, fn (array $history) => $history['action'] === 'reapply'));

        $this->assertCount(1, $reapplied);
        $this->assertSame(
            [['end_date', '2027-03-31', '2027-04-30'], ['budget', 300, 350]],
            array_map(fn (array $field) => [$field['code'], $field['before'], $field['after']], $reapplied[0]['diff']['fields'] ?? []),
        );
    }

    /**
     * 未完了の案件(WF-000002〜000005・WF-000009・WF-000010・WF-000013〜000016)のレコードは、業務データの入れ物の業務処理の更新(鍵なし)が 422 record で拒まれる。
     */
    public function test_the_records_of_the_active_cases_are_held(): void
    {
        $context = $this->contextOf('u0004');
        $rejected = [];

        foreach (self::ACTIVE_NUMBERS as $number) {
            $case = $this->caseOf($number);
            $moduleCode = $case->workflowFlow?->workflowContent?->recordModule->code ?? '';

            try {
                app(UpdateRecord::class)->execute($context, $moduleCode, (int) $case->record_id, new RecordInput(['status' => 'done']), version: null);
                $rejected[$number] = null;
            } catch (ValidationException $exception) {
                $rejected[$number] = array_keys($exception->errors());
            }
        }

        $this->assertSame(array_fill_keys(self::ACTIVE_NUMBERS, ['record']), $rejected);
    }

    /**
     * 設計/ワークフロー/ルートの深さ/初期データ.md 1 節、設計/ワークフロー/ルートの深さ/初期データ.md 5 節: 接頭辞のリソースグループ 6 と
     * ルートのリソース 27(ルート 18 本 = 設計/ワークフロー/認可と一覧.md 4 節と、処理対象者の候補と、一括承認・一括確認・利用者の候補・
     * 保留・保留解除・振替・確認 = 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節と、写しの値の入口 = 設計/ワークフロー/申請の周辺/初期データ.md の 3 節。
     * ID・親は名前から決まる。表示名 ja・en = それぞれの節)。
     * ポリシー 54 は WorkflowRouteAuthorizationTest・AuthorizationSeedersTest が確かめる。(定義の管理を足すときは、そのリソースグループ・ルートとメニュー「フロー定義」をここで確かめる)
     */
    public function test_seeds_the_route_resources_of_the_design(): void
    {
        $groups = AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations.locale'])->where('code', 'like', 'http-services.workflow%')->orderBy('id')->get()
            ->map(fn (AuthzResourceGroup $group) => [
                $group->code,
                $group->parent?->code,
                $group->authzResourceGroupTranslations->firstOrFail(fn (AuthzResourceGroupTranslation $translation) => $translation->locale?->code === 'ja')->display_name,
                $group->authzResourceGroupTranslations->firstOrFail(fn (AuthzResourceGroupTranslation $translation) => $translation->locale?->code === 'en')->display_name,
            ])
            ->all();

        $this->assertSame([
            ['http-services.workflow', 'http-services', 'ワークフロー', 'Workflow'],
            ['http-services.workflow.flows', 'http-services.workflow', '申請できるフロー', 'Applicable flows'],
            ['http-services.workflow.applications', 'http-services.workflow', '新規申請', 'New applications'],
            ['http-services.workflow.drafts', 'http-services.workflow', '一時保存', 'Drafts'],
            ['http-services.workflow.cases', 'http-services.workflow', '申請', 'Applications'],
            ['http-services.workflow.reapplications', 'http-services.workflow', '再申請', 'Reapplications'],
            ['http-services.workflow.flows.index', 'http-services.workflow.flows', '申請できるフローの一覧', 'Applicable flow list'],
            ['http-services.workflow.applications.create', 'http-services.workflow.applications', '申請の画面', 'New application form'],
            ['http-services.workflow.applications.store', 'http-services.workflow.applications', '申請', 'Apply'],
            ['http-services.workflow.drafts.store', 'http-services.workflow.drafts', '一時保存', 'Save draft'],
            ['http-services.workflow.drafts.edit', 'http-services.workflow.drafts', '一時保存の画面', 'Draft form'],
            ['http-services.workflow.drafts.update', 'http-services.workflow.drafts', '一時保存の上書き', 'Update draft'],
            ['http-services.workflow.drafts.apply', 'http-services.workflow.drafts', '一時保存からの申請', 'Apply from draft'],
            ['http-services.workflow.drafts.destroy', 'http-services.workflow.drafts', '一時保存の削除', 'Delete draft'],
            ['http-services.workflow.cases.index', 'http-services.workflow.cases', '申請一覧', 'Application list'],
            ['http-services.workflow.cases.show', 'http-services.workflow.cases', '申請の詳細', 'Application detail'],
            ['http-services.workflow.cases.approve', 'http-services.workflow.cases', '承認', 'Approve'],
            ['http-services.workflow.cases.approve-early', 'http-services.workflow.cases', '承認終了', 'Approve and close'],
            ['http-services.workflow.cases.reject', 'http-services.workflow.cases', '否認', 'Reject'],
            ['http-services.workflow.cases.send-back', 'http-services.workflow.cases', '差戻し', 'Send back'],
            ['http-services.workflow.cases.pull-back', 'http-services.workflow.cases', '引戻し', 'Pull back'],
            ['http-services.workflow.cases.withdraw', 'http-services.workflow.cases', '取止め', 'Withdraw'],
            ['http-services.workflow.cases.assignee-candidates', 'http-services.workflow.cases', '処理対象者の候補', 'Assignee candidates'],
            ['http-services.workflow.reapplications.create', 'http-services.workflow.reapplications', '再申請の画面', 'Reapplication form'],
            ['http-services.workflow.reapplications.store', 'http-services.workflow.reapplications', '再申請', 'Reapply'],
            ['http-services.workflow.cases.bulk-approve', 'http-services.workflow.cases', '一括承認', 'Bulk approve'],
            ['http-services.workflow.cases.bulk-confirm', 'http-services.workflow.cases', '一括確認', 'Bulk confirm'],
            ['http-services.workflow.user-candidates', 'http-services.workflow', '利用者の候補', 'User candidates'],
            ['http-services.workflow.cases.hold', 'http-services.workflow.cases', '保留', 'Hold'],
            ['http-services.workflow.cases.release', 'http-services.workflow.cases', '保留解除', 'Release hold'],
            ['http-services.workflow.cases.transfer', 'http-services.workflow.cases', '担当変更', 'Transfer'],
            ['http-services.workflow.cases.confirm', 'http-services.workflow.cases', '確認', 'Confirm'],
            ['http-services.workflow.target-values', 'http-services.workflow', '申請対象の値', 'Target values'],
        ], $groups);
        $this->assertSame(27, AuthzResource::query()->where('uri', 'like', 'service://workflow/%')->count());
    }

    /**
     * 対象者の条件 1 つの「種類 相手のコード」(条件は組み方 any・1 つ・eq = 設計/ワークフロー/初期データ.md 2 節)。
     */
    private function conditionOf(int $subjectGroupId): string
    {
        $group = AuthzSubjectGroup::query()->findOrFail($subjectGroupId);
        $condition = AuthzSubjectCondition::query()->with(['user', 'department', 'companyPost', 'role'])->where('authz_subject_group_id', $subjectGroupId)->sole();
        $this->assertSame([SubjectCombination::Any, SubjectGroupPurpose::Selection], [$group->combination, $group->purpose]);

        return match (true) {
            $condition->user !== null => "user {$condition->user->code}",
            $condition->department !== null => "department {$condition->department->code}",
            $condition->companyPost !== null => "company_post {$condition->companyPost->code}",
            $condition->role !== null => "role {$condition->role->code}",
            default => 'other',
        };
    }

    /**
     * 版のノード(位置の順)の [位置, コード, 名前, 種類, 指定の種類, 処理対象者の条件, 差戻しできる先のコード, 申請者承認防止]。辺が位置の順の一本道であることも確かめる。
     *
     * @return list<array{int, string, string, string, string|null, string, list<string>, bool}>
     */
    private function routeOf(WorkflowFlowVersion $version): array
    {
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $version->id)->orderBy('position')->get();
        $codes = $nodes->pluck('code', 'id');
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $version->id)->orderBy('id')->get()->map(fn (WorkflowRouteEdge $edge) => [$codes[$edge->from_workflow_route_node_id], $codes[$edge->to_workflow_route_node_id]])->all();
        $targets = WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $version->id)->orderBy('target_workflow_route_node_id')->get()->groupBy('workflow_route_node_id');

        $this->assertSame([['apply', 'section_chief'], ['section_chief', 'manager']], $edges);

        return array_values($nodes->map(fn (WorkflowRouteNode $node) => [
            $node->position, $node->code, $node->name, $node->kind->value, $node->target_kind?->value, $this->conditionOf((int) $node->authz_subject_group_id),
            array_values(array_map(fn (WorkflowRouteSendBack $sendBack) => (string) $codes[$sendBack->target_workflow_route_node_id], $targets->get($node->id)?->all() ?? [])),
            $node->prevents_applicant_approval,
        ])->all());
    }

    /**
     * 現在のノード(写した並び。保留中を含む)の [表示名, 状態, 処理対象者のコード]。
     *
     * @return list<array{string, string, list<string>}>
     */
    private function currentNodesOf(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->whereIn('state', ['waiting', 'reapply', 'hold'])->get()->sort(WorkflowCaseNode::compareInRoute(...))->values();

        return array_values($nodes->map(fn (WorkflowCaseNode $node) => [$node->displayName(), $node->state->value, array_values(WorkflowCaseNodeTarget::query()->with('user')
            ->where('workflow_case_node_id', $node->id)->orderBy('user_id')->get()->map(fn (WorkflowCaseNodeTarget $target) => (string) $target->user?->code)->all())])->all());
    }

    /**
     * 履歴(id の順)の「種別 処理者のコード」。
     *
     * @return list<string>
     */
    private function historiesOf(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseHistory::query()->with('actor')->where('workflow_case_id', $case->id)->orderBy('id')->get()
            ->map(fn (WorkflowCaseHistory $history) => "{$history->action->value} {$history->actor?->code}")->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $userCode, string $number): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($userCode), $this->caseOf($number)->id);
    }

    private function caseOf(string $number): WorkflowCase
    {
        return WorkflowCase::query()->with('workflowFlow.workflowContent.recordModule')->where('case_number', (int) substr($number, 3))->sole();
    }

    private function contextOf(?string $userCode): AccessContext
    {
        $userId = $userCode === null ? null : User::query()->where('code', $userCode)->sole()->id;

        return new AccessContext($userId, null, null, CarbonImmutable::today(AccessContext::TIMEZONE), AccessContext::LOCALE_CODE);
    }
}
