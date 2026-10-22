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
use App\Queries\Authz\AuthorizationExplanation;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\EffectivePolicy;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組をまとめた判定 explainEach(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1・3.2): 2 つのリソースタイプの組を
 * 1 回の呼び出しに混ぜても、組ごとの結果と効いている設定(順は問わない)が固定の期待値どおりで、単独の explain も同じ期待値になる。
 *
 * 木(リソースタイプ im-menu-group。module だけ record-module): far > mid(リソースを持たない)> near・midLeaf、
 * far > blockedX(前方・後方に字が付くだけの閉塞の値)・blockedExact(admin だけ閉塞)・module、別の先頭 blockedAll(ALL)、
 * 互いを親にする cycleA・cycleB(業務処理が止める循環が残ったとき)。mine の read は far 許可 > mid 禁止 > near 許可、
 * admin は far 禁止 > mid 許可(近い・中間・遠いで効果を違える)。対象者 = 利用者に合う mine、合わない others、
 * 期間 [2026-01-01, 2027-01-01) の d にだけ合う termed。
 */
final class AuthorizationQueryExplainEachTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const IN_TERM = '2026-10-22';

    private const AFTER_TERM = '2027-06-01';

    private const ACTIONS = ['read', 'admin', 'update'];

    private int $userId;

    /** @var array<string, AuthzSubjectGroup> */
    private array $subjects = [];

    /** @var array<string, AuthzResourceGroup> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->user()->id;
        $this->subjects['mine'] = $this->subjectGroup(['user_id' => $this->userId]);
        $this->subjects['others'] = $this->subjectGroup(['user_id' => $this->user()->id]);
        $this->subjects['termed'] = $this->subjectGroup([
            'user_id' => null,
            'term_start_date' => '2026-01-01',
            'term_end_date' => '2027-01-01',
        ]);
        $this->buildTree();
    }

    public function test_one_call_over_two_resource_types_gives_the_fixed_results_and_settings_in_the_term(): void
    {
        $this->assertExplained(self::IN_TERM, $this->expectedInTerm());
    }

    public function test_one_call_over_two_resource_types_gives_the_fixed_results_and_settings_after_the_term(): void
    {
        $termedOff = fn (string $policy): string => $policy === 'termed permit far +' ? 'termed permit far -' : $policy;
        $expected = array_map(fn (array $byAction) => array_map(
            fn (array $pair): array => [$pair[0], array_map($termedOff, $pair[1])],
            $byAction,
        ), $this->expectedInTerm());
        $expected['far']['admin'] = ['Deny', $expected['far']['admin'][1]];
        $expected['blockedX']['admin'] = ['Deny', $expected['blockedX']['admin'][1]];

        $this->assertExplained(self::AFTER_TERM, $expected);
    }

    /**
     * 期待値(コード → アクション → [結果, 効いている設定 = 「対象者 効果 置き場 合うか」])。missing = 存在しないリソースグループ。
     *
     * @return array<string, array<string, array{string, list<string>}>>
     */
    private function expectedInTerm(): array
    {
        $none = ['read' => ['Deny', []], 'admin' => ['Deny', []], 'update' => ['Deny', []]];
        $farRead = ['Permit', ['mine permit far +', 'others permit far -']];
        $farAdmin = ['Permit', ['mine deny far +', 'termed permit far +']];

        return [
            'far' => ['read' => $farRead, 'admin' => $farAdmin, 'update' => ['Deny', []]],
            'mid' => $none,
            'near' => [
                'read' => ['Permit', ['mine permit near +', 'others permit far -']],
                'admin' => ['Permit', ['mine permit mid +', 'termed permit far +']],
                'update' => ['Deny', []],
            ],
            'midLeaf' => [
                'read' => ['Deny', ['mine deny mid +', 'others permit far -']],
                'admin' => ['Permit', ['mine permit mid +', 'termed permit far +']],
                'update' => ['Deny', []],
            ],
            'blockedX' => ['read' => $farRead, 'admin' => $farAdmin, 'update' => ['Deny', []]],
            'blockedExact' => ['read' => $farRead, 'admin' => ['Block', $farAdmin[1]], 'update' => ['Deny', []]],
            'blockedAll' => [
                'read' => ['Block', ['mine permit blockedAll +']],
                'admin' => ['Block', []],
                'update' => ['Block', []],
            ],
            'cycleA' => [
                'read' => ['Permit', ['mine permit cycleA +']],
                'admin' => ['Deny', ['others permit cycleB -']],
                'update' => ['Deny', []],
            ],
            'cycleB' => [
                'read' => ['Deny', ['mine deny cycleB +']],
                'admin' => ['Deny', ['others permit cycleB -']],
                'update' => ['Deny', []],
            ],
            'module' => [
                'read' => ['Deny', ['mine deny far +', 'others permit module -']],
                'admin' => ['Deny', []],
                'update' => ['Permit', ['mine permit module +']],
            ],
            'missing' => $none,
        ];
    }

    /**
     * すべての組を 1 回の explainEach に渡した結果と、組ごとの単独の explain が、どちらも期待値どおり。
     *
     * @param  array<string, array<string, array{string, list<string>}>>  $expected
     */
    private function assertExplained(string $date, array $expected): void
    {
        $ids = array_map(fn (string $code) => $this->idOf($code), array_keys($expected));
        $requester = new Requester($this->userId, null);
        $each = app(AuthorizationQuery::class)->explainEach($requester, $ids, self::ACTIONS, self::day($date));

        foreach ($expected as $code => $byAction) {
            $id = $this->idOf($code);

            foreach ($byAction as $action => [$result, $policies]) {
                $alone = app(AuthorizationQuery::class)->explain($requester, $id, $action, self::day($date));

                foreach (['explainEach' => $each[$id][$action], 'explain' => $alone] as $route => $explanation) {
                    $message = "{$route} {$code} {$action} {$date}";
                    $this->assertSame($result, $explanation->result->name, $message);
                    $this->assertEqualsCanonicalizing($policies, $this->summaries($explanation), $message);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function summaries(AuthorizationExplanation $explanation): array
    {
        $subjectNames = array_flip(array_map(fn (AuthzSubjectGroup $group) => $group->id, $this->subjects));
        $groupCodes = array_flip(array_map(fn (AuthzResourceGroup $group) => $group->id, $this->groups));

        return array_map(fn (EffectivePolicy $policy) => implode(' ', [
            $subjectNames[$policy->subjectGroupId],
            $policy->effect->value,
            $groupCodes[$policy->resourceGroupId],
            $policy->matches ? '+' : '-',
        ]), $explanation->policies);
    }

    private function idOf(string $code): int
    {
        return $code === 'missing' ? (int) AuthzResourceGroup::query()->max('id') + 1 : $this->groups[$code]->id;
    }

    private function buildTree(): void
    {
        $this->resourceGroup('far', null, 'im-menu-group');
        $this->resourceGroup('mid', 'far', null);
        $this->resourceGroup('near', 'mid', 'im-menu-group');
        $this->resourceGroup('midLeaf', 'mid', 'im-menu-group');
        $onlyLookAlike = 'x-im-menu-group:read,im-menu-group:read2,im-menu-group:admi';
        $this->resourceGroup('blockedX', 'far', 'im-menu-group', $onlyLookAlike);
        $this->resourceGroup('blockedExact', 'far', 'im-menu-group', 'im-menu-group:admin');
        $this->resourceGroup('blockedAll', null, 'im-menu-group', 'ALL');
        $this->resourceGroup('module', 'far', 'record-module');
        $this->resourceGroup('cycleA', null, 'im-menu-group');
        $this->resourceGroup('cycleB', 'cycleA', 'im-menu-group');
        $this->groups['cycleA']->update(['parent_id' => $this->groups['cycleB']->id]);

        foreach ([
            ['far', 'mine', 'read', 'permit'], ['far', 'others', 'read', 'permit'],
            ['far', 'mine', 'admin', 'deny'], ['far', 'termed', 'admin', 'permit'],
            ['mid', 'mine', 'read', 'deny'], ['mid', 'mine', 'admin', 'permit'],
            ['near', 'mine', 'read', 'permit'], ['blockedAll', 'mine', 'read', 'permit'],
            ['cycleA', 'mine', 'read', 'permit'], ['cycleB', 'mine', 'read', 'deny'],
            ['cycleB', 'others', 'admin', 'permit'],
        ] as [$group, $subject, $action, $effect]) {
            $this->policy($group, $subject, 'im-menu-group', $action, $effect);
        }

        $this->policy('far', 'mine', 'record-module', 'read', 'deny');
        $this->policy('module', 'others', 'record-module', 'read', 'permit');
        $this->policy('module', 'mine', 'record-module', 'update', 'permit');
    }

    private function resourceGroup(string $code, ?string $parent, ?string $resourceType, ?string $blocked = null): void
    {
        $parentId = $parent === null ? null : $this->groups[$parent]->id;
        $group = $this->groups[$code] = AuthzResourceGroup::factory()->create(['parent_id' => $parentId]);
        $ofGroup = ['authz_resource_group_id' => $group->id];

        if ($resourceType !== null) {
            AuthzResource::factory()->create([...$ofGroup, 'resource_type' => $resourceType]);
        }

        if ($blocked !== null) {
            AuthzResourceGroupAttribute::factory()->blocked($blocked)->create($ofGroup);
        }
    }

    /**
     * 条件 1 つ(any)の対象者。
     *
     * @param  array<string, mixed>  $condition
     */
    private function subjectGroup(array $condition): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, ...$condition]);

        return $group;
    }

    private function policy(string $group, string $subject, string $resourceType, string $action, string $effect): void
    {
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $this->groups[$group]->id,
            'authz_subject_group_id' => $this->subjects[$subject]->id,
            'resource_type' => $resourceType,
            'action' => $action,
            'effect' => PolicyEffect::from($effect),
        ]);
    }
}
