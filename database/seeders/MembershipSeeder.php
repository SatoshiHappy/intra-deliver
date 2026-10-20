<?php

namespace Database\Seeders;

use App\Actions\Master\AssignMembershipPost;
use App\Actions\Master\CreateDepartmentMembership;
use App\Models\Master\CompanyPost;
use App\Support\Period;
use Illuminate\Database\Seeder;

/**
 * 初期データ: 所属と所属役職。主所属を先に作る(主所属でない所属 = 兼務は、その期間に主所属があることが要る)。
 *
 * 承認の流れの見本 = 東京営業課の一般(u0001)→ 東京営業課の課長(u0002)→ 第一営業部の部長(u0003)→ 営業本部の本部長(u0006)。
 * 兼務 = 総務部長(u0009)が人事部長を兼ねる。異動 = u0013 が 2025-04-01 に開発部 → 第一営業部。
 * 昇格 = u0012 が 2025-04-01 に開発部の係長 → 課長(同じ組織の所属を隣り合う 2 行にし、後ろの行に新しい役職を付ける)。
 * 異動と昇格 = u0016 が 2025-04-01 に第一営業部の担当課長 → 新設の第二営業部の部長。
 * 退職 = u0014 は退職の日で終える。入社予定 = u0015 は入社の日から。
 */
class MembershipSeeder extends Seeder
{
    use FindsCommonMaster;

    private const START = OrganizationSeeder::START;

    private const REORGANIZATION = OrganizationSeeder::REORGANIZATION;

    /** 所属: [ユーザー, 組織, 開始日, 終了日(null = 上限なし), 主所属か, 役職(null = なし)] */
    private const MEMBERSHIPS = [
        ['u0001', 'tokyo-sales', self::START, null, true, null],
        ['u0002', 'tokyo-sales', self::START, null, true, 'section-chief'],
        ['u0003', 'sales-1', self::START, null, true, 'manager'],
        ['u0004', 'planning', self::START, null, true, 'senior'],
        ['u0005', 'deliver', self::START, null, true, 'president'],
        ['u0006', 'sales', self::START, null, true, 'division-head'],
        ['u0007', 'administration', self::START, null, true, 'division-head'],
        ['u0008', 'engineering', self::START, null, true, 'division-head'],
        ['u0009', 'general-affairs', self::START, null, true, 'manager'],
        ['u0009', 'human-resources', self::START, null, false, 'manager'],
        ['u0010', 'accounting', self::START, null, true, 'manager'],
        ['u0011', 'development', self::START, null, true, 'manager'],
        ['u0012', 'development', self::START, self::REORGANIZATION, true, 'chief'],
        ['u0012', 'development', self::REORGANIZATION, null, true, 'section-chief'],
        ['u0013', 'development', self::START, self::REORGANIZATION, true, null],
        ['u0013', 'sales-1', self::REORGANIZATION, null, true, null],
        ['u0014', 'accounting', self::START, UserSeeder::RETIREMENT, true, null],
        ['u0015', 'sales-2', UserSeeder::JOINING, null, true, null],
        ['u0016', 'sales-1', self::START, self::REORGANIZATION, true, 'assistant-manager'],
        ['u0016', 'sales-2', self::REORGANIZATION, null, true, 'manager'],
        ['u0017', 'quality-assurance', self::START, null, true, 'manager'],
    ];

    /** 役職の無い所属の、組織の中の並び順(役職のある所属はランクを並び順にする) */
    private const SORT_WITHOUT_POST = 99;

    public function run(CreateDepartmentMembership $createMembership, AssignMembershipPost $assignPost): void
    {
        foreach (self::MEMBERSHIPS as [$user, $department, $start, $end, $isMain, $post]) {
            $period = new Period(self::day($start), $end === null ? null : self::day($end));

            if ($post === null) {
                $createMembership->execute($this->userId($user), $this->departmentId($department), $period, $isMain, self::SORT_WITHOUT_POST);

                continue;
            }

            $membership = $createMembership->execute($this->userId($user), $this->departmentId($department), $period, $isMain, $this->rankOf($post));
            $assignPost->execute($membership->id, $this->companyPostId($post));
        }
    }

    private function rankOf(string $post): int
    {
        return CompanyPost::query()->where('code', $post)->sole()->rank;
    }
}
