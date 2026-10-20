<?php

namespace Database\Seeders;

use App\Actions\Authz\AddSubRole;
use App\Actions\Authz\CreateRole;
use App\Actions\Authz\GrantRole;
use App\Models\Master\User;
use App\Support\Period;
use Illuminate\Database\Seeder;

/**
 * 初期データ: ロール・サブロール・付与(認可のリソースとポリシーはメニューの単位で入れる)。
 *
 * サブロール = システム管理者 ⊃ 共通マスタ管理者、ワークフロー管理者 ⊃ 一般利用者(親ロールを持つ人は子ロールも持つ)。
 * 全員に一般利用者を既定の期間(登録日 = ユーザーの開始日から、ユーザーが続けて有効な間)で付ける。退職者の付与は退職の日で切れる。
 * ワークフロー管理者の 1 人は 2026-03-31 で終える(終了日 2026-04-01 = その日を含まない)。
 */
class RoleSeeder extends Seeder
{
    use FindsCommonMaster;

    /** ロール: コード => [ロール名, 表示名(ja), 表示名(en), カテゴリ, 並び順] */
    private const ROLES = [
        'system_admin' => ['system_admin', 'システム管理者', 'System administrator', 'administration', 1],
        'master_admin' => ['master_admin', '共通マスタ管理者', 'Master data administrator', 'administration', 2],
        'workflow_admin' => ['workflow_admin', 'ワークフロー管理者', 'Workflow administrator', 'workflow', 3],
        'general_user' => ['general_user', '一般利用者', 'General user', 'basic', 4],
    ];

    /** サブロール: [親, 子] */
    private const SUB_ROLES = [
        ['system_admin', 'master_admin'],
        ['workflow_admin', 'general_user'],
    ];

    /** 一般利用者のほかの付与: [ユーザー, ロール, 開始日, 終了日(null = 上限なし)] */
    private const GRANTS = [
        ['u0004', 'system_admin', OrganizationSeeder::START, null],
        ['u0007', 'master_admin', OrganizationSeeder::START, null],
        ['u0009', 'workflow_admin', OrganizationSeeder::START, '2026-04-01'],
        ['u0002', 'workflow_admin', OrganizationSeeder::START, null],
    ];

    public function run(CreateRole $createRole, AddSubRole $addSubRole, GrantRole $grantRole): void
    {
        foreach (self::ROLES as $code => [$name, $ja, $en, $category, $sortKey]) {
            $createRole->execute($code, $name, ['ja' => $ja, 'en' => $en], $category, sortKey: $sortKey);
        }

        foreach (self::SUB_ROLES as [$parent, $child]) {
            $addSubRole->execute($this->roleId($parent), $this->roleId($child));
        }

        foreach (array_keys(UserSeeder::USERS) as $code) {
            $user = User::query()->where('code', $code)->sole();
            $grantRole->execute($user->id, $this->roleId('general_user'), self::day(UserSeeder::USERS[$code][3]));
        }

        foreach (self::GRANTS as [$user, $role, $start, $end]) {
            $grantRole->execute($this->userId($user), $this->roleId($role), new Period(self::day($start), $end === null ? null : self::day($end)));
        }
    }
}
