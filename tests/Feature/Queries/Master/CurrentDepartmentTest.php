<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\User;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Master\MembershipQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * カレント組織の公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1、設計/デモユーザー切替/今の利用者.md 5.5: 候補は既定の組織セットの所属、
 * 選んだ組織が候補に無ければ主所属、無い・d に有効でないユーザー・候補の所属が無ければ null。MembershipQuery::currentMembershipOn と同じ結果)。
 * 初期データ(u0009 = 総務部が主所属・人事部を兼務、u0014 は 2025-10-01 に退職)で確かめる。
 */
final class CurrentDepartmentTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const TODAY = '2026-01-01';

    public function test_takes_the_selected_candidate_or_falls_back_to_the_main_membership(): void
    {
        $user = $this->userId('u0009');
        [$generalAffairs, $humanResources] = [$this->departmentId('general-affairs'), $this->departmentId('human-resources')];

        $this->assertSame($humanResources, $this->current($user, $humanResources));
        $this->assertSame($generalAffairs, $this->current($user, null));
        $this->assertSame($generalAffairs, $this->current($user, $this->departmentId('sales-1')));
        $this->assertSame($generalAffairs, $this->current($user, (int) Department::query()->max('id') + 1));
    }

    public function test_is_null_for_a_user_without_candidates_not_effective_or_missing(): void
    {
        $withoutMembership = $this->user();

        $this->assertNull($this->current($withoutMembership->id, null));
        $this->assertNull($this->current($this->userId('u0014'), $this->departmentId('accounting'), '2025-10-01'));
        $this->assertNull($this->current((int) User::query()->max('id') + 1, null));
    }

    public function test_gives_the_same_department_as_the_current_membership_for_every_seeded_user_and_selection(): void
    {
        $date = self::day(self::TODAY);
        $memberships = app(MembershipQuery::class);
        $selections = [null, ...Department::query()->orderBy('id')->pluck('id')->all()];

        foreach (User::query()->orderBy('id')->pluck('id')->all() as $userId) {
            foreach ($selections as $selected) {
                $this->assertSame(
                    $memberships->currentMembershipOn($userId, $selected, $date)?->department_id,
                    app(CurrentDepartment::class)->departmentIdOn($userId, $selected, $date),
                    "ユーザー {$userId}・選んだ組織 {$selected}",
                );
            }
        }
    }

    private function current(int $userId, ?int $selectedDepartmentId, string $date = self::TODAY): ?int
    {
        return app(CurrentDepartment::class)->departmentIdOn($userId, $selectedDepartmentId, self::day($date));
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
