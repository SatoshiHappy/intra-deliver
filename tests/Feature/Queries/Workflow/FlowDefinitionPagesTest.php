<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\NodeSettingsInput;
use App\Actions\Workflow\SaveNodeSettings;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\FlowDefinitionDetail;
use App\Queries\Workflow\FlowDefinitionList;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * フロー定義の一覧と詳細の props(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 2 節・3 節、
 * クエリの既定 = 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、今日の版 = 設計/ワークフロー/ルートの深さ/定義の管理.md 2 節)。
 * 初期データのプロジェクト計画の承認(版 1 = ユーザー無効、版 2、版 3 = 分岐 B)と課題の対応の承認(版 1)。
 * 期待値は 設計/ワークフロー/ルートの深さ/初期データ.md の 2 節・3 節の値と画面の文から書いた(日付は流す日からの相対なので版の行から読む)。
 */
final class FlowDefinitionPagesTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    public function test_the_list_shows_every_flow_with_its_current_version_and_referrers(): void
    {
        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');

        $this->assertSame(['project_plan', 'issue_resolution'], array_column($flows, 'code'));
        $this->assertSame(['プロジェクト計画の承認', '課題の対応の承認'], array_column($flows, 'name'));
        $this->assertSame([3, 1], array_column($flows, 'versionCount'));
        $this->assertSame(['経理部', '部長'], array_column($flows, 'referrerSummary'));
        $this->assertSame(
            ['number' => 3, 'startDate' => $this->startOf(3), 'endDate' => null],
            $flows[0]['currentVersion'],
        );
        $this->assertSame(1, $flows[1]['currentVersion']['number'] ?? null);
    }

    /**
     * 業務モジュールの列と詳細の見出しは、申請対象の業務モジュール(コンテンツの target_record_module_id)の名前。申請対象なしは null(画面は「—」)。
     */
    public function test_the_module_name_is_the_application_target_module(): void
    {
        $names = fn () => array_column(app(FlowDefinitionList::class)->flows($this->today(), 'ja'), 'moduleName');
        $expected = RecordModule::query()->whereIn('code', ['project', 'project_issue'])->pluck('name', 'code');

        $this->assertSame([$expected['project'], $expected['project_issue']], $names());
        $this->assertSame($expected['project'], $this->detail([])['flow']['moduleName']);

        FlowDefinitionList::contentOf(WorkflowFlow::query()->where('code', self::PLAN)->sole())
            ->update(['target_record_field_id' => null, 'target_record_module_id' => null]);

        $this->assertSame([null, $expected['project_issue']], $names());
        $this->assertNull($this->detail([])['flow']['moduleName']);
    }

    /**
     * 版の並び = 番号の降順、表示の終了日 = 次の版の開始日の前日(最後は null)、今日の版だけが isCurrent。参照者の行は対象者の名前。
     */
    public function test_the_detail_lists_the_versions_and_the_referrers(): void
    {
        $detail = $this->detail([]);
        $versions = $detail['versions'];

        $this->assertSame([3, 2, 1], array_column($versions, 'number'));
        $this->assertSame([null, $this->startOf(3), $this->startOf(2)], array_column($versions, 'endDate'));
        $dayBefore = fn (int $number) => CarbonImmutable::parse($this->startOf($number))->subDay()->toDateString();
        $this->assertSame([null, $dayBefore(3), $dayBefore(2)], array_column($versions, 'displayEndDate'));
        $this->assertSame([false, false, true], array_column($versions, 'isDisabled'));
        $this->assertSame([true, false, false], array_column($versions, 'isCurrent'));
        $accounting = Department::query()->where('code', 'accounting')->sole()->id;
        $rows = [['type' => 'department', 'id' => $accounting, 'name' => '経理部', 'affiliation' => null]];
        $this->assertSame(['rows' => $rows], $detail['referrers']);
        $this->assertSame(['code' => self::PLAN, 'name' => 'プロジェクト計画の承認'], array_slice($detail['flow'], 0, 2));
        // 版を追加の開始日の範囲に使う、サーバーの今日(Y-m-d)
        $this->assertSame($this->today()->toDateString(), $detail['today']);
    }

    /**
     * クエリの読み方: version = 無い・数でない・そのフローに無い番号は今日の版、有る番号はその版。
     * tab = 3 つ(diagram・versions・settings)、ほかは diagram(前の referrers も diagram)。
     * node = 選んだ版に有るコード、無い・別の版にだけ有るコードは申請のノード(版を替えても同じコードがあれば選んだまま)。
     */
    public function test_the_query_is_read_into_the_selected_version_tab_and_node(): void
    {
        $selected = fn (array $query) => [
            ($detail = $this->detail($query))['selectedVersion'], $detail['tab'], $detail['selectedNode']['code'],
        ];

        $this->assertSame([3, 'diagram', 'apply'], $selected([]));
        $this->assertSame([3, 'diagram', 'apply'], $selected(['version' => 'abc', 'tab' => 'other']));
        $this->assertSame([3, 'versions', 'apply'], $selected(['version' => '9', 'tab' => 'versions']));
        $this->assertSame([2, 'settings', 'apply'], $selected(['version' => '2', 'tab' => 'settings']));
        $this->assertSame([3, 'diagram', 'apply'], $selected(['tab' => 'referrers']));
        $this->assertSame([3, 'diagram', 'manager'], $selected(['node' => 'manager']));
        $this->assertSame([2, 'diagram', 'manager'], $selected(['version' => '2', 'node' => 'manager']));
        $this->assertSame([2, 'diagram', 'apply'], $selected(['version' => '2', 'node' => 'budget']));
        $this->assertSame([3, 'diagram', 'apply'], $selected(['node' => 'nothing']));
    }

    /**
     * 今日がユーザー無効の版の期間なら今日の版は無い: 一覧の現在の版は null、詳細の既定は最後の版。
     */
    public function test_without_a_current_version_the_default_is_the_last_version(): void
    {
        $this->travelTo(CarbonImmutable::parse($this->startOf(1), AccessContext::TIMEZONE)->setTime(12, 0));

        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');
        $detail = $this->detail([]);

        $this->assertNull($flows[0]['currentVersion']);
        $this->assertSame(3, $detail['selectedVersion']);
        $this->assertSame([false, false, false], array_column($detail['versions'], 'isCurrent'));
    }

    /**
     * 図で選んだステップの設定(定義の管理の画面.md 3 節の StepSettings)と、選択の欄の指定するステップの候補(定義の検査と同じ式)。
     * 処理期限と確認する人(案件の運用/定義の管理.md 1 節、初期データ = 案件の運用/初期データ.md 3 節: 版 3 の課長・部長は確認する人
     * 山田 恵(u0010)、承認・合議は期限の日数)は持てる種類だけ値を持ち、要約に文が続く(7.6 節)。
     */
    public function test_the_selected_step_and_the_options(): void
    {
        $chief = $this->detail(['node' => 'section_chief'])['selectedNode'];
        $council = $this->detail(['node' => 'council']);
        $post = CompanyPost::query()->where('code', 'section-chief')->sole()->id;
        $choice = fn (string $code, string $name) => ['code' => $code, 'name' => $name];
        // ユーザーの行は今日の主所属の組織の名前を持つ(定義の管理の画面.md 3 節の SubjectRow)
        $yamadaId = $this->seededAccount('u0010')->id;
        $yamada = ['type' => 'user', 'id' => $yamadaId, 'name' => '山田 恵', 'affiliation' => '経理部'];

        $this->assertSame([
            'code' => 'section_chief', 'name' => '課長', 'kind' => 'approve',
            'summary' => ['申請者の所属組織の課長が承認します。到達した日から 3 日以内に処理します。山田 恵が確認します。'],
            'target' => ['kind' => 'relative', 'base' => 'applicant', 'scope' => 'own', 'companyPostId' => $post],
            'preventsApplicantApproval' => true, 'preventsAttachmentAdd' => false,
            'sendBack' => ['candidates' => [$choice('apply', '申請')], 'selected' => ['apply']],
            'decision' => null, 'deadline' => ['days' => 3], 'confirmers' => [$yamada],
        ], $chief);
        $this->assertSame([
            'code' => 'council', 'name' => '関係部署の合議', 'kind' => 'parallel',
            'summary' => ['課長が選択した 2 人から 5 人のうち、2 人が承認すると次へ進みます。到達した日から 3 日以内に処理します。'],
            'target' => ['kind' => 'designated', 'designatorCode' => 'section_chief', 'min' => 2, 'max' => 5],
            'preventsApplicantApproval' => true, 'preventsAttachmentAdd' => false,
            'sendBack' => [
                'candidates' => [$choice('apply', '申請'), $choice('section_chief', '課長'), $choice('manager', '部長')],
                'selected' => ['apply', 'section_chief'],
            ],
            'decision' => ['rule' => 'count', 'value' => 2], 'deadline' => ['days' => 3], 'confirmers' => null,
        ], $council['selectedNode']);
        $this->assertSame([$choice('section_chief', '課長')], $council['options']['designators']);
    }

    /**
     * 申請・分岐のステップは持たない設定が null(申請の確認する人は持てるので空の並び)で、選択の欄の候補の役職は今日に有効な役職。
     */
    public function test_the_steps_without_the_settings_and_the_post_options(): void
    {
        $apply = $this->detail([]);
        $budget = $this->detail(['node' => 'budget'])['selectedNode'];
        $role = Role::query()->where('code', 'general_user')->sole()->id;

        $this->assertSame(
            ['kind' => 'fixed', 'rows' => [
                ['type' => 'role', 'id' => $role, 'name' => '一般利用者', 'affiliation' => null],
            ]],
            $apply['selectedNode']['target'],
        );
        $applyNode = $apply['selectedNode'];
        $this->assertSame([['一般利用者が申請できます。確認する人はいません。'], null, null, null, null, []], [
            $applyNode['summary'], $applyNode['preventsApplicantApproval'], $applyNode['preventsAttachmentAdd'],
            $applyNode['sendBack'], $applyNode['deadline'], $applyNode['confirmers'],
        ]);
        $this->assertSame([], $apply['options']['designators']);
        $this->assertSame([['分岐「予算」の条件に該当する先へ、すべて進みます。'], null, null, null, null, null, null, null], [
            $budget['summary'], $budget['target'], $budget['preventsApplicantApproval'],
            $budget['preventsAttachmentAdd'], $budget['sendBack'], $budget['decision'], $budget['deadline'],
            $budget['confirmers'],
        ]);
        $president = CompanyPost::query()->where('code', 'president')->sole()->id;
        $this->assertSame(['id' => $president, 'name' => '社長'], $apply['options']['companyPosts'][0]);
        $this->assertCount(7, $apply['options']['companyPosts']);
    }

    /**
     * 「追加できない」の部長(初期データの版 3)は要約の 1 文に「添付ファイルを追加できません。」が続き、追加できる課長は続かない。
     * フローの設定(添付ファイルを使用するか)はタブ「設定」の面の値で、既定は使用する。
     */
    public function test_the_attachment_settings_of_the_step_and_the_flow(): void
    {
        $manager = $this->detail(['node' => 'manager'])['selectedNode'];

        $this->assertSame(
            [['申請者の上位組織の部長が承認します。到達した日から 5 日以内に処理します。山田 恵が確認します。添付ファイルを追加できません。'], true],
            [$manager['summary'], $manager['preventsAttachmentAdd']],
        );
        $this->assertSame(['isAttachmentEnabled' => true], $this->detail([])['flowSettings']);
        WorkflowFlow::query()->where('code', self::PLAN)->update(['is_attachment_enabled' => false]);
        $this->assertSame(['isAttachmentEnabled' => false], $this->detail([])['flowSettings']);
    }

    /**
     * 対象者で指定の組織の行は、要約の並びで組織の id つきの区切りになる(画面が名前の横に「組織図で見る」を付ける)。ほかの種類の行は文字列のまま。
     */
    public function test_the_summary_marks_the_department_of_a_fixed_target(): void
    {
        $accounting = Department::query()->where('code', 'accounting')->sole();
        $role = Role::query()->where('code', 'general_user')->sole();
        $targets = [
            new SelectionCondition(SelectionConditionKind::Department, $accounting->id),
            new SelectionCondition(SelectionConditionKind::Role, $role->id),
        ];
        $input = new NodeSettingsInput(
            WorkflowTargetKind::Fixed, $targets, sendBackNodeCodes: ['apply'], preventsAttachmentAdd: true,
        );
        app(SaveNodeSettings::class)->execute(self::PLAN, 3, 'manager', $input);

        $this->assertSame(
            [
                ['departmentId' => $accounting->id, 'text' => '経理部'],
                '、一般利用者が承認します。期限はありません。確認する人はいません。添付ファイルを追加できません。',
            ],
            $this->detail(['node' => 'manager'])['selectedNode']['summary'],
        );
    }

    /**
     * 詳細の props(遅延の props は評価した値)。
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function detail(array $query): array
    {
        $props = app(FlowDefinitionDetail::class)->show(
            self::PLAN, $query['version'] ?? null, $query['tab'] ?? null, $query['node'] ?? null, $this->today(), 'ja',
        );

        return array_map(fn (mixed $value) => $value instanceof Closure ? $value() : $value, $props);
    }

    private function startOf(int $number): string
    {
        return $this->planVersion($number)->start_date->toDateString();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }
}
