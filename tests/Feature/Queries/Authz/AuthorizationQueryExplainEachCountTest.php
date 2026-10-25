<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\OverviewQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 判定と認可設定の格子の問い合わせの数がリソースグループの数で増えず、単独の判定はまとめた判定の 1 組と同じ本数であることを確かめる
 * (docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
 * Authz の外の変更で落ちないよう、上限は判定を求める者の事実(Master の問い合わせ)を組み立てた後の判定だけに掛け、格子は前後の差だけを比べる。
 */
final class AuthorizationQueryExplainEachCountTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    /** 1 回の explainEach の本数の上限(設定の読み込み 4 本・対象者と条件の相手の読み込み 3 本 = 7 本に余裕を持たせる) */
    private const CEILING = 10;

    private const DATE = '2026-10-22';

    private Requester $requester;

    private AuthzSubjectGroup $mine;

    private AuthzSubjectGroup $others;

    private AuthzResourceGroup $top;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->user();
        $this->requester = new Requester($user->id, null);
        $this->mine = $this->subjectGroup($user->id);
        $this->others = $this->subjectGroup($this->user()->id);
        $this->top = $this->resourceGroup(null);
        $this->policy($this->top, $this->mine, 'read', PolicyEffect::Permit);
        $this->policy($this->top, $this->others, 'admin', PolicyEffect::Deny);
    }

    public function test_one_call_stays_within_the_ceiling_and_does_not_grow_with_the_resource_groups(): void
    {
        $authorization = $this->warmed();
        $ids = $this->groupIds();
        $before = $this->queriesOf(fn () => $this->explainAll($authorization, $ids));
        $this->addFiveNestedGroups();
        $moreIds = $this->groupIds();
        $after = $this->queriesOf(fn () => $this->explainAll($authorization, $moreIds));

        $this->assertCount(count($ids) + 5, $moreIds);
        $this->assertSame($before, $after);
        $this->assertLessThanOrEqual(self::CEILING, $after);
    }

    public function test_a_single_explain_takes_as_many_queries_as_one_pair_of_explain_each(): void
    {
        $authorization = $this->warmed();
        $child = $this->resourceGroup($this->top);

        $date = self::day(self::DATE);
        $each = $this->queriesOf(fn () => $authorization->explainEach($this->requester, [$child->id], ['read'], $date));
        $alone = $this->queriesOf(fn () => $authorization->explain($this->requester, $child->id, 'read', $date));

        $this->assertSame($each, $alone);
        $this->assertLessThanOrEqual(self::CEILING, $alone);
    }

    public function test_the_grid_of_the_overview_does_not_grow_with_the_resource_groups(): void
    {
        $grid = fn () => app(OverviewQuery::class)
            ->gridOn($this->requester, 'im-menu-group', self::day(self::DATE), 'ja', true);
        $before = $this->queriesOf($grid);
        $this->addFiveNestedGroups();

        $this->assertSame($before, $this->queriesOf($grid));
    }

    /**
     * 入れ子の 5 グループを足す(どれもリソース・ポリシー・閉塞の値を持つ)。
     */
    private function addFiveNestedGroups(): void
    {
        $parent = $this->top;

        foreach (range(1, 5) as $index) {
            $parent = $this->resourceGroup($parent);
            $this->policy($parent, $index % 2 === 0 ? $this->mine : $this->others, 'read', PolicyEffect::Deny);
            AuthzResourceGroupAttribute::factory()->blocked('im-menu-group:admin')
                ->create(['authz_resource_group_id' => $parent->id]);
        }
    }

    /**
     * 判定を求める者の事実を組み立て済みの判定の口(同じインスタンスで数える)。
     */
    private function warmed(): AuthorizationQuery
    {
        $authorization = app(AuthorizationQuery::class);
        $authorization->explain($this->requester, $this->top->id, 'read', self::day(self::DATE));

        return $authorization;
    }

    /**
     * すべての組を 1 回の explainEach で判定する(リソースグループの id は呼ぶ前に読んでおく)。
     *
     * @param  list<int>  $resourceGroupIds
     */
    private function explainAll(AuthorizationQuery $authorization, array $resourceGroupIds): void
    {
        $authorization->explainEach($this->requester, $resourceGroupIds, ['read', 'admin'], self::day(self::DATE));
    }

    private function queriesOf(callable $call): int
    {
        $this->resetRequestScope();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * @return list<int>
     */
    private function groupIds(): array
    {
        return array_values(AuthzResourceGroup::query()->pluck('id')->all());
    }

    private function resourceGroup(?AuthzResourceGroup $parent): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create(['parent_id' => $parent?->id]);
        AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'im-menu-group']);

        return $group;
    }

    private function subjectGroup(int $userId): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => $userId]);

        return $group;
    }

    private function policy(
        AuthzResourceGroup $group,
        AuthzSubjectGroup $subject,
        string $action,
        PolicyEffect $effect,
    ): void {
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $group->id,
            'authz_subject_group_id' => $subject->id,
            'resource_type' => 'im-menu-group',
            'action' => $action,
            'effect' => $effect,
        ]);
    }
}
