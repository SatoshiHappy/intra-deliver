<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Workflow\ApplicationApplicants;
use App\Queries\Workflow\CaseVisibility;
use App\Queries\Workflow\CopiedRowAccess;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\InterposesAfterRevisionCopy;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の写す元と組み立ての後の判定(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の
 * 12.3 の判定の後の表・13.1・13.4): 写す元は最後の申請・再申請の版で、写す人の業務モジュールの権限と行の判定に依らない。判定の後に
 * 写す元を引けない・閲覧を失ったときは 404、元の申請のレコードがほかの業務モジュールのものなら作りの誤りの例外。
 */
final class ApplicationCopyFormTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CopiesCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 再申請した申請は再申請の版から写る。完了の後に規則の外から今の値と参照制限を変え、後の版を足しても写らない。写す人が申請書の業務モジュールの
     * read を持たない・行の判定で見えない関係者でも同じ版から写る。
     */
    public function test_the_last_submitted_revision_is_copied(): void
    {
        [$u0001, $u0002] = $this->usersOf('u0001', 'u0002');
        $this->copyFlow([$u0001, $u0002]);
        $case = $this->applyNew($u0001, null, ['amount' => 1]);
        $this->sendBack($u0002, $case, 'apply');
        $this->reapplyCase($u0001, null, $case, ['amount' => 2]);
        $this->operate(ApproveCase::class, $u0002, $case);
        $this->changeOutsideTheRules((int) $case->record_id);

        $this->assertSame([2, false], $this->copiedAs($u0001, $case->id));

        $this->onlyRelated(self::COPY_FORM);
        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Read, $u0001);
        $this->assertSame([2, false], $this->copiedAs($u0002, $case->id));
    }

    /**
     * 判定の後の表: 写す元を引けない(判定の後に申請が消えた)と 404、組み立ての後に写す人が閲覧を失うと 404(500 にならない)。
     */
    public function test_losing_the_source_after_the_judgment_is_404(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $deleted = $this->sourceCase($u0001);
        $this->beforeChoosingApplicant(fn () => WorkflowCase::query()->whereKey($deleted->id)->delete());

        $this->assertSame(404, $this->copyStatusAs($u0001, $deleted->id));

        $hidden = $this->sourceCase($u0001);
        $this->hideOnSecondCheck();

        $this->assertSame(404, $this->copyStatusAs($u0001, $hidden->id));
    }

    /**
     * 元の申請のレコードをほかの業務モジュールのものに書き換えると、作りの誤りの例外で 500(値を写さない)。
     */
    public function test_a_source_of_another_module_is_a_programming_error(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001);
        $hub = RecordModule::query()->where('code', self::HUB)->sole()->id;
        // 版は (業務モジュール, レコード) の外部キーで指すので、同じ文で両方を書き換える
        DB::update(
            'with revisions as (update record_revisions set record_module_id = ? where record_id = ?)'
            .' update records set record_module_id = ? where id = ?',
            [$hub, $source->record_id, $hub, $source->record_id],
        );

        $this->assertSame(500, $this->copyStatusAs($u0001, $source->id));
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('のものでない');
        $this->copyStatusAs($u0001, $source->id);
    }

    /**
     * 申請対象: 既定の申請者が閲覧できれば targetValues で写し、元の業務モジュールの read が無い・行の判定で見えなければ写さず 200。
     * 写しの値を作った後・申請対象の判定の前に閲覧を失うと、申請対象は写した値のまま残って写さず、申請すると 422。
     */
    public function test_the_application_target_is_copied_only_when_viewable(): void
    {
        [$u0001, $u0004] = $this->usersOf('u0001', 'u0004');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $source = $this->sourceCase($u0001, [self::TARGET_FIELD => $hub]);

        $opened = $this->copyPropsAs($u0001, $source->id);
        $this->assertSame($hub, $opened['targetValues']['values'][self::TARGET_FIELD]['id'] ?? null);

        $this->losesTargetAfterCopying(fn () => $this->allowOnly(self::HUB, RecordModuleAction::Read, $u0004));
        $props = $this->copyPropsAs($u0001, $source->id);
        $copiedTarget = $props['initial']['values'][self::TARGET_FIELD]['id'] ?? null;
        $this->assertSame([null, $hub], [$props['targetValues'], $copiedTarget]);
        $sent = $this->sentFrom($props);
        $sent['values'][self::TARGET_FIELD] = $hub;
        $this->post(self::APPLY_URL, $sent)->assertSessionHasErrors('values.'.self::TARGET_FIELD);

        $this->assertNull($this->copyPropsAs($u0001, $source->id)['targetValues']);
    }

    /**
     * 代理のとき targetValues は既定の申請者(代理元)の権限で作られる: 申請対象の業務モジュールの read が代理元だけにあれば写り、
     * 代理元に見えない参照先は申請対象の写しの空にした項目に出る(元の申請の値の空にした項目とは別)。自分だけに read があれば写さない。
     */
    public function test_the_target_values_of_a_proxy_follow_the_principal(): void
    {
        [$u0002, $u0003, $principal] = $this->usersOf('u0002', 'u0003', 'u0009');
        $flowId = $this->copyFlow([$principal]);
        $this->addProxy($principal, $u0002, WorkflowProxyKind::Apply, $flowId);
        $hub = $this->hubRecord($principal, $u0003)['hub'];
        $source = $this->sourceCase($principal, [self::TARGET_FIELD => $hub]);
        $this->onlyRelated('m_ref_a');
        $this->allowOnly(self::HUB, RecordModuleAction::Read, $principal);

        $props = $this->copyPropsAs($u0002, $source->id);

        $this->assertSame($principal->id, $props['applicant']);
        $this->assertSame($hub, $props['targetValues']['values'][self::TARGET_FIELD]['id'] ?? null);
        $this->assertSame([['ref'], []], [$props['targetValues']['clearedFields'], $props['copy']['clearedFields']]);

        $this->allowOnly(self::HUB, RecordModuleAction::Read, $u0002);
        $this->assertNull($this->copyPropsAs($u0002, $source->id)['targetValues']);
    }

    /**
     * 開いた画面の写した額と、暗号化した値の印。
     *
     * @return array{mixed, bool|null}
     */
    private function copiedAs(User $user, int $caseId): array
    {
        $props = $this->copyPropsAs($user, $caseId);
        $restriction = app(CopiedRowAccess::class)
            ->open($props['copy']['rowAccess'], $user->id, $this->flowIdOf(self::COPY_FLOW));

        return [$props['initial']['values']['amount'], $restriction['restricted'] ?? null];
    }

    /**
     * 規則の外から、今の値(額 = 9)と参照制限(付き)を変え、その形の後の版を足す。
     */
    private function changeOutsideTheRules(int $recordId): void
    {
        $amount = RecordField::query()->where('code', 'amount')
            ->where('record_module_id', RecordModule::query()->where('code', self::COPY_FORM)->sole()->id)->sole();
        $record = Record::query()->findOrFail($recordId);
        $values = [...($record->field_values ?? []), $amount->id => 9];
        $record->update(['field_values' => $values, 'row_access_restricted' => true]);
        $latest = RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();
        $later = $latest->replicate();
        $later->revision = $latest->revision + 1;
        $later->save();
        $this->setRevisionRowAccess($later->id, ['restricted' => true, 'viewers' => []]);
    }

    /**
     * 既定の申請者を選ぶ前(判定の順の後・写す元を読む前)に差し込む。
     */
    private function beforeChoosingApplicant(Closure $between): void
    {
        $real = app(ApplicationApplicants::class);
        app()->instance(ApplicationApplicants::class, new class($real, $between) extends ApplicationApplicants
        {
            public function __construct(private ApplicationApplicants $real, private Closure $between) {}

            public function choose(AccessContext $context, array $candidateIds, mixed $applicantId): array
            {
                ($this->between)();

                return $this->real->choose($context, $candidateIds, $applicantId);
            }

            public function propsOf(AccessContext $context, array $candidateIds, int $applicantId): array
            {
                return $this->real->propsOf($context, $candidateIds, $applicantId);
            }
        });
    }

    /**
     * 閲覧の判定の 2 回目(組み立ての後)から何も見えなくする。
     */
    private function hideOnSecondCheck(): void
    {
        $real = app(CaseVisibility::class);
        app()->instance(CaseVisibility::class, new class($real) extends CaseVisibility
        {
            private int $calls = 0;

            public function __construct(private CaseVisibility $real) {}

            public function visible(Builder $query, Requester $requester, DateTimeInterface $date): Builder
            {
                $this->calls++;

                return $this->calls === 1 ? $this->real->visible($query, $requester, $date) : $query->whereRaw('false');
            }
        });
    }

    /**
     * 写しの初期値(版から)を作った直後に差し込む(申請対象の判定の前)。
     */
    private function losesTargetAfterCopying(Closure $between): void
    {
        $real = app(RecordFormQuery::class);
        app()->instance(RecordFormQuery::class, new InterposesAfterRevisionCopy($real, $between));
    }
}
