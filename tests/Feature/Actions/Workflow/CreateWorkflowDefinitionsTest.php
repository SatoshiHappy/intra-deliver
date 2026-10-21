<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\CreateWorkflowContent;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 定義を作る業務処理(docs の 設計/ワークフロー/データモデル/表.md 2.1〜2.4 の「業務処理で止める規則」、入力の形 = 初期データ.md 2〜3 節(値は WorkflowSeeder)。段 3a で
 * ルートの表が消え、フローを作る業務処理が版 1 と一本道のルートを作る = ルートの深さ/データモデル/定義の表.md 2.1・2.5・2.6):
 * 正しい入力でコンテンツ・フロー・版・ルートのノード・辺・差戻しできる先と処理対象者・参照者の専用の対象者(用途 selection)ができる、ルートを既存のフローの
 * コードで渡すと同じノードで同じ対象者を指す、各 422 のキー、無い業務モジュール・コンテンツ・ルートの写し元のフローは 404、失敗で何も残らない、同じ入力の
 * 2 回目はコードの重複。定義は 設計/ワークフロー/初期データ.md 3 節と同じ中身を、初期データ(WorkflowSeeder)と重ならないコードで作り、自分が作った行だけを見る
 * (件数は前後の差で比べる)。
 */
final class CreateWorkflowDefinitionsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    /** 計画のコンテンツとフローのコード(初期データの project_plan に当たる) */
    private const PLAN = 'test_plan';

    /** 課題のコンテンツとフローのコード(初期データの issue_resolution に当たる) */
    private const ISSUE = 'test_issue';

    /** 版 1 の開始日 */
    private const START_DATE = '2026-04-01';

    /**
     * 設計/ワークフロー/初期データ.md 3 節と同じ中身の定義(コンテンツ 2・フロー 2(版 1 とノード 3。2 つ目のフローのルートは 1 つ目と同じ))を作り、行と対象者を確かめる。
     */
    public function test_creates_the_definitions_of_the_seed_example(): void
    {
        $this->createSeedExample();

        $this->assertSame(
            [[self::ISSUE, '課題の対応', 'project_issue'], [self::PLAN, 'プロジェクト計画', 'project']],
            WorkflowContent::query()->whereIn('code', [self::PLAN, self::ISSUE])->orderBy('code')->get()->map(fn (WorkflowContent $content) => [$content->code, $content->name, RecordModule::query()->findOrFail($content->record_module_id)->code])->all(),
        );
        $flows = WorkflowFlow::query()->whereIn('code', [self::PLAN, self::ISSUE])->orderBy('sort_key')->get();
        $this->assertSame(
            [[self::PLAN, 'プロジェクト計画の承認', 10], [self::ISSUE, '課題の対応の承認', 20]],
            $flows->map(fn (WorkflowFlow $flow) => [$flow->code, $flow->name, $flow->sort_key])->all(),
        );
        $plan = WorkflowFlow::query()->where('code', self::PLAN)->sole();
        $issue = WorkflowFlow::query()->where('code', self::ISSUE)->sole();
        $this->assertSame('プロジェクトの担当・期間・予算とマイルストーンの計画を、上長と部長の承認で決めます。', $plan->description);
        $this->assertSame(['department_id', $this->departmentId('accounting')], $this->selectionCondition((int) $plan->authz_subject_group_id));
        $this->assertSame(['company_post_id', CompanyPost::query()->where('code', 'manager')->sole()->id], $this->selectionCondition((int) $issue->authz_subject_group_id));

        $planNodes = $this->nodesOf($plan);
        $this->assertSame([[1, 'apply', '申請', 'apply'], [2, 'section_chief', '課長の承認', 'approve'], [3, 'manager', '部長の承認', 'approve']], $planNodes->map(fn (WorkflowRouteNode $node) => [$node->position, $node->code, $node->name, $node->kind->value])->all());
        $this->assertSame(
            [['role_id', $this->roleId('general_user')], ['user_id', $this->userId('u0002')], ['user_id', $this->userId('u0003')]],
            $planNodes->map(fn (WorkflowRouteNode $node) => $this->selectionCondition((int) $node->authz_subject_group_id))->all(),
        );
        $this->assertSame(
            $planNodes->map(fn (WorkflowRouteNode $node) => [$node->code, $node->authz_subject_group_id])->all(),
            $this->nodesOf($issue)->map(fn (WorkflowRouteNode $node) => [$node->code, $node->authz_subject_group_id])->all(),
        );
    }

    /**
     * 版 1 は開始日から上限なしで有効。辺は位置の順の一本道、差戻しできる先は承認のノードごとに前の全部(段 2 の振る舞い = 設計/ワークフロー/ルートの深さ/初期データ.md 3.1 の一本道 A)。
     */
    public function test_the_flow_has_the_first_version_with_a_linear_route(): void
    {
        $this->createSeedExample();
        $plan = WorkflowFlow::query()->where('code', self::PLAN)->sole();
        $version = WorkflowFlowVersion::query()->where('workflow_flow_id', $plan->id)->sole();
        $codes = $this->nodesOf($plan)->pluck('code', 'id');

        $this->assertSame([1, self::START_DATE, null, false], [$version->number, $version->start_date->toDateString(), $version->end_date, $version->is_deleted]);
        $this->assertSame(
            [['apply', 'section_chief'], ['section_chief', 'manager']],
            WorkflowRouteEdge::query()->where('workflow_flow_version_id', $version->id)->orderBy('id')->get()->map(fn (WorkflowRouteEdge $edge) => [$codes[$edge->from_workflow_route_node_id], $codes[$edge->to_workflow_route_node_id]])->all(),
        );
        $this->assertSame(
            [['section_chief', 'apply'], ['manager', 'apply'], ['manager', 'section_chief']],
            WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $version->id)->orderBy('id')->get()->map(fn (WorkflowRouteSendBack $sendBack) => [$codes[$sendBack->workflow_route_node_id], $codes[$sendBack->target_workflow_route_node_id]])->all(),
        );
    }

    /**
     * 説明は前後の空白を除いて空なら NULL、参照者の条件が null なら参照者なし。
     */
    public function test_a_flow_without_referrers_and_with_a_blank_description(): void
    {
        $this->createSeedExample();
        $groups = AuthzSubjectGroup::query()->count();

        $flowId = $this->flow(code: 'plain', description: '   ', route: self::PLAN, referrers: null);

        $this->assertSame([null, null], [WorkflowFlow::query()->findOrFail($flowId)->description, WorkflowFlow::query()->findOrFail($flowId)->authz_subject_group_id]);
        $this->assertSame($groups, AuthzSubjectGroup::query()->count());
    }

    /**
     * @return array<string, array{Closure(self): mixed, string}>
     */
    public static function rejectedInputs(): array
    {
        $long = str_repeat('あ', 1001);

        return [
            'コンテンツのコードの書式' => [fn (self $test) => $test->content(code: 'Project'), 'code'],
            'コンテンツのコードの重複' => [fn (self $test) => $test->content(code: self::PLAN), 'code'],
            'コンテンツの名前が空' => [fn (self $test) => $test->content(name: '  '), 'name'],
            'コンテンツの名前が長い' => [fn (self $test) => $test->content(name: $long), 'name'],
            'フローの名前の U+0000' => [fn (self $test) => $test->flow(code: 'other_flow', name: "名\0前"), 'name'],
            'ノードが空' => [fn (self $test) => $test->flow(code: 'other_flow', route: []), 'nodes'],
            '先頭が申請でない' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Approve), $test->node('b', WorkflowNodeKind::Approve)]), 'nodes'],
            '承認が無い' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply)]), 'nodes'],
            '申請と承認でない種類' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::BranchStart), $test->node('c', WorkflowNodeKind::Approve)]), 'nodes.1.kind'],
            '2 番目の申請' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Apply), $test->node('c', WorkflowNodeKind::Approve)]), 'nodes.1.kind'],
            'ノードのコードの書式' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('1st', WorkflowNodeKind::Approve)]), 'nodes.1.code'],
            'ノードのコードの重複' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Approve), $test->node('b', WorkflowNodeKind::Approve)]), 'nodes.2.code'],
            'ノードの名前が空' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply, name: ''), $test->node('b', WorkflowNodeKind::Approve)]), 'nodes.0.name'],
            '処理対象者の条件が無い' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Approve), $test->node('c', WorkflowNodeKind::Approve, conditions: [])]), 'conditions'],
            '処理対象者の相手が無い' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Approve, conditions: [new SelectionCondition(SelectionConditionKind::User, 999_999_999)])]), 'conditions.0.id'],
            '処理対象者の表示名が長い' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Approve, translations: ['ja' => $long])]), 'nodes.1.target_translations.ja'],
            '処理対象者の表示名が無い' => [fn (self $test) => $test->flow(code: 'other_flow', route: [$test->node('a', WorkflowNodeKind::Apply), $test->node('b', WorkflowNodeKind::Approve, translations: [])]), 'translations'],
            'フローのコードの重複' => [fn (self $test) => $test->flow(code: self::PLAN), 'code'],
            'フローの名前が長い' => [fn (self $test) => $test->flow(code: 'other_flow', name: $long), 'name'],
            'フローの説明が長い' => [fn (self $test) => $test->flow(code: 'other_flow', description: str_repeat('あ', 4001)), 'description'],
            '並び順の範囲' => [fn (self $test) => $test->flow(code: 'other_flow', sortKey: 1_000_000_000_000_000), 'sort_key'],
            '参照者の条件が無い' => [fn (self $test) => $test->flow(code: 'other_flow', referrers: []), 'conditions'],
            '参照者の表示名が長い' => [fn (self $test) => $test->flow(code: 'other_flow', referrerTranslations: ['ja' => '参照者', 'en' => $long]), 'referrer_translations.en'],
            '参照者の相手が無い' => [fn (self $test) => $test->flow(code: 'other_flow', referrers: [new SelectionCondition(SelectionConditionKind::Department, 999_999_999)]), 'conditions.0.id'],
        ];
    }

    /**
     * 誤りのキー(対象者の口の誤りは下位のキーのまま)で拒み、定義の行も対象者の行も増えない(先に作ったノードの対象者も残らない)。
     *
     * @param  Closure(self): mixed  $action
     */
    #[DataProvider('rejectedInputs')]
    public function test_rejects_an_invalid_input_with_its_key_and_leaves_nothing(Closure $action, string $key): void
    {
        $this->createSeedExample();
        $counts = $this->counts();

        $errors = $this->validationErrors(fn () => $action($this));

        $this->assertSame([$key], array_keys($errors));
        $this->assertSame($counts, $this->counts());
    }

    /**
     * 処理対象者・参照者の表示名は 1000 文字まで通る(表示名の列 = varchar(1000)。1001 文字は rejectedInputs の 422)。
     */
    public function test_the_display_names_of_targets_and_referrers_accept_1000_characters(): void
    {
        $this->createSeedExample();
        $displayName = str_repeat('あ', 1000);

        $flowId = $this->flow(code: 'long_flow', route: [$this->node('a', WorkflowNodeKind::Apply, translations: ['ja' => $displayName]), $this->node('b', WorkflowNodeKind::Approve)], referrerTranslations: ['ja' => $displayName]);

        $applyNode = $this->nodesOf(WorkflowFlow::query()->findOrFail($flowId))->firstOrFail();
        $this->assertSame([$displayName, $displayName], [$this->displayName((int) $applyNode->authz_subject_group_id), $this->displayName((int) WorkflowFlow::query()->findOrFail($flowId)->authz_subject_group_id)]);
    }

    /**
     * 明細のモジュール(project_milestone)は無いものと同じ。ルートの写し元は既存のフローのコード。
     */
    public function test_a_missing_module_content_or_route_is_not_found(): void
    {
        $this->createSeedExample();

        $this->assertThrows(fn () => $this->content(moduleCode: 'no_such_module'), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->content(moduleCode: 'project_milestone'), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->flow(contentCode: 'no_such_content'), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->flow(code: 'other_flow', route: 'no_such_flow'), ModelNotFoundException::class);
    }

    /**
     * 同じ入力の 2 回目は、どちらもコードの重複(422 code)。
     */
    public function test_the_same_input_twice_is_a_duplicate_code(): void
    {
        $this->createSeedExample();

        foreach ([fn () => $this->content(code: self::PLAN), fn () => $this->flow(code: self::PLAN)] as $again) {
            $this->assertSame(['code' => 'このコードは既に使用されています'], $this->validationErrors($again));
        }
    }

    /**
     * 設計/ワークフロー/初期データ.md 3 節と同じ中身の定義を、初期データと重ならないコードで作る。
     */
    private function createSeedExample(): void
    {
        $this->content('project', self::PLAN, 'プロジェクト計画');
        $this->content('project_issue', self::ISSUE, '課題の対応');
        $this->flow();
        $this->flow(self::ISSUE, '課題の対応の承認', 'プロジェクトで起きた課題の対応方針を、上長と部長の承認で決めます。', self::ISSUE, route: self::PLAN, referrers: [new SelectionCondition(SelectionConditionKind::CompanyPost, CompanyPost::query()->where('code', 'manager')->sole()->id)], sortKey: 20);
    }

    private function content(string $moduleCode = 'project', string $code = 'other_content', string $name = '別のコンテンツ'): int
    {
        return app(CreateWorkflowContent::class)->execute($moduleCode, $code, $name);
    }

    /** @return list<WorkflowRouteNodeInput> 初期データと同じ中身のノード 3 つ */
    private function seedNodes(): array
    {
        return [
            new WorkflowRouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, [new SelectionCondition(SelectionConditionKind::Role, $this->roleId('general_user'))], ['ja' => '申請(課長・部長の承認)', 'en' => 'Apply (Manager approval)']),
            new WorkflowRouteNodeInput('section_chief', '課長の承認', WorkflowNodeKind::Approve, [new SelectionCondition(SelectionConditionKind::User, $this->userId('u0002'))], ['ja' => '課長の承認(課長・部長の承認)', 'en' => 'Section chief approval (Manager approval)']),
            new WorkflowRouteNodeInput('manager', '部長の承認', WorkflowNodeKind::Approve, [new SelectionCondition(SelectionConditionKind::User, $this->userId('u0003'))], ['ja' => '部長の承認(課長・部長の承認)', 'en' => 'Manager approval (Manager approval)']),
        ];
    }

    /** @return Collection<int, WorkflowRouteNode> フローの版 1 のノード(位置の順) */
    private function nodesOf(WorkflowFlow $flow): Collection
    {
        $version = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->where('number', 1)->sole();

        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $version->id)->orderBy('position')->get();
    }

    /**
     * 処理対象者 = u0001 の条件 1 つのノード(条件・表示名を差し替えられる)。
     *
     * @param  list<SelectionCondition>|null  $conditions
     * @param  array<string, string>  $translations
     */
    private function node(string $code, WorkflowNodeKind $kind, string $name = 'ノード', ?array $conditions = null, array $translations = ['ja' => '処理対象者']): WorkflowRouteNodeInput
    {
        return new WorkflowRouteNodeInput($code, $name, $kind, $conditions ?? [new SelectionCondition(SelectionConditionKind::User, $this->userId('u0001'))], $translations);
    }

    /**
     * 既定 = 初期データのフロー「プロジェクト計画の承認」と同じ中身(ルート = 初期データと同じノード 3 つ、参照者 = 経理部。コードは self::PLAN)。
     *
     * @param  list<WorkflowRouteNodeInput>|string|null  $route  ノードの並びか、写し元のフローのコード(null = 初期データと同じノード 3 つ)
     * @param  list<SelectionCondition>|null|false  $referrers  false = 既定(経理部)
     * @param  array<string, string>  $referrerTranslations
     */
    private function flow(
        string $code = self::PLAN,
        string $name = 'プロジェクト計画の承認',
        ?string $description = 'プロジェクトの担当・期間・予算とマイルストーンの計画を、上長と部長の承認で決めます。',
        string $contentCode = self::PLAN,
        array|string|null $route = null,
        array|null|false $referrers = false,
        int $sortKey = 10,
        array $referrerTranslations = ['ja' => '参照者', 'en' => 'Referrers'],
    ): int {
        $referrers = $referrers === false ? [new SelectionCondition(SelectionConditionKind::Department, $this->departmentId('accounting'))] : $referrers;

        return app(CreateWorkflowFlow::class)->execute($code, $name, $description, $contentCode, $route ?? $this->seedNodes(), CarbonImmutable::parse(self::START_DATE), $referrers, $referrerTranslations, $sortKey);
    }

    /**
     * 用途 selection の対象者の、ただ 1 つの条件(列の名前と相手の id)。
     *
     * @return array{string, int}
     */
    private function selectionCondition(int $subjectGroupId): array
    {
        $this->assertSame(SubjectGroupPurpose::Selection, AuthzSubjectGroup::query()->findOrFail($subjectGroupId)->purpose);
        $condition = AuthzSubjectCondition::query()->where('authz_subject_group_id', $subjectGroupId)->sole();

        foreach (['user_id', 'department_id', 'company_post_id', 'role_id'] as $column) {
            if ($condition->{$column} !== null) {
                return [$column, (int) $condition->{$column}];
            }
        }

        $this->fail('条件の相手が無い');
    }

    private function displayName(int $subjectGroupId): string
    {
        return AuthzSubjectGroupTranslation::query()->where('authz_subject_group_id', $subjectGroupId)->whereRelation('locale', 'code', 'ja')->sole()->display_name;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'contents' => WorkflowContent::query()->count(),
            'versions' => WorkflowFlowVersion::query()->count(),
            'nodes' => WorkflowRouteNode::query()->count(),
            'edges' => WorkflowRouteEdge::query()->count(),
            'send_backs' => WorkflowRouteSendBack::query()->count(),
            'flows' => WorkflowFlow::query()->count(),
            'subject_groups' => AuthzSubjectGroup::query()->count(),
            'conditions' => AuthzSubjectCondition::query()->count(),
        ];
    }

    private function roleId(string $code): int
    {
        return Role::query()->where('code', $code)->sole()->id;
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
