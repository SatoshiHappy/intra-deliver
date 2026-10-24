<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplicationBaseDate;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\CaseEntryGate;
use App\Queries\Workflow\PrincipalContext;
use App\Queries\Workflow\RecordApplyFlows;
use App\Queries\Workflow\WorkflowRecordApplyEntries;
use Tests\Support\AppliesFromRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * そのものを申請する経路の作成者の確かめ(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節・4 節の 0・6 節・8 節):
 * 申請者(代理なら代理元)がレコードの作成者でなければ 404(ほかの人・作成者なし・行の判定で見えないも同じ)。権限の無い人にはどのレコードも 403。
 * 入口の判定(ボタン・GET・申請の入口)は allowsAny と、申請者の判定(applicants・申請基準日の (4)・申請の 0)は allows と同じ結果になる。
 * 完了の後も同じ申請書のレコードはもう一度申請できない。
 */
final class RecordApplicationCreatorTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use CatchesValidationErrors;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 自分として申請すると自分が作成者のレコードだけ、代理元を選ぶと代理元が作成者のレコードだけが通る。ほかは 404 で何も書かない。
     */
    public function test_only_the_creator_can_apply_for_self_and_for_the_principal(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $records = $this->recordsByCreator($proxy, $principal);
        $counts = $this->workflowCounts();

        $asSelf = fn (int $recordId) => $this->outcome(fn () => $this->applyFromRecord($proxy, $recordId, version: ''));
        $asPrincipal = fn (int $recordId) => $this->outcome(
            fn () => $this->applyFromRecord($proxy, $recordId, principal: $principal, version: ''),
        );
        $failures = [
            array_map($asSelf, [$records['principal'], $records['other'], $records['nobody']]),
            array_map($asPrincipal, [$records['proxy'], $records['other'], $records['nobody']]),
        ];

        $this->assertSame([['404', '404', '404'], ['404', '404', '404']], $failures);
        $this->assertSame($counts, $this->workflowCounts());
        $this->assertSame($proxy->id, $this->applyFromRecord($proxy, $records['proxy'])->user_id);
        $byPrincipal = $this->applyFromRecord($proxy, $records['principal'], principal: $principal);
        $this->assertSame($principal->id, $byPrincipal->user_id);
    }

    /**
     * 行の判定: 自分は見えなくても、作成者の代理元を選べば通る(判定を求める者 = 代理元)。自分としては 404。
     */
    public function test_the_row_access_is_judged_on_the_applicant(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $record = $this->projectRecord($principal);
        RecordModule::query()->where('code', self::PROJECT)->update(['row_access_scope' => RowAccessScope::Related]);

        $this->assertSame('404', $this->outcome(fn () => $this->applyFromRecord($proxy, $record->id)));
        $this->assertSame($principal->id, $this->applyFromRecord($proxy, $record->id, principal: $principal)->user_id);
    }

    /**
     * 権限の無い人(自分も代理元も update が無い)には、無い・ほかのモジュール・明細の行・申請中・ほかの人の・作成者なし・行の判定で見えない
     * レコードのどれも 403(存在を漏らさない)。
     */
    public function test_a_person_who_cannot_apply_gets_403_whatever_the_record_is(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply);
        $records = $this->recordsByCreator($proxy, $principal);
        $held = $this->projectRecord($proxy);
        $this->applyFromRecord($proxy, $held->id);
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $rowId = $this->detailRowIds($records['proxy'])[0];
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $this->seededUser('u0003'));
        RecordModule::query()->where('code', self::PROJECT)->update(['row_access_scope' => RowAccessScope::Related]);
        $missing = (int) Record::query()->max('id') + 1000;
        $recordIds = [$missing, $other->id, $rowId, $held->id, ...array_values($records)];

        foreach ([null, $principal] as $chosen) {
            $outcomes = array_map(fn (int $recordId) => $this->outcome(
                fn () => $this->applyFromRecord($proxy, $recordId, principal: $chosen, version: ''),
            ), $recordIds);
            $this->assertSame(array_fill(0, count($recordIds), '403'), $outcomes);
        }
    }

    /**
     * 同じ組で、ボタン(入口の口)・GET・申請の入口の結果は allowsAny と、申請者の候補・申請基準日の (4)・申請の 0 の結果は allows と同じ
     * (入口はレコードの作成者 = 申請できる側の人、申請者の判定はその人が作成者のレコードで見る。create だけの人は数えない)。
     */
    public function test_the_entrances_agree_with_allows_any_and_the_applicant_checks_agree_with_allows(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $cases = [
            'both' => [[$proxy, $principal], $principal],
            'principal_only' => [[$principal], $principal],
            'self_only' => [[$proxy], $proxy],
            'create_only' => [[], $principal],
        ];

        foreach ($cases as $name => [$updaters, $creator]) {
            $this->allowOnly(self::PROJECT, RecordModuleAction::Update, ...$updaters);
            $recordId = Record::factory()->create([
                'record_module_id' => $this->moduleIdOf(self::PROJECT), 'created_by_user_id' => $creator->id,
            ])->id;
            [$allowsAny, $entrances] = $this->entranceResults($proxy, $recordId);
            $this->assertSame([$allowsAny, $allowsAny, $allowsAny], $entrances, $name);

            foreach ([$proxy, $principal] as $person) {
                [$allows, $checks] = $this->applicantResults($proxy, $person);
                $this->assertSame([$allows, $allows, $allows], $checks, "{$name}: {$person->code}");
            }
        }
    }

    /**
     * 完了の後: 同じ申請書のレコードはもう一度申請できず(422 record)、業務の画面からも更新できない(持ち主の理由)。
     */
    public function test_a_completed_record_cannot_be_applied_again_or_updated_from_the_screen(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->complete($this->applyFromRecord($u0001, $record->id));
        $counts = $this->workflowCounts();

        $again = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $record->id));
        $screen = $this->validationErrors(fn () => $this->updateFromScreen($u0001, $record->id, ['summary' => '直す']));

        $this->assertSame(['record' => RecordApplicationSteps::HELD], $again);
        $this->assertSame(['record'], array_keys($screen));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 作成者ごとのレコード(代理先・代理元・ほかの人(u0003)・作成者なし)の id。
     *
     * @return array{proxy: int, principal: int, other: int, nobody: int}
     */
    private function recordsByCreator(User $proxy, User $principal): array
    {
        return [
            'proxy' => $this->projectRecord($proxy)->id,
            'principal' => $this->projectRecord($principal)->id,
            'other' => $this->projectRecord($this->seededUser('u0003'))->id,
            'nobody' => Record::factory()->create(['record_module_id' => $this->moduleIdOf(self::PROJECT)])->id,
        ];
    }

    /**
     * allowsAny と、[入口の口が空でない, GET が 403 でない, 申請の入口が 403 でない]。
     *
     * @return array{bool, list<bool>}
     */
    private function entranceResults(User $user, int $recordId): array
    {
        $context = $this->contextOf($user);
        $flowId = $this->flowIdOf(self::FLOW_CODE);
        $requester = new Requester($user->id, null);
        $allowsAny = app(RecordApplyFlows::class)->allowsAny($requester, $flowId, $context->date);
        $entries = app(WorkflowRecordApplyEntries::class)->entriesFor($requester, $recordId, $context->date);
        $forms = app(ApplicationFormQuery::class);
        $page = $this->outcome(fn () => $forms->fromRecord($context, self::FLOW_CODE, $recordId));
        $entrance = $this->outcome(fn () => app(CaseEntryGate::class)->authorizeRecordApply($context, self::FLOW_CODE));

        return [$allowsAny, [$entries !== [], $page !== '403', $entrance !== '403']];
    }

    /**
     * その人の allows と、その人が作成者のレコードで [既定の申請者(候補)がその人, 申請基準日の (4) を通る, 申請の 0 で 403 にならない]
     * (代理先が操作し、その人を申請者にする)。
     *
     * @return array{bool, list<bool>}
     */
    private function applicantResults(User $proxy, User $person): array
    {
        $context = $this->contextOf($proxy);
        $applicant = PrincipalContext::of($context, $person->id);
        $flowId = $this->flowIdOf(self::FLOW_CODE);
        $recordId = Record::factory()->create([
            'record_module_id' => $this->moduleIdOf(self::PROJECT), 'created_by_user_id' => $person->id,
        ])->id;
        $allows = app(RecordApplyFlows::class)->allows(new Requester($person->id, null), $flowId, $context->date);
        $forms = app(ApplicationFormQuery::class);
        $default = $this->outcome(fn () => $forms->recordApplicant($context, $flowId, self::PROJECT, $recordId));
        $chosen = $default === 'success' ? $forms->recordApplicant($context, $flowId, self::PROJECT, $recordId) : false;
        $day = $context->date->toDateString();
        $rules = app(ApplicationBaseDate::class);
        $baseDate = $this->outcome(fn () => $rules->resolveFromRecord($applicant, $flowId, $day));
        $confirm = $this->outcome(fn () => app(RecordApplicationSteps::class)
            ->confirm($applicant, $flowId, self::PROJECT, $recordId));
        $expected = $person->is($proxy) ? null : $person->id;

        return [$allows, [$chosen === $expected, $baseDate === 'success', $confirm !== '403']];
    }
}
