<?php

namespace Database\Seeders;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\CreateWorkflowContent;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\WithdrawCase;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\ServiceAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * ワークフローの初期データ(docs の 設計/ワークフロー/ルートの深さ/初期データ.md。中身の正本はこの seeder、設計の値 = 2〜4 節)。RecordsSeeder の後に 1 回入れる前提。
 * すべて業務処理を通す: 処理対象者と参照者の対象者 5(用途 selection)はフローを作る業務処理の中で作り、定義(コンテンツ 2・フロー 2・版 4 = 計画の版 1(ユーザー無効)・2 が
 * 一本道 A、版 3 が分岐 B、課題の版 1 が一本道 A。一本道 A の版は同じ対象者を指す = 2 節・3 節)の後に、案件の見本 13 を案件の操作の業務処理で順に作る
 * (番号は申請の順に WF-000001 から。2 段目の見本 9 は申請基準日を版 2・版 1 に置き、足した見本 4 は版 3 = 4 節)。
 *
 * 判定の入力は操作する人ごとの AccessContext(接続元・選んだ組織なし、基準日 = 流す日の Asia/Tokyo の日付、ロケール ja)。処理の日時は流した時刻
 * (業務処理が操作した時の時計で付ける)。
 * 申請の案件名は空で渡して既定にする(docs の 設計/ワークフロー/初期データ.md 4 節)。業務データは RecordsSeeder のプロジェクトを参照する。
 *
 * 先頭に、接頭辞のリソースグループ 6・ルートのリソース 19 と、各ルートのゲストユーザ・認証済みユーザへの execute の許可(38。認可と一覧.md 4 節)を入れる
 * (初期データ.md 1 節の 1。接頭辞のリソースグループには許可を置かない)。メニューのグループ「ワークフロー」は MenuSeeder が入れる。
 *
 * 日付(版の開始日・2 段目の計画の見本の申請基準日)は {@see self::DATES} の 1 か所に基準の日の値で置き、使う所で流す日からの相対にする
 * ({@see SeedingDates}。docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1)。
 */
class WorkflowSeeder extends Seeder
{
    use BuildsWorkflowSampleRecords;
    use FindsAuthzSubjects;
    use SeedsWorkflowRouteCases;
    use SeedsWorkflowRouteVersions;

    /**
     * 初期データの日付(1 か所。docs の 設計/ワークフロー/ルートの深さ/初期データ.md 3.1・4 節): 計画のフローの版の開始日、課題のフローの版 1 の開始日、2 段目の計画の見本の申請基準日(版 2 の中)。
     * 足した見本と課題の見本の申請基準日は流す日(版 3・課題の版 1)。
     */
    public const DATES = [
        'plan_version_1' => '2025-04-01',
        'plan_version_2' => '2026-04-01',
        'plan_version_3' => '2026-10-01',
        'issue_version_1' => '2026-04-01',
        'stage2_plan_base_date' => '2026-09-01',
    ];

    /** 接頭辞のリソースグループ: 接頭辞 => [表示名(ja), 表示名(en)] */
    private const PREFIXES = [
        'workflow' => ['ワークフロー', 'Workflow'],
        'workflow.flows' => ['申請できるフロー', 'Applicable flows'],
        'workflow.applications' => ['新規申請', 'New applications'],
        'workflow.drafts' => ['一時保存', 'Drafts'],
        'workflow.cases' => ['申請', 'Applications'],
        'workflow.reapplications' => ['再申請', 'Reapplications'],
    ];

    /** ルート: 名前 => [表示名(ja), 表示名(en)](どれもゲストユーザと認証済みユーザに execute を許す) */
    private const ROUTES = [
        'workflow.flows.index' => ['申請できるフローの一覧', 'Applicable flow list'],
        'workflow.applications.create' => ['申請の画面', 'New application form'],
        'workflow.applications.store' => ['申請', 'Apply'],
        'workflow.drafts.store' => ['一時保存', 'Save draft'],
        'workflow.drafts.edit' => ['一時保存の画面', 'Draft form'],
        'workflow.drafts.update' => ['一時保存の上書き', 'Update draft'],
        'workflow.drafts.apply' => ['一時保存からの申請', 'Apply from draft'],
        'workflow.drafts.destroy' => ['一時保存の削除', 'Delete draft'],
        'workflow.cases.index' => ['申請一覧', 'Application list'],
        'workflow.cases.show' => ['申請の詳細', 'Application detail'],
        'workflow.cases.approve' => ['承認', 'Approve'],
        'workflow.cases.approve-early' => ['承認終了', 'Approve and close'],
        'workflow.cases.reject' => ['否認', 'Reject'],
        'workflow.cases.send-back' => ['差戻し', 'Send back'],
        'workflow.cases.pull-back' => ['引戻し', 'Pull back'],
        'workflow.cases.withdraw' => ['取止め', 'Withdraw'],
        'workflow.cases.assignee-candidates' => ['処理対象者の候補', 'Assignee candidates'],
        'workflow.reapplications.create' => ['再申請の画面', 'Reapplication form'],
        'workflow.reapplications.store' => ['再申請', 'Reapply'],
    ];

    /** コンテンツ: コード => [業務モジュールのコード, 名前] */
    private const CONTENTS = [
        'project_plan' => ['project', 'プロジェクト計画'],
        'issue_resolution' => ['project_issue', '課題の対応'],
    ];

    /** ルートのノード(位置の順): [コード, 名前, 種類, 処理対象者の条件の種類, 相手のコード, 表示名(ja), 表示名(en)] */
    private const NODES = [
        ['apply', '申請', WorkflowNodeKind::Apply, SelectionConditionKind::Role, 'general_user', '申請(課長・部長の承認)', 'Apply (Manager approval)'],
        ['section_chief', '課長の承認', WorkflowNodeKind::Approve, SelectionConditionKind::User, 'u0002', '課長の承認(課長・部長の承認)', 'Section chief approval (Manager approval)'],
        ['manager', '部長の承認', WorkflowNodeKind::Approve, SelectionConditionKind::User, 'u0003', '部長の承認(課長・部長の承認)', 'Manager approval (Manager approval)'],
    ];

    /**
     * フロー: コード => [名前, 説明, コンテンツのコード, 参照者の条件の種類, 相手のコード, 表示名(ja), 表示名(en), 並び, 版 1 の開始日, 版 1 がユーザー無効か]
     * (ルートの深さ/初期データ.md 3.1)。版 1 のルートは 1 つ目のフローが {@see self::NODES} で作り、2 つ目は 1 つ目の版 1 と同じノード(同じ対象者)。
     */
    private const FLOWS = [
        'project_plan' => ['プロジェクト計画の承認', 'プロジェクトの担当・期間・予算とマイルストーンの計画を、上長と部長の承認で決めます。', 'project_plan',
            SelectionConditionKind::Department, 'accounting', '参照者(プロジェクト計画の承認)', 'Referrers (Project plan approval)', 10, self::DATES['plan_version_1'], true],
        'issue_resolution' => ['課題の対応の承認', 'プロジェクトで起きた課題の対応方針を、上長と部長の承認で決めます。', 'issue_resolution',
            SelectionConditionKind::CompanyPost, 'manager', '参照者(課題の対応の承認)', 'Referrers (Issue resolution approval)', 20, self::DATES['issue_version_1'], false],
    ];

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
        private CreateWorkflowContent $createContent,
        private CreateWorkflowFlow $createFlow,
        private AddRouteVersion $addVersion,
        private CreateDraft $createDraft,
        private ApplyCase $apply,
        private ApproveCase $approve,
        private ApproveCaseEarly $approveEarly,
        private RejectCase $reject,
        private SendBackCase $sendBack,
        private ReapplyCase $reapply,
        private WithdrawCase $withdraw,
    ) {}

    public function run(): void
    {
        $this->createRoutes();
        $this->createDefinitions();
        $this->createCases();
        $this->createRouteCases();
    }

    /**
     * 接頭辞のリソースグループと、ルートのリソース・ポリシー(RecordsSeeder の業務のルートと同じ書き方)。
     */
    private function createRoutes(): void
    {
        foreach (self::PREFIXES as $prefix => [$ja, $en]) {
            $this->createPrefix->execute($prefix, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);
        }

        $everyone = [$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)];

        foreach (self::ROUTES as $name => [$ja, $en]) {
            $resource = $this->createRouteResource->execute($name, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute($resource->authz_resource_group_id, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
            }
        }
    }

    /**
     * コンテンツ 2・フロー 2(版 1 とノード 3。処理対象者の対象者 3 を 1 つ目のフローの業務処理が作り、2 つ目は同じ対象者を指す。参照者の対象者 2 も
     * フローの業務処理が作る)と、計画のフローの版 2(一本道 A)・版 3(分岐 B)。
     */
    private function createDefinitions(): void
    {
        foreach (self::CONTENTS as $code => [$moduleCode, $name]) {
            $this->createContent->execute($moduleCode, $code, $name);
        }

        $nodes = array_map(
            fn (array $node) => new WorkflowRouteNodeInput($node[0], $node[1], $node[2], [$this->condition($node[3], $node[4])], ['ja' => $node[5], 'en' => $node[6]]),
            self::NODES,
        );
        $route = $nodes;

        foreach (self::FLOWS as $code => [$name, $description, $contentCode, $kind, $target, $ja, $en, $sortKey, $startDate, $isFirstVersionDeleted]) {
            $this->createFlow->execute($code, $name, $description, $contentCode, $route, SeedingDates::date($startDate), [$this->condition($kind, $target)], ['ja' => $ja, 'en' => $en], $sortKey, $isFirstVersionDeleted);
            $route = $code;
        }

        $subjects = $this->firstVersionSubjects('project_plan');
        $this->addVersion->execute('project_plan', SeedingDates::date(self::DATES['plan_version_2']), $this->linearRoute($subjects), [
            new RouteEdgeInput('apply', 'section_chief'), new RouteEdgeInput('section_chief', 'manager'),
        ]);
        [$branchNodes, $branchEdges] = $this->branchRoute($subjects['apply']);
        $this->addVersion->execute('project_plan', SeedingDates::date(self::DATES['plan_version_3']), $branchNodes, $branchEdges);
    }

    /**
     * 案件の見本 9(設計/ワークフロー/初期データ.md 4 節の表の順。申請の順に案件番号が付く)。計画の申請基準日 = 版 2 の日、課題 = 流す日(版 1)。
     */
    private function createCases(): void
    {
        $first = $this->applyPlan('u0001', 1, '営業支援ツールの導入計画を申請します。');
        $this->approve->execute($this->contextOf('u0002'), $first, $this->revisionOf($first), $this->currentNodeIdOf($first), '確認しました。');
        $this->approve->execute($this->contextOf('u0003'), $first, $this->revisionOf($first), $this->currentNodeIdOf($first), '承認します。');

        $this->applyIssue('u0001', '導入先の部署が決定していない', $this->recordIdOf($first), 'u0001', 'medium', '2026-11-13');

        $third = $this->applyPlan('u0001', 3);
        $this->approve->execute($this->contextOf('u0002'), $third, $this->revisionOf($third), $this->currentNodeIdOf($third), '部長の確認をお願いします。');

        $fourth = $this->applyPlan('u0001', 4);
        $this->sendBackToApply($fourth, '対象の資料の一覧を添えてください。');

        $fifth = $this->applyPlan('u0001', 5, '代理店向け説明会の計画を申請します。');
        $this->sendBackToApply($fifth, '予算と終了日を再検討してください。');
        $end = SeedingDates::day('2027-04-30');
        $reapplied = new ApplicationInput(null, "予算を 350 万円に、終了日を {$end} に変更しました。", $this->planInput(5, ['budget' => 350, 'end_date' => $end]));
        $this->reapply->execute($this->contextOf('u0001'), $fifth, $this->revisionOf($fifth), $reapplied);

        $sixth = $this->applyIssue('u0010', '経費の入力画面が分かりにくい', $this->projectIdOf('経費精算の電子化'), 'u0010', 'low', null);
        $this->reject->execute($this->contextOf('u0002'), $sixth, $this->revisionOf($sixth), $this->currentNodeIdOf($sixth), '運用マニュアルで対応します。');

        $seventh = $this->applyIssue('u0009', '人事の申請の入口が分かれている', $this->projectIdOf('社内ポータル刷新'), 'u0011', 'high', '2026-11-30');
        $this->approveEarly->execute($this->contextOf('u0002'), $seventh, $this->revisionOf($seventh), $this->currentNodeIdOf($seventh), '急ぎのため、課長の承認で終了します。');

        $eighth = $this->applyPlan('u0001', 8);
        $this->sendBackToApply($eighth, '開催の目的を追加してください。');
        $this->withdraw->execute($this->contextOf('u0001'), $eighth, $this->revisionOf($eighth), '今期は見送ります。');

        $this->createDraft->execute($this->contextOf('u0001'), 'issue_resolution', new DraftInput('課題の対応の承認 請求書の様式の変更', null, [
            'title' => '請求書の様式の変更', 'project' => $this->projectIdOf('経費精算の電子化'), 'priority' => 'medium', 'status' => 'open',
        ], []));
    }

    /**
     * 計画の案件を申請する(案件名は空 = 既定)。申請基準日の既定 = 2 段目の見本の日(版 2)を流す日からの相対にした日。
     */
    private function applyPlan(string $applicant, int $plan, ?string $comment = null, ?string $baseDate = null): int
    {
        $baseDate ??= SeedingDates::day(self::DATES['stage2_plan_base_date']);

        return $this->apply->execute($this->contextOf($applicant), 'project_plan', new ApplicationInput(null, $comment, $this->planInput($plan)), $baseDate)->caseId;
    }

    /**
     * 課題の案件を申請する(案件名は空 = 既定。状態は未着手。期限は基準の日の値で受ける)。
     */
    private function applyIssue(string $applicant, string $title, int $projectId, string $assignee, string $priority, ?string $dueDate): int
    {
        return $this->apply->execute($this->contextOf($applicant), 'issue_resolution', new ApplicationInput(null, null, new RecordInput([
            'title' => $title, 'project' => $projectId, 'assignee' => $this->userId($assignee), 'priority' => $priority, 'status' => 'open',
            'due_date' => SeedingDates::day($dueDate),
        ])), $this->today()->toDateString())->caseId;
    }

    /**
     * u0002(課長の承認の処理対象者)が申請のノードへ差戻す。
     */
    private function sendBackToApply(int $caseId, string $comment): void
    {
        $applyNode = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', 'apply')->sole();
        $this->sendBack->execute($this->contextOf('u0002'), $caseId, $this->revisionOf($caseId), $this->currentNodeIdOf($caseId), $applyNode->id, $comment);
    }

    /**
     * 操作する人の判定の入力(接続元・選んだ組織なし、基準日 = 流す日の Asia/Tokyo の日付、ロケール ja)。
     */
    private function contextOf(string $userCode): AccessContext
    {
        return new AccessContext($this->userId($userCode), null, null, $this->today(), AccessContext::LOCALE_CODE);
    }

    /**
     * 流す日(Asia/Tokyo の日付。操作の基準日と申請基準日)。
     */
    private function today(): CarbonImmutable
    {
        return SeedingDates::seedingDay();
    }

    /**
     * 処理の操作の node_id(2 段目の見本の案件は一本道で、現在のノードはちょうど 1 つ)。
     */
    private function currentNodeIdOf(int $caseId): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('state', WorkflowNodeState::current())->sole()->id;
    }

    private function revisionOf(int $caseId): int
    {
        return WorkflowCase::query()->findOrFail($caseId)->revision;
    }

    private function recordIdOf(int $caseId): int
    {
        return WorkflowCase::query()->findOrFail($caseId)->record_id ?? throw new LogicException("案件 {$caseId} がレコードを指していない");
    }

    /**
     * 対象者の条件 1 つ(相手はコードで引く)。
     */
    private function condition(SelectionConditionKind $kind, string $code): SelectionCondition
    {
        return new SelectionCondition($kind, match ($kind) {
            SelectionConditionKind::User => $this->userId($code),
            SelectionConditionKind::Department => $this->departmentId($code),
            SelectionConditionKind::CompanyPost => $this->companyPostId($code),
            SelectionConditionKind::Role => Role::query()->where('code', $code)->sole()->id,
        });
    }

    private function companyPostId(string $code): int
    {
        return CompanyPost::query()->where('code', $code)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
