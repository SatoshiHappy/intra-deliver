<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\EffectivePolicy;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の格子のセルと判定の口の一致(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節「Feature テスト」の判定の口との一致と、その入力。
 * 方針 3 = セルのたどり方が画面の読み取りと判定の口の 2 か所にあるのを止める検査): すべてのリソースタイプについて、判定のある行のアクションごとのセル
 * {(対象者, エフェクト, 置き場)} が、判定の口 explain の効いている設定のうち用途 policy の対象者のもの(置き場が行なら継承でない)と同じ。
 *
 * 入力 = 初期データ(ポリシーはすべて許可)に足す固定の木(メニューグループ im-menu-group の格子): 先頭 A に対象者 S1・S2 の read の許可、
 * A の子 B(リソースを持たない)に S1 の read の禁止(上書き)、B の子 C(リソースを持つ)は S1 の禁止を B から・S2 の許可を A から継承、
 * B の子 D(リソースを持つ)に S1 の read の許可を直接(子で戻す)。A に業務モジュール record-module の read で S1 の禁止(同じアクション ID で別のタイプ)。
 * C に用途 selection の対象者の read の許可(CreatePolicy が拒むので DB に直接。セルに出ない)。S1・S2・selection の対象者はどれも u0004 に合う。
 */
final class OverviewGridConsistencyTest extends TransactionalTestCase
{
    use ReadsAuthzGrid;

    private User $administrator;

    private int $s1;

    private int $s2;

    private int $selection;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->administrator = User::query()->where('code', 'u0004')->sole();
        $this->buildFixedTree();
    }

    public function test_the_fixed_tree_inherits_overrides_and_restores_per_subject(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL.'?type=im-menu-group');

        $this->assertSame([[$this->s1, 'permit', null], [$this->s2, 'permit', null]], $this->cells($this->row($response, 'grid-a'), 'read'));
        $this->assertSame([[$this->s1, 'deny', null], [$this->s2, 'permit', 'grid-a']], $this->cells($this->row($response, 'grid-a.b'), 'read'));
        $this->assertSame([[$this->s1, 'deny', 'grid-a.b'], [$this->s2, 'permit', 'grid-a']], $this->cells($this->row($response, 'grid-a.b.c'), 'read'));
        $this->assertSame([[$this->s1, 'permit', null], [$this->s2, 'permit', 'grid-a']], $this->cells($this->row($response, 'grid-a.b.d'), 'read'));
        $this->assertSame(['action' => 'read', 'result' => 'permit', 'decidingSubjectGroupIds' => [$this->s2, $this->selection]], $this->row($response, 'grid-a.b.c')['judgements'][0]);
        $this->assertNull($this->row($response, 'grid-a.b')['judgements']);
    }

    public function test_the_cells_of_every_judged_row_agree_with_the_explanation_for_every_type(): void
    {
        $this->actingAs($this->administrator);
        $resourceTypes = $this->props($this->get(self::URL))['resourceTypes'];

        foreach ($resourceTypes as $resourceType) {
            $grid = $this->grid($this->get(self::URL.'?type='.$resourceType));
            $judgedRows = array_filter($grid['rows'], fn (array $row) => $row['judgements'] !== null);
            $this->assertNotEmpty($judgedRows, "{$resourceType} の判定のある行");

            foreach ($judgedRows as $row) {
                $this->assertRowAgrees($row, $grid['actions']);
            }
        }
    }

    /**
     * 行のアクションごとに、セルの {(対象者, エフェクト, 置き場のコード)} が explain の効いている設定の用途 policy の対象者のものと同じ。
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $actions
     */
    private function assertRowAgrees(array $row, array $actions): void
    {
        $codes = AuthzResourceGroup::query()->pluck('code', 'id');
        $forPolicies = AuthzSubjectGroup::query()->where('purpose', SubjectGroupPurpose::Policy)->pluck('id')->all();

        foreach ($actions as $action) {
            $explanation = app(AuthorizationQuery::class)->explain(new Requester($this->administrator->id, null), $row['resourceGroupId'], $action, CarbonImmutable::now());
            $expected = array_map(
                fn (EffectivePolicy $policy) => "{$policy->subjectGroupId} {$policy->effect->value} {$codes->get($policy->resourceGroupId)}",
                array_filter($explanation->policies, fn (EffectivePolicy $policy) => in_array($policy->subjectGroupId, $forPolicies, true)),
            );
            $actual = array_map(fn (array $cell) => "{$cell[0]} {$cell[1]} ".($cell[2] ?? $row['code']), $this->cells($row, $action));
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "{$row['code']} {$action}");
        }
    }

    private function buildFixedTree(): void
    {
        $this->s1 = $this->subjectGroupForTheAdministrator('対象者 S1');
        $this->s2 = $this->subjectGroupForTheAdministrator('対象者 S2');
        $this->selection = app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition(SelectionConditionKind::User, $this->administrator->id)], ['ja' => '指定用の対象者']);
        $a = app(CreateResourceGroup::class)->execute('grid-a', null, ['ja' => ['display_name' => 'A']])->id;
        $b = app(CreateResourceGroup::class)->execute('grid-a.b', $a, ['ja' => ['display_name' => 'B']])->id;
        $c = app(CreateResource::class)->execute('grid-a.b.c', $b, 'im-menu-group', 'im-menu-group:grid-a.b.c', ['ja' => ['display_name' => 'C']])->authz_resource_group_id;
        $d = app(CreateResource::class)->execute('grid-a.b.d', $b, 'im-menu-group', 'im-menu-group:grid-a.b.d', ['ja' => ['display_name' => 'D']])->authz_resource_group_id;
        $createPolicy = app(CreatePolicy::class);
        $createPolicy->execute($a, $this->s1, 'im-menu-group', 'read', PolicyEffect::Permit);
        $createPolicy->execute($a, $this->s2, 'im-menu-group', 'read', PolicyEffect::Permit);
        $createPolicy->execute($b, $this->s1, 'im-menu-group', 'read', PolicyEffect::Deny);
        $createPolicy->execute($d, $this->s1, 'im-menu-group', 'read', PolicyEffect::Permit);
        $createPolicy->execute($a, $this->s1, 'record-module', 'read', PolicyEffect::Deny);
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $c,
            'authz_subject_group_id' => $this->selection,
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => PolicyEffect::Permit,
        ]);
    }

    /**
     * u0004 に合う、用途 policy の対象者(ユーザーの条件 1 つ)。
     */
    private function subjectGroupForTheAdministrator(string $name): int
    {
        return app(CreateSubjectGroup::class)->execute(SubjectCombination::Any, [new SubjectConditionInput(userId: $this->administrator->id)], ['ja' => ['display_name' => $name]])->id;
    }
}
