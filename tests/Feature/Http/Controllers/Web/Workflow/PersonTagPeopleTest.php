<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Master\Department;
use App\Models\Master\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 名前の札に出す人が持つ主所属の組織の id(札の「組織図で見る」の行き先 = docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1。主所属の意味は
 * 共通マスタの口 MasterNames::mainDepartments)。主所属のある人はその組織の id、無い人は null。初期データの u0002(中村 美咲)の主所属は東京営業課。
 * 初期データを入れ、ルートの認可を外さずに開く。
 */
final class PersonTagPeopleTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use BuildsCommonMaster;
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 申請の画面の次の処理担当者・申請の詳細のフロー情報の処理担当者・申請操作の面の今の処理担当者。
     */
    public function test_the_people_on_the_pages_carry_their_main_department(): void
    {
        $tokyoSales = Department::query()->where('code', 'tokyo-sales')->sole()->id;
        $u0002 = $this->tagPerson('u0002', '中村 美咲', $tokyoSales);
        $case = $this->applyOnBranch('u0001', 350);

        $this->actingAsUser('u0001');
        $this->get('/workflow/flows/project_plan/apply')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('next.nodes.0.targets', [$u0002]));

        $this->actingAsUser('u0004');
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodes', fn (Collection $nodes) => $nodes->firstWhere('code', 'section_chief')['targets']
                === [[...$u0002, 'isMe' => false]])
            ->where('operation.retargetNodes.0.targets', [$u0002]));
    }

    /**
     * 合議の進み具合の処理担当者の行(初期データの WF-000009 の縦配置の子。u0009 の主所属は総務部、u0010 は経理部)。所属は組織の表示のまま。
     */
    public function test_the_council_rows_carry_the_main_department_of_the_person(): void
    {
        $departmentId = fn (string $code) => Department::query()->where('code', $code)->sole()->id;
        $expected = [
            [...$this->tagPerson('u0009', '吉田 達也', $departmentId('general-affairs')), 'isMe' => false],
            [...$this->tagPerson('u0010', '山田 恵', $departmentId('accounting')), 'isMe' => true],
        ];
        $this->actingAsUser('u0010');

        $this->get('/workflow/cases/'.$this->caseNumbered(9))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('councils.0.rows', fn (Collection $rows) => $rows->pluck('person')->take(2)->all() === $expected));
    }

    /**
     * 処理済みのステップの処理者と処理実行者(初期データの WF-000015 = u0009 が u0010 の代理で申請。u0010 の主所属は経理部、u0009 は総務部)。
     * 図の人の並びも処理者の札。
     */
    public function test_the_processor_and_the_executor_of_a_processed_step_carry_their_main_department(): void
    {
        $departmentId = fn (string $code) => Department::query()->where('code', $code)->sole()->id;
        $u0010 = $this->tagPerson('u0010', '山田 恵', $departmentId('accounting'));
        $u0009 = $this->tagPerson('u0009', '吉田 達也', $departmentId('general-affairs'));
        $this->actingAsUser('u0010');

        $this->get('/workflow/cases/'.$this->caseNumbered(15))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodes', function (Collection $nodes) use ($u0010, $u0009) {
                $apply = $nodes->firstWhere('kind', 'apply');

                return [$apply['processed']['actor'], $apply['processed']['executor'], $apply['people']]
                    === [$u0010, $u0009, [[...$u0010, 'isMe' => true]]];
            }));
    }

    /**
     * 人の候補: 主所属のある人はその組織の id、主所属の無い人は null。
     */
    public function test_a_candidate_without_a_main_department_has_null(): void
    {
        $without = $this->user();
        $this->actingAsUser('u0001');

        $users = $this->getJson('/workflow/user-candidates?q='.urlencode($without->code))->assertOk()->json('users');

        $departments = array_map(fn (array $user) => [$user['department'], $user['departmentId']], $users);

        $this->assertSame([[null, null]], $departments);
    }

    /**
     * @return array{id: int, code: string, name: string, mainDepartmentId: int}
     */
    private function tagPerson(string $code, string $name, int $departmentId): array
    {
        $id = User::query()->where('code', $code)->sole()->id;

        return ['id' => $id, 'code' => $code, 'name' => $name, 'mainDepartmentId' => $departmentId];
    }
}
