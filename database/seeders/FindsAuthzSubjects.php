<?php

namespace Database\Seeders;

use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Models\Authz\Role;

/**
 * 初期データの seeder が、認可の対象者を条件で引く(docs の 設計/メニュー/初期データ.md 4 節: 対象者はコードを持たないので、表示名でなく条件で引く。
 * 初期データが使う対象者は 1 条件の対象者なので、業務処理「1 条件の対象者を引くか作る」で引く。--class で単独に流す seeder も、無ければ作る)。
 */
trait FindsAuthzSubjects
{
    /**
     * ゲストユーザ(認証状態 anonymous)。
     */
    private function guestSubjectGroupId(FindOrCreateSingleConditionSubjectGroup $findOrCreate): int
    {
        return $findOrCreate->execute(
            new SubjectConditionInput(metaSubject: MetaSubject::Anonymous),
            ['ja' => ['display_name' => 'ゲストユーザ'], 'en' => ['display_name' => 'Guest user']],
        )->id;
    }

    /**
     * 認証済みユーザ(認証状態 authenticated)。
     */
    private function authenticatedSubjectGroupId(FindOrCreateSingleConditionSubjectGroup $findOrCreate): int
    {
        return $findOrCreate->execute(
            new SubjectConditionInput(metaSubject: MetaSubject::Authenticated),
            ['ja' => ['display_name' => '認証済みユーザ'], 'en' => ['display_name' => 'Authenticated user']],
        )->id;
    }

    /**
     * システム管理者(ロール system_admin。ロールは共通マスタの初期データが作る)。
     */
    private function systemAdministratorSubjectGroupId(FindOrCreateSingleConditionSubjectGroup $findOrCreate): int
    {
        return $findOrCreate->execute(
            new SubjectConditionInput(roleId: Role::query()->where('code', 'system_admin')->sole()->id),
            ['ja' => ['display_name' => 'システム管理者'], 'en' => ['display_name' => 'System administrator']],
        )->id;
    }
}
