<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowProxy;
use App\Models\Workflow\WorkflowRouteNode;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の運用の初期データ(docs の 設計/ワークフロー/案件の運用/初期データ.md 6 節の検査。値 = 同じ文書の 3 節・4 節): 期限の日数と確認する人、代理 4、
 * 代理の登録の通知、保留・申請の代理・担当変更の見本、u0010 の確認、足した見本の通知。
 * 案件の一覧の 17 件は {@see WorkflowSeederTest}、時刻は {@see WorkflowSeederSampleTimesTest}。
 * 初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederOperationSamplesTest extends TransactionalTestCase
{
    /**
     * 3 節: 計画の版 2・版 3 の承認のノードに期限の日数(課長 3・部長 5・合議 3・責任者 3)と、課長・部長に確認する人。計画の版 1 と課題の版 1 は持たない。
     * 確認する人は 1 つの対象者(条件 = ユーザー u0010、表示名はすべてのロケールで「プロジェクト計画の承認 / 確認する人」)を 4 つのノードが共有する。
     */
    public function test_the_versions_have_the_deadline_days_and_the_confirmer_of_the_design(): void
    {
        $settings = fn (WorkflowFlowVersion $version) => WorkflowRouteNode::query()
            ->where('workflow_flow_version_id', $version->id)->orderBy('position')->get()
            ->map(fn (WorkflowRouteNode $n) => [$n->code, $n->deadline_days, isset($n->confirm_authz_subject_group_id)])
            ->all();
        $versions = WorkflowFlowVersion::query()->with('workflowFlow')->orderBy('id')->get()
            ->mapWithKeys(fn (WorkflowFlowVersion $v) => ["{$v->workflowFlow?->code} {$v->number}" => $settings($v)])
            ->all();
        $none = fn (string ...$codes) => array_map(fn (string $code) => [$code, null, false], $codes);

        $this->assertSame([
            'project_plan 1' => $none('apply', 'section_chief', 'manager'),
            'issue_resolution 1' => $none('apply', 'section_chief', 'manager'),
            'project_plan 2' => [['apply', null, false], ['section_chief', 3, true], ['manager', 5, true]],
            'project_plan 3' => [
                ['apply', null, false], ['section_chief', 3, true], ['budget', null, false], ['manager', 5, true],
                ['budget_end', null, false], ['council', 3, false], ['owner', 3, false],
            ],
        ], $versions);

        $confirmers = WorkflowRouteNode::query()->whereNotNull('confirm_authz_subject_group_id')->distinct()
            ->pluck('confirm_authz_subject_group_id')->all();
        $this->assertCount(1, $confirmers);
        $group = AuthzSubjectGroup::query()->with('authzSubjectGroupTranslations')->findOrFail((int) $confirmers[0]);
        $condition = AuthzSubjectCondition::query()->with('user')->where('authz_subject_group_id', $group->id)->sole();
        $this->assertSame(['selection', 'u0010'], [$group->purpose->value, $condition->user?->code]);
        $this->assertSame(
            ['プロジェクト計画の承認 / 確認する人'],
            $group->authzSubjectGroupTranslations->map(fn (AuthzSubjectGroupTranslation $row) => $row->display_name)
                ->unique()->values()->all(),
        );
    }

    /**
     * 4.1: 代理 4 行(登録の順)の代理元・代理先・別・対象のフロー・開始日・最後の有効な日(行の終了日の前日)・登録した人。
     */
    public function test_seeds_the_four_proxies_of_the_design(): void
    {
        $proxies = WorkflowProxy::query()->with(['principalUser', 'proxyUser', 'workflowFlow', 'registeredByUser'])
            ->orderBy('id')->get()
            ->map(fn (WorkflowProxy $proxy) => [
                $proxy->principalUser?->code, $proxy->proxyUser?->code, $proxy->kind->value,
                $proxy->workflowFlow?->code, $proxy->start_date->toDateString(),
                $proxy->end_date?->subDay()->toDateString(), $proxy->registeredByUser?->code,
            ])->all();

        $this->assertSame([
            ['u0010', 'u0002', 'approve', 'issue_resolution', '2026-08-30', '2026-09-28', 'u0010'],
            ['u0010', 'u0009', 'apply', null, '2026-09-29', null, 'u0010'],
            ['u0016', 'u0003', 'approve', 'project_plan', '2026-10-08', '2026-10-18', 'u0004'],
            ['u0003', 'u0009', 'approve', null, '2026-10-13', null, 'u0003'],
        ], $proxies);
    }

    /**
     * 4.1 の代理 4 行の登録の通知「代理」(設計/ワークフロー/案件の運用/通知.md の 3 節。宛先 = 代理先、起こした人 = 登録した人、対象 = 代理の行):
     * u0002・u0009 に 2 つ・u0003。文の値は登録の時の写し(終了日は最後の有効な日)。
     */
    public function test_the_four_proxies_send_their_notices(): void
    {
        $notices = Notice::query()->with(['user', 'actor'])->where('type', 'workflow.proxy')->orderBy('id')->get();
        $params = fn (Notice $notice, string $key) => $notice->params[$key] ?? null;

        $this->assertSame([
            ['u0002', 'u0010', '山田 恵', 'approve', '課題の対応の承認', '2026-08-30', '2026-09-28'],
            ['u0009', 'u0010', '山田 恵', 'apply', null, '2026-09-29', null],
            ['u0003', 'u0004', '清水 美穂', 'approve', 'プロジェクト計画の承認', '2026-10-08', '2026-10-18'],
            ['u0009', 'u0003', '高橋 誠', 'approve', null, '2026-10-13', null],
        ], $notices->map(fn (Notice $notice) => [
            $notice->user?->code, $notice->actor?->code, $params($notice, 'principal_name'), $params($notice, 'kind'),
            $params($notice, 'flow_name'), $params($notice, 'start_date'), $params($notice, 'end_date'),
        ])->all());
        $this->assertSame(['workflow-proxy'], $notices->pluck('target_type')->unique()->values()->all());
        $proxyIds = WorkflowProxy::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame($proxyIds, $notices->pluck('target_id')->all());
    }

    /**
     * 4.3: WF-000013 の部長は保留中で、保留の行の処理者 u0003・実行者 u0009。WF-000015 は申請者 u0010・実行者 u0009・レコードの作成者 u0010。
     * WF-000016 の課長の承認の処理担当者は u0009(振替先)で、担当変更の履歴の人が u0009。
     */
    public function test_the_hold_the_proxy_application_and_the_transfer_samples(): void
    {
        $hold = $this->historyOf(13, 'hold');
        $this->assertSame(['hold', 'u0003', 'u0009', '取引先の見積りが届くまで保留します。'], [
            $this->nodeOf(13, 'manager')->state->value, $hold->actor?->code, $hold->executor?->code, $hold->comment,
        ]);

        $fifteenth = $this->caseOf(15);
        $apply = $this->historyOf(15, 'apply');
        $this->assertSame(['u0010', 'u0010', 'u0009', 'u0010'], [
            $fifteenth->applicant?->code, $apply->actor?->code, $apply->executor?->code,
            Record::query()->with('createdByUser')->findOrFail($fifteenth->record_id)->createdByUser?->code,
        ]);

        $transfer = $this->historyOf(16, 'transfer');
        $this->assertSame([['u0009'], 'u0002', ['u0009']], [
            WorkflowCaseNodeTarget::query()->with('user')
                ->where('workflow_case_node_id', $this->nodeOf(16, 'section_chief')->id)->get()
                ->map(fn (WorkflowCaseNodeTarget $target) => $target->user?->code)->all(),
            $transfer->actor?->code,
            WorkflowCaseHistoryUser::query()->with('user')->where('workflow_case_history_id', $transfer->id)->get()
                ->map(fn (WorkflowCaseHistoryUser $row) => $row->user?->code)->all(),
        ]);
    }

    /**
     * 4.3 の下の箇条: u0010 が WF-000009 を確認し、開いていた課長と部長の両方が確認済。確認の行はほかの案件に無い。
     */
    public function test_u0010_confirmed_both_steps_of_the_ninth_case(): void
    {
        $this->assertSame(
            [[9, 'section_chief', 'u0010'], [9, 'manager', 'u0010']],
            WorkflowCaseConfirmation::query()->with(['workflowCase', 'workflowCaseNode', 'user'])->orderBy('id')->get()
                ->map(fn (WorkflowCaseConfirmation $row) => [
                    $row->workflowCase?->case_number, $row->workflowCaseNode?->code, $row->user?->code,
                ])->all(),
        );
    }

    /**
     * 足した見本の通知(宛先 = 設計/ワークフロー/案件の運用/通知.md の 3 節を 4.3 の操作に当てた値。代理の登録の通知は対象が代理なので入らない):
     * WF-000013 = 課長・部長の処理依頼と課長の確認依頼(保留は通知しない)、WF-000014 = 課長の処理依頼・連絡・部長の処理依頼(代理先 u0009 にも。
     * 代理は D − 2 から有効)・確認依頼、WF-000015 = 課長の処理依頼(処理者 u0010・実行者 u0009 には送らない)、WF-000016 = 処理依頼と振替先への担当変更。
     */
    public function test_the_added_samples_send_the_notices_of_the_design(): void
    {
        $request = 'workflow.process_request';
        $noticesOf = fn (int $number) => Notice::query()->with('user')->where('target_type', 'workflow-case')
            ->where('target_id', $this->caseOf($number)->id)->orderBy('id')->get()
            ->map(fn (Notice $notice) => "{$notice->user?->code} {$notice->type}")->all();

        $this->assertSame([
            13 => ["u0002 {$request}", "u0003 {$request}", 'u0010 workflow.confirm_request'],
            14 => [
                "u0002 {$request}", 'u0012 workflow.contact', "u0003 {$request}", "u0009 {$request}",
                'u0010 workflow.confirm_request',
            ],
            15 => ["u0002 {$request}"],
            16 => ["u0002 {$request}", 'u0009 workflow.transfer'],
        ], array_map($noticesOf, [13 => 13, 14 => 14, 15 => 15, 16 => 16]));
    }

    private function caseOf(int $number): WorkflowCase
    {
        return WorkflowCase::query()->with('applicant')->where('case_number', $number)->sole();
    }

    private function nodeOf(int $number, string $code): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->whereRelation('workflowCase', 'case_number', $number)->where('code', $code)
            ->sole();
    }

    private function historyOf(int $number, string $action): WorkflowCaseHistory
    {
        return WorkflowCaseHistory::query()->with(['actor', 'executor'])
            ->whereRelation('workflowCase', 'case_number', $number)->where('action', $action)->sole();
    }
}
