<?php

namespace App\Actions\Master;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Queries\Master\EffectivePeriodQuery;
use App\Support\Period;
use Illuminate\Validation\ValidationException;

/**
 * 所属に役職を付ける(組織所属役職。docs の 設計/共通マスタ/組織とユーザー.md 8 節)。所属役職は所属の期間と無効の旗に従う(連動する子)。
 *
 * 止める規則: 所属が d に有効なら、その所属役職の役職も d に有効(共通の規則 5.5)。無効の所属は有効な日を持たないので、役職を問わない
 * (所属を有効に戻すときに確かめる = 期間を変える業務処理)。役職が所属と同じ組織セット・同じ役職を 2 回付けないは DB が止める。
 */
class AssignMembershipPost
{
    use WritesCommonMaster;

    public function __construct(private EffectivePeriodQuery $effectivePeriods) {}

    /**
     * @throws ValidationException 規則に反するとき
     */
    public function execute(int $departmentMembershipId, int $companyPostId): DepartmentMembershipPost
    {
        return $this->writeCommonMaster(function () use ($departmentMembershipId, $companyPostId) {
            $membership = DepartmentMembership::query()->findOrFail($departmentMembershipId);
            $membershipPeriod = $membership->effectivePeriod();

            if ($membershipPeriod !== null && ! Period::covers($this->effectivePeriods->ofCompanyPost($companyPostId), $membershipPeriod)) {
                throw ValidationException::withMessages(['company_post_id' => ['役職が所属の期間の全体で有効ではありません']]);
            }

            return DepartmentMembershipPost::query()->create([
                'department_set_id' => $membership->department_set_id,
                'department_membership_id' => $membership->id,
                'company_post_id' => $companyPostId,
            ]);
        });
    }
}
