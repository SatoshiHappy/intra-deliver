<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Queries\Authz\SubjectGroupPolicyFingerprints;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の編集の props(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.3・8 節の props): can の 3 つ、列の builtin・件数・指紋
 * (列を削除できない人には null)、セルの切替の後の部分再読み込み。初期データ(システム管理者 = u0004)を入れ、今日を 2026-10-10 に固定する。
 * システム管理者でない人には、u0001 の列に許可・禁止を置いて開かせる。
 */
final class OverviewEditPropsTest extends TransactionalTestCase
{
    use EditsAuthz;

    private const SUBJECTS_URL = '/authz/editor/subjects';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_the_administrator_can_do_every_edit_and_gets_the_fingerprints(): void
    {
        $props = $this->propsAs('u0004');

        $this->assertSame(['editCells' => true, 'addSubject' => true, 'deleteSubject' => true], $props['can']);
        $subjects = $props['grid']['subjects'];
        $this->assertSame(
            [[$this->guestColumn(), true], [$this->authenticatedColumn(), true], [$this->administratorColumn(), true]],
            array_map(fn (array $subject) => [$subject['id'], $subject['builtin']], $subjects),
        );
        $fingerprints = app(SubjectGroupPolicyFingerprints::class)->of(array_column($subjects, 'id'));
        foreach ($subjects as $subject) {
            $inProps = ['policyCount' => $subject['policyCount'], 'fingerprint' => $subject['fingerprint']];
            $this->assertSame($fingerprints[$subject['id']], $inProps);
        }
    }

    public function test_someone_who_may_only_open_can_do_nothing_and_gets_no_fingerprints(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.authz.overview', $column, PolicyEffect::Permit);

        $props = $this->propsAs('u0001');

        $this->assertSame(['editCells' => false, 'addSubject' => false, 'deleteSubject' => false], $props['can']);
        $this->assertSame([true, true, true, false], array_column($props['grid']['subjects'], 'builtin'));
        $this->assertSame([null], array_values(array_unique(array_column($props['grid']['subjects'], 'fingerprint'))));
        $this->assertSame([null], array_values(array_unique(array_column($props['grid']['subjects'], 'policyCount'))));
    }

    public function test_someone_who_may_open_and_save_cells_can_only_edit_cells(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.authz.overview', $column, PolicyEffect::Permit);
        $this->place('http-services.authz.editor.cells.update', $column, PolicyEffect::Permit);

        $this->assertSame(
            ['editCells' => true, 'addSubject' => false, 'deleteSubject' => false],
            $this->propsAs('u0001')['can'],
        );
    }

    public function test_adding_needs_the_candidates_and_deleting_needs_saving_cells(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.authz.overview', $column, PolicyEffect::Permit);
        $this->place('http-services.authz.editor', $column, PolicyEffect::Permit);
        $this->place('http-services.authz.editor.subject-candidates', $column, PolicyEffect::Deny);

        $this->assertSame(
            ['editCells' => true, 'addSubject' => false, 'deleteSubject' => true],
            $this->propsAs('u0001')['can'],
        );

        $this->place('http-services.authz.editor.cells.update', $column, PolicyEffect::Deny);
        $props = $this->propsAs('u0001');

        $this->assertSame(['editCells' => false, 'addSubject' => false, 'deleteSubject' => false], $props['can']);
        $this->assertSame([null], array_values(array_unique(array_column($props['grid']['subjects'], 'fingerprint'))));
    }

    /**
     * セルの切替の後の部分再読み込み(only = grid・judge・can)でも can を取り直し、ほかの props は作らない。
     */
    public function test_a_partial_reload_after_a_cell_change_rebuilds_can(): void
    {
        $this->actingAs($this->user('u0004'));
        $this->changeCell('http-services.master.search', $this->userColumn('u0001'), 'none', 'permit')
            ->assertSessionHasNoErrors();

        $this->get(self::OVERVIEW_URL)->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['grid', 'judge', 'can'], fn (Assert $reload) => $reload
                ->where('can.editCells', true)
                ->missing('candidates')
                ->missing('resourceTypes')
                ->whereType('grid.subjects.3.fingerprint', 'string')
            )
        );
    }

    public function test_the_fingerprint_in_the_props_is_accepted_by_the_deletion(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.master.search', $column, PolicyEffect::Deny);
        $subjects = $this->propsAs('u0004')['grid']['subjects'];
        $subject = $subjects[array_search($column, array_column($subjects, 'id'), true)];
        $seen = ['count' => $subject['policyCount'], 'fingerprint' => $subject['fingerprint']];

        $this->from(self::OVERVIEW_URL)->delete(self::SUBJECTS_URL."/{$column}", $seen)->assertSessionHasNoErrors();

        $this->assertSame([1, false], [$subject['policyCount'], $subject['builtin']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsAs(string $userCode): array
    {
        return $this->actingAs($this->user($userCode))->get(self::OVERVIEW_URL)->assertOk()->viewData('page')['props'];
    }
}
