<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 新規の申請の画面の申請者(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節、案件の運用/案件の操作/README.md の 3.1 節・4.5 節):
 * applicants = 自分と、自分を申請の代理先とする今日に有効でそのフローに効く代理の代理元のうち、このフローで申請できる人(自分が先、代理元は昇順)。
 * applicant = 既定の申請者(クエリ applicant が候補の代理元 → その人、自分が候補にある → 自分 = null、ほか → 先頭の代理元)。
 * 定義・次の処理担当者はこの申請者で引く。代理先 B = u0002(東京営業課)、代理元 A1 = u0009(総務部)・A2 = u0011(開発部)。申請できるかは
 * 申請書の業務モジュールの create で作る(申請のノードの対象者は全員)。
 */
final class ApplicationApplicantsPageTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    /** 代理元 1 人で増えてよい問い合わせの数(見本の代理元の測った値は 31・15) */
    private const QUERIES_PER_PRINCIPAL = 40;

    private int $flowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->flowId = $this->createFlow();
    }

    /**
     * 自分だけ / 代理元だけ / 両方 / 代理元 2 人(昇順)。申請できない代理元は入らない。
     */
    public function test_the_applicants_are_the_requester_and_the_principals_who_can_apply(): void
    {
        [$b, $a1, $a2] = $this->people();
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $this->flowId);

        $this->allowCreate($b);
        $onlySelf = $this->choiceOf($b);
        $this->allowCreate($a1);
        $onlyPrincipal = $this->choiceOf($b);
        $this->allowCreate($b, $a1);
        $both = $this->choiceOf($b);
        $this->addProxy($a2, $b, WorkflowProxyKind::Apply);
        $this->allowCreate($b, $a1, $a2);
        $twoPrincipals = $this->choiceOf($b);

        $this->assertSame([[$b->id], null], $onlySelf);
        $this->assertSame([[$a1->id], $a1->id], $onlyPrincipal);
        $this->assertSame([[$b->id, $a1->id], null], $both);
        $this->assertSame([[$b->id, ...$this->ascending($a1->id, $a2->id)], null], $twoPrincipals);
    }

    /**
     * 期間の後・別のフローに効く・承認の代理の代理元は入らない(どれも申請できる人)。
     */
    public function test_principals_of_proxies_not_in_effect_for_the_flow_are_not_applicants(): void
    {
        [$b, $a1] = $this->people();
        $otherFlowId = $this->createFlow('wf_other', 'ほかのフロー');
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, end: '2026-10-13');
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $otherFlowId);
        $this->addProxy($a1, $b, WorkflowProxyKind::Approve, $this->flowId);
        $this->allowCreate($b, $a1);

        $this->assertSame([[$b->id], null], $this->choiceOf($b));
    }

    /**
     * 各要素の形(名前と主所属の名前は今日、自分の印)。
     */
    public function test_each_applicant_has_the_name_the_main_department_and_the_self_mark(): void
    {
        [$b, $a1] = $this->people();
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->actingAs($b);

        $props = $this->propsOf($b);

        $this->assertSame([
            ['id' => $b->id, 'name' => '中村 美咲', 'department' => '東京営業課', 'isSelf' => true],
            ['id' => $a1->id, 'name' => '吉田 達也', 'department' => '総務部', 'isSelf' => false],
        ], $props['applicants']);
    }

    /**
     * 既定の申請者の 3 つの枝。解釈できない値・代理元でない値・申請できない代理元の値は既定と同じ。
     */
    public function test_the_default_applicant_follows_the_query_and_the_candidates(): void
    {
        [$b, $a1, $a2] = $this->people();
        $nonPrincipal = $this->seededUser('u0003');
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->addProxy($a2, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->allowCreate($b, $a1);
        $withSelf = array_map(fn (string $applicant) => $this->choiceOf($b, ['applicant' => $applicant])[1], [
            (string) $a1->id, 'abc', '0', (string) $nonPrincipal->id, (string) $a2->id, (string) $b->id,
        ]);
        $this->allowCreate($a1, $a2);
        $withoutSelf = [
            $this->choiceOf($b)[1],
            $this->choiceOf($b, ['applicant' => (string) $a2->id])[1],
            $this->choiceOf($b, ['applicant' => (string) $b->id])[1],
        ];

        $this->assertSame([$a1->id, null, null, null, null, null], $withSelf);
        $this->assertSame([min($a1->id, $a2->id), $a2->id, min($a1->id, $a2->id)], $withoutSelf);
    }

    /**
     * 定義と次の処理担当者は既定の申請者で引く: 自分が create を許されなくても代理元で開き、担当組織は代理元の主所属。
     */
    public function test_the_definition_and_the_next_targets_are_read_by_the_applicant(): void
    {
        [$b, $a1] = $this->people();
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->allowCreate($a1);
        $asPrincipal = $this->propsOf($b);
        $this->allowCreate($b, $a1);
        $asSelf = $this->propsOf($b);

        $this->assertSame(
            [$this->projectFormModuleCode(), '総務部', $a1->id],
            [$asPrincipal['module']['code'], $asPrincipal['next']['departmentName'], $asPrincipal['applicant']],
        );
        $this->assertSame(['東京営業課', null], [$asSelf['next']['departmentName'], $asSelf['applicant']]);
    }

    /**
     * 新規の申請の画面の問い合わせの数: 代理元 1 人ごとに増えるのは、その人が申請できるかの判定(所属・役割の数で変わる)の分だけ。
     * 最初の 1 回は要求をまたいで残る読み込みを含むので数えない。
     */
    public function test_the_queries_of_the_new_form_grow_only_with_the_principals(): void
    {
        [$b, $a1, $a2] = $this->people();
        $this->actingAs($b);
        $countOf = fn () => count($this->recordedSql(fn () => $this->get($this->formUrl())->assertOk()));
        $countOf();
        $none = $countOf();
        $this->addProxy($a1, $b, WorkflowProxyKind::Apply, $this->flowId);
        $one = $countOf();
        $this->addProxy($a2, $b, WorkflowProxyKind::Apply, $this->flowId);
        $two = $countOf();

        $this->assertLessThanOrEqual(self::QUERIES_PER_PRINCIPAL, $one - $none);
        $this->assertLessThanOrEqual(self::QUERIES_PER_PRINCIPAL, $two - $one);
    }

    /**
     * @return array{User, User, User} [代理先 B, 代理元 A1, 代理元 A2]
     */
    private function people(): array
    {
        return [$this->seededUser('u0002'), $this->seededUser('u0009'), $this->seededUser('u0011')];
    }

    private function allowCreate(User ...$users): void
    {
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Create, ...$users);
    }

    /**
     * @return list<int>
     */
    private function ascending(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, string>  $query
     * @return array{list<int>, int|null} [applicants の id, applicant]
     */
    private function choiceOf(User $user, array $query = []): array
    {
        $props = $this->propsOf($user, $query);

        return [array_column($props['applicants'], 'id'), $props['applicant']];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function propsOf(User $user, array $query = []): array
    {
        $this->actingAs($user);

        return $this->get($this->formUrl($query))->assertOk()->viewData('page')['props'];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function formUrl(array $query = []): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/apply'.($query === [] ? '' : '?'.http_build_query($query));
    }
}
