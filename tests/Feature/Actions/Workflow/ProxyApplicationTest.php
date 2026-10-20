<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DeleteDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\UpdateDraft;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の代理(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節、操作.md の 10.8 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「申請の代理」・14.3 節の「申請の代理 × 業務データの登録の権限」の業務処理の分)。
 * 代理元 A = u0009(総務部が主所属)、申請の代理先 B = u0002(東京営業課)。代理元を選んだ申請は、申請者・担当組織(代理元の申請基準日の主所属)・
 * レコードの作成者が代理元、履歴の実行者が代理先。一時保存は持ち主(代理元)のもので、代理先は代理の期間の内だけ上書き・削除・申請できる。
 * 申請できる人・申請基準日の規則・業務モジュールの判定は代理元で行う(テストの中でポリシーを足して許可・禁止を作る)。
 */
final class ProxyApplicationTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use CatchesValidationErrors;
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const APPLICANT_ERROR = ['applicant_user_id' => '選択した申請者として申請できません。代理の設定を確認してください。'];

    public function test_an_application_as_the_principal_belongs_to_the_principal_and_records_the_executor(): void
    {
        [$a, $b] = $this->users();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->createFlow());

        $case = $this->applyFor($b, $a, $this->departmentIdOf('tokyo-sales'));

        $record = Record::query()->findOrFail($case->record_id);
        $this->assertSame(
            [$a->id, $this->departmentIdOf('general-affairs'), $a->id],
            [$case->user_id, $case->department_id, $record->created_by_user_id],
        );
        $this->assertSame([['apply', $a->id, $b->id]], $this->histories($case));
    }

    /**
     * 代理が無い・別が承認・別のフロー・期間の後は、どれも 422 applicant_user_id で何も書かない(新規の申請と新規の一時保存)。
     */
    public function test_an_applicant_who_is_not_a_principal_of_an_apply_proxy_is_rejected(): void
    {
        [$a, $b] = $this->users();
        $this->createFlow();
        $otherFlowId = $this->createFlow('wf_other', 'ほかのフロー');
        $proxies = [
            'none' => fn () => null,
            'approve kind' => fn () => $this->addProxy($a, $b),
            'other flow' => fn () => $this->addProxy($a, $b, WorkflowProxyKind::Apply, $otherFlowId),
            'after the period' => fn () => $this->addProxy($a, $b, WorkflowProxyKind::Apply, end: '2026-10-13'),
        ];

        foreach ($proxies as $label => $add) {
            $add();
            $counts = $this->workflowCounts();
            $this->assertSame(self::APPLICANT_ERROR, $this->validationErrors(fn () => $this->applyFor($b, $a)), $label);
            $this->assertSame(self::APPLICANT_ERROR, $this->validationErrors(fn () => $this->draftFor($b, $a)), $label);
            $this->assertSame($counts, $this->workflowCounts(), $label);
        }
    }

    /**
     * 代理先が作った代理元の一時保存と、代理元が作った一時保存の両方を、代理先が期間の内だけ上書き・削除・申請できる(期間の後は 404)。
     * 一時保存からの申請は 申請者 = 処理者 = 代理元、実行者 = 代理先。
     */
    public function test_the_proxy_overwrites_deletes_and_applies_drafts_of_the_principal_within_the_period(): void
    {
        [$a, $b] = $this->users();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->createFlow(), '2026-10-01', '2026-10-14');
        $byProxy = $this->draftFor($b, $a);
        $byPrincipal = $this->draftFor($a, null);
        $late = $this->draftFor($a, null);

        $this->overwrite($b, $byProxy);
        $applied = $this->applyDraft($b, $byPrincipal);
        $this->deleteDraft($b, $byProxy);
        $afterPeriod = array_map(fn (Closure $operate) => $this->outcomeOf($operate), [
            fn () => $this->overwrite($b, $late, '2026-10-14'),
            fn () => $this->deleteDraft($b, $late, '2026-10-14'),
            fn () => $this->applyDraft($b, $late, '2026-10-14'),
        ]);

        $this->assertSame([$a->id, $a->id], [WorkflowCase::query()->findOrFail($late)->user_id, $applied->user_id]);
        $this->assertFalse(WorkflowCase::query()->whereKey($byProxy)->exists());
        $this->assertSame([['apply', $a->id, $b->id]], $this->histories($applied));
        $this->assertSame(['404', '404', '404'], $afterPeriod);
    }

    /**
     * 一時保存からの申請の 403 は持ち主(代理元)で決まる: 代理先だけが申請できる → 403、代理元だけが申請できる → 代理先が申請できる。
     * 代理元が申請できなくなっても、代理先は期間の内なら代理元の一時保存を消せる。
     */
    public function test_the_403_of_an_application_from_a_draft_is_judged_by_the_owner(): void
    {
        [$a, $b] = $this->users();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->createFlow());
        $onlyProxy = $this->draftFor($a, null);
        $onlyPrincipal = $this->draftFor($a, null);

        $this->allowOnly('project', RecordModuleAction::Create, $b);
        $rejected = [
            $this->outcomeOf(fn () => $this->applyDraft($b, $onlyProxy)),
            $this->outcomeOf(fn () => $this->overwrite($b, $onlyProxy)),
        ];
        $this->deleteDraft($b, $onlyProxy);
        $this->allowOnly('project', RecordModuleAction::Create, $a);
        $applied = $this->applyDraft($b, $onlyPrincipal);

        $this->assertSame(['403', '403'], $rejected);
        $this->assertFalse(WorkflowCase::query()->whereKey($onlyProxy)->exists());
        $this->assertSame([$a->id, [['apply', $a->id, $b->id]]], [$applied->user_id, $this->histories($applied)]);
    }

    /**
     * 14.3 の (1)・(2): 申請できる人と業務モジュールの create は代理元で判定する(代理先自身が許されるかは見ない)。(2) は何も書かない。
     */
    public function test_the_create_permission_is_judged_by_the_principal(): void
    {
        [$a, $b] = $this->users();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->createFlow());

        $this->allowOnly('project', RecordModuleAction::Create, $a);
        $applied = $this->applyFor($b, $a);
        $this->allowOnly('project', RecordModuleAction::Create, $b);
        $counts = $this->workflowCounts();
        $rejected = $this->outcomeOf(fn () => $this->applyFor($b, $a));

        $this->assertSame($a->id, $applied->user_id);
        $this->assertSame('403', $rejected);
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 14.3 の (3)・(5): 再申請の update は代理元で判定する。代理元が許されない → 403、代理元が許され代理先が許されない → 代理先が代理元として再申請できる。
     */
    public function test_the_update_permission_of_a_reapply_is_judged_by_the_principal(): void
    {
        [$a, $b, $approver] = [...$this->users(), $this->seededUser('u0003')];
        $flowId = $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $flowId);
        $case = $this->applyAs($a);
        $this->sendBack($approver, $case, 'apply');
        $reapply = fn () => app(ReapplyCase::class)
            ->execute($this->contextOf($b), $case->id, $this->revisionOf($case), $this->projectInput(), $a->id);

        $this->allowOnly('project', RecordModuleAction::Update, $b);
        $rejected = $this->outcomeOf($reapply);
        $this->allowOnly('project', RecordModuleAction::Update, $a);
        $reapply();

        $this->assertSame('403', $rejected);
        $this->assertSame(['reapply', $a->id, $b->id], $this->histories($case)[2]);
    }

    /**
     * 14.3 の (4): 代理元が申請基準日に有効でない → 422 base_date で何も書かない(代理元 u0015 = 2026-11-01 入社。今日 2026-11-02、申請基準日 2026-10-20)。
     */
    public function test_a_principal_not_effective_on_the_base_date_is_rejected_by_the_base_date_rule(): void
    {
        [$a, $b] = $this->seededUsers('u0015', 'u0002');
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->createFlow());
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => app(ApplyCase::class)->execute(
            $this->contextOf($b, date: '2026-11-02'), self::FLOW_CODE, $this->projectInput(), '2026-10-20', $a->id));

        $this->assertSame(['base_date'], array_keys($errors));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * @return array{User, User} [代理元 A, 代理先 B]
     */
    private function users(): array
    {
        return [$this->seededUser('u0009'), $this->seededUser('u0002')];
    }

    private function applyFor(User $proxy, User $principal, ?int $selectedDepartmentId = null): WorkflowCase
    {
        $context = $this->contextOf($proxy, $selectedDepartmentId);
        $baseDate = $context->date->toDateString();
        $input = $this->projectInput();
        $result = app(ApplyCase::class)->execute($context, self::FLOW_CODE, $input, $baseDate, $principal->id);

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    private function draftFor(User $user, ?User $principal): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($user), self::FLOW_CODE, $input, $principal?->id);
    }

    private function overwrite(User $user, int $caseId, ?string $date = null): void
    {
        $input = new DraftInput('上書き', null, [], []);
        $context = $this->contextOf($user, date: $date);
        app(UpdateDraft::class)->execute($context, $caseId, $this->revisionOfId($caseId), $input);
    }

    private function deleteDraft(User $user, int $caseId, ?string $date = null): void
    {
        app(DeleteDraft::class)->execute($this->contextOf($user, date: $date), $caseId, $this->revisionOfId($caseId));
    }

    private function applyDraft(User $user, int $caseId, ?string $date = null): WorkflowCase
    {
        $context = $this->contextOf($user, date: $date);
        $baseDate = $context->date->toDateString();
        $input = $this->projectInput();
        app(ApplyCase::class)->executeFromDraft($context, $caseId, $this->revisionOfId($caseId), $input, $baseDate);

        return WorkflowCase::query()->findOrFail($caseId);
    }

    private function revisionOfId(int $caseId): int
    {
        return WorkflowCase::query()->findOrFail($caseId)->revision;
    }

    /**
     * 操作の結果(success・404・403・422:<キー>)。
     *
     * @param  Closure(): mixed  $operate
     */
    private function outcomeOf(Closure $operate): string
    {
        try {
            $operate();

            return 'success';
        } catch (ModelNotFoundException) {
            return '404';
        } catch (AuthorizationException) {
            return '403';
        } catch (ValidationException $exception) {
            return '422:'.implode(',', array_keys($exception->errors()));
        }
    }

    /**
     * 履歴の行の [種別, 処理者, 実行者](id の順)。
     *
     * @return list<array{string, int, int|null}>
     */
    private function histories(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()
            ->map(fn (WorkflowCaseHistory $row) => [$row->action->value, $row->user_id, $row->executor_user_id])
            ->all());
    }
}
