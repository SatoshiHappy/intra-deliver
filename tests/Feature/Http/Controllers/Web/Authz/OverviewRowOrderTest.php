<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の格子の行の集合と並び(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1 の「行」「行の並び」)。
 * ほかの props は {@see OverviewControllerTest}。初期データを入れ、システム管理者(u0004)で開く(日付は OverviewControllerTest と同じに固定)。
 */
final class OverviewRowOrderTest extends TransactionalTestCase
{
    use ReadsAuthzGrid;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->administrator = User::query()->where('code', 'u0004')->sole();
    }

    public function test_the_rows_are_the_holders_of_the_type_and_their_ancestors_in_depth_first_order(): void
    {
        $root = $this->resourceGroup('zz-root', null, 1000, false);
        $this->resourceGroup('zz-root.no-holder', $root, 0, false);
        $this->resourceGroup('zz-root.b', $root, 1, true);
        $this->resourceGroup('zz-root.a', $root, 2, true);
        $this->resourceGroup('zz-root.d', $root, 3, true);
        $this->resourceGroup('zz-root.c', $root, 3, true);
        $this->resourceGroup('zz-root.f.g', $this->resourceGroup('zz-root.f', $root, 4, false), 0, true);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame(
            ['http-services', 0, null, null],
            [$rows[0]['code'], $rows[0]['depth'], $rows[0]['parentId'], $rows[0]['judgements']],
        );
        $this->assertSame(
            ['zz-root', 'zz-root.f', 'zz-root.f.g', 'zz-root.b', 'zz-root.a', 'zz-root.c', 'zz-root.d'],
            $this->codesStartingWith($rows, 'zz-root'),
            'リソースを持つ行と祖先だけ。兄弟は子を持つ行が先、それぞれの中は sort_key・code の順',
        );
        $this->assertDepthFirst($rows);
        $this->assertLeavesAreJudged($rows);
        $this->assertSiblingOrder($rows);

        foreach ($rows as $row) {
            $this->assertSame(
                ['resourceGroupId', 'parentId', 'depth', 'code', 'name', 'description', 'settings', 'judgements'],
                array_keys($row),
            );
        }
    }

    /**
     * 初期データの画面・処理の格子: 先頭の下は、上のフォルダ(認可・基本データ・業務データ・ワークフロー)が登録した順。束は子を持たない
     * (ルートの行は出ない)。フォルダ「基本データ」の子は束「組織図」だけ。設定不可のセットは出ない。
     */
    public function test_in_the_seeded_data_the_folders_come_in_registration_order_and_bundles_have_no_children(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL);
        $rows = $this->grid($response)['rows'];
        $id = fn (string $code) => $this->row($response, $code)['resourceGroupId'];

        $this->assertSame(
            ['http-services.authz', 'http-services.master', 'http-services.records', 'http-services.workflow'],
            $this->childCodes($rows, $id('http-services')),
        );
        $this->assertSame(['http-services.authz.settings'], $this->childCodes($rows, $id('http-services.authz')));
        $chart = 'http-services.master.organization-chart';
        $this->assertSame([$chart], $this->childCodes($rows, $id('http-services.master')));
        $this->assertSame([], $this->childCodes($rows, $id('http-services.authz.settings')));
        $this->assertSame([], $this->childCodes($rows, $id($chart)));
        $this->assertSiblingOrder($rows);
    }

    public function test_siblings_with_children_in_the_grid_come_first_each_in_the_order_of_sort_key_and_code(): void
    {
        $root = $this->resourceGroup('zz-o', null, 1000, false);
        $other = $this->resourceGroup('zz-o.other', $root, -1, true);
        $this->typedGroup('zz-o.other.menu', $other, 'im-menu-group');
        $this->resourceGroup('zz-o.leaf-a', $root, 0, true);
        $this->resourceGroup('zz-o.fb.x', $this->resourceGroup('zz-o.fb', $root, 1, false), 0, true);
        $this->resourceGroup('zz-o.leaf-c', $root, 2, true);
        $folderA = $this->resourceGroup('zz-o.fa', $root, 3, false);
        $this->resourceGroup('zz-o.fa.y', $folderA, 0, true);
        $this->resourceGroup('zz-o.fa.x', $folderA, 0, true);
        $folderC = $this->resourceGroup('zz-o.fc', $root, 3, false);
        $this->resourceGroup('zz-o.fc.q.1', $this->resourceGroup('zz-o.fc.q', $folderC, 0, false), 0, true);
        $this->resourceGroup('zz-o.fc.p.1', $this->resourceGroup('zz-o.fc.p', $folderC, 0, false), 0, true);
        $withPolicy = $this->resourceGroup('zz-o.policy', $root, 5, true);
        $this->servicePolicyOn($this->typedGroup('zz-o.policy.menu', $withPolicy, 'im-menu-group'));

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame([
            'zz-o',
            'zz-o.fb', 'zz-o.fb.x',
            'zz-o.fa', 'zz-o.fa.x', 'zz-o.fa.y',
            'zz-o.fc', 'zz-o.fc.p', 'zz-o.fc.p.1', 'zz-o.fc.q', 'zz-o.fc.q.1',
            'zz-o.policy', 'zz-o.policy.menu',
            'zz-o.other', 'zz-o.leaf-a', 'zz-o.leaf-c',
        ], $this->codesStartingWith($rows, 'zz-o'), '別の種類のリソースだけを持つ子は行にならず、親の判定に数えない');
        $this->assertSiblingOrder($rows);
    }

    public function test_among_the_top_groups_the_one_with_children_comes_first(): void
    {
        $this->resourceGroup('zz-top-leaf', null, -1000, true);
        $this->resourceGroup('zz-top-folder.child', $this->resourceGroup('zz-top-folder', null, 1000, true), 0, true);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame(
            ['http-services', 'zz-top-folder', 'zz-top-leaf'],
            $this->childCodes($rows, null),
        );
        $this->assertSiblingOrder($rows);
    }

    public function test_a_row_only_with_a_policy_of_the_type_makes_its_parent_a_folder(): void
    {
        $root = $this->resourceGroup('zz-p', null, 1000, false);
        $this->resourceGroup('zz-p.leaf', $root, -1, true);
        $parent = $this->resourceGroup('zz-p.parent', $root, 0, false);
        $this->servicePolicyOn($this->resourceGroup('zz-p.parent.policy', $parent, 0, false));

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $codes = $this->codesStartingWith($rows, 'zz-p');

        $this->assertSame(['zz-p', 'zz-p.parent', 'zz-p.parent.policy', 'zz-p.leaf'], $codes);
    }

    public function test_the_menu_group_grid_puts_the_categories_with_children_first(): void
    {
        $set = AuthzResourceGroup::query()->where('code', 'im-menu-group')->sole();
        $this->typedGroup('zz-menu-leaf', $set, 'im-menu-group', -1000);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL.'?type=im-menu-group'))['rows'];
        $children = $this->childCodes($rows, $set->id);

        $this->assertContains('zz-menu-leaf', $children);
        $this->assertNotSame('zz-menu-leaf', $children[0], '並び順が最小でも、子を持つカテゴリより後');
        $this->assertSiblingOrder($rows);
    }

    public function test_siblings_with_all_digit_codes_are_in_the_order_of_the_database(): void
    {
        $root = $this->resourceGroup('zz-digits', null, 0, false);
        $this->resourceGroup('2', $root, 0, true);
        $this->resourceGroup('10', $root, 0, true);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame(
            ['zz-digits', '10', '2'],
            array_values(array_filter(
                array_column($rows, 'code'),
                fn (string $code) => in_array($code, ['zz-digits', '2', '10'], true),
            )),
            '同じ sort_key の数字だけのコードは、数値でなく DB の文字列の順(10 → 2)',
        );
    }

    /**
     * 行が深さ優先(先頭から下へ。親の行は直前の行かその祖先)で、parentId が親のリソースグループ、depth が先頭からの深さ。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertDepthFirst(array $rows): void
    {
        $parentIds = AuthzResourceGroup::query()->pluck('parent_id', 'id');
        $path = [];

        foreach ($rows as $row) {
            while ($path !== [] && end($path) !== $row['parentId']) {
                array_pop($path);
            }

            $this->assertSame(
                [
                    $parentIds->get($row['resourceGroupId']),
                    $row['parentId'] === null ? [] : [$row['parentId']],
                    count($path),
                ],
                [$row['parentId'], array_slice($path, -1), $row['depth']],
                "{$row['code']} の親と深さ",
            );
            $path[] = $row['resourceGroupId'];
        }
    }

    /**
     * 葉の行(子の行が無い行)は、どれも判定がある(格子のタイプのリソースを持つ行か、ルートの束。持たない葉が混ざらない)。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertLeavesAreJudged(array $rows): void
    {
        $parentIds = array_column($rows, 'parentId');

        foreach ($rows as $row) {
            if (! in_array($row['resourceGroupId'], $parentIds, true)) {
                $this->assertNotNull($row['judgements'], "{$row['code']} は子の行が無いのに判定が無い");
            }
        }
    }

    /**
     * 兄弟(同じ親の行。先頭どうしを含む)の並びが、この格子の行の中で子を持つ行が先、それぞれの中は sort_key・code。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertSiblingOrder(array $rows): void
    {
        $sortKeys = AuthzResourceGroup::query()->pluck('sort_key', 'id');
        $parentIds = array_column($rows, 'parentId');

        foreach (collect($rows)->groupBy(fn (array $row) => (string) $row['parentId']) as $siblings) {
            $keys = $siblings->map(fn (array $row) => [
                in_array($row['resourceGroupId'], $parentIds, true) ? 0 : 1,
                $sortKeys->get($row['resourceGroupId']),
                $row['code'],
            ])->all();
            $sorted = $keys;
            usort($sorted, fn (array $a, array $b) => $a <=> $b);
            $this->assertSame($sorted, $keys);
        }
    }

    /**
     * リソースグループ(holdsService = 画面・処理のリソースを 1 つ持つ)。
     */
    private function resourceGroup(
        string $code,
        ?AuthzResourceGroup $parent,
        int $sortKey,
        bool $holdsService,
    ): AuthzResourceGroup {
        $group = AuthzResourceGroup::factory()
            ->create(['code' => $code, 'parent_id' => $parent?->id, 'sort_key' => $sortKey]);

        if ($holdsService) {
            AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'service']);
        }

        return $group;
    }

    /**
     * リソースタイプ $type のリソースを 1 つ持つリソースグループ。
     */
    private function typedGroup(
        string $code,
        AuthzResourceGroup $parent,
        string $type,
        int $sort = 0,
    ): AuthzResourceGroup {
        $group = $this->resourceGroup($code, $parent, $sort, false);
        AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => $type]);

        return $group;
    }

    /**
     * リソースグループに、画面・処理のポリシー(実行の許可)を 1 つ置く。
     */
    private function servicePolicyOn(AuthzResourceGroup $group): void
    {
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $group->id,
            'resource_type' => 'service',
            'action' => 'execute',
        ]);
    }

    /**
     * 行のコードのうち $prefix で始まるもの(並びのまま)。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function codesStartingWith(array $rows, string $prefix): array
    {
        $codes = array_column($rows, 'code');

        return array_values(array_filter($codes, fn (string $code) => str_starts_with($code, $prefix)));
    }

    /**
     * 親の行が $parentId の行のコード(並びのまま。null = 先頭どうし)。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function childCodes(array $rows, ?int $parentId): array
    {
        $children = array_filter($rows, fn (array $row) => $row['parentId'] === $parentId);

        return array_column(array_values($children), 'code');
    }
}
