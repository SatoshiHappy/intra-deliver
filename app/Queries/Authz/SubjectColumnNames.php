<?php

namespace App\Queries\Authz;

use App\Enums\Authz\SubjectColumnKind;
use App\Models\Authz\Role;
use App\Models\Authz\RoleTranslation;
use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use DateTimeInterface;

/**
 * 「列を追加」の相手が d に有効かと、列の表示名にする相手の d の名前(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1)。
 */
class SubjectColumnNames
{
    /**
     * 相手の d の名前(ロケールのコード → 名前。locales のすべて)。相手が無い・d に有効でなければ null。
     *
     * @return array<string, string>|null
     */
    public function namesOn(SubjectColumnKind $kind, int $targetId, DateTimeInterface $date): ?array
    {
        $code = $this->effectiveCode($kind, $targetId, $date);

        if ($code === null) {
            return null;
        }

        $names = $this->localeNames($kind, $targetId, $date);

        return Locale::query()->orderBy('code')->pluck('code')
            ->mapWithKeys(fn (string $localeCode) => [$localeCode => $names[$localeCode] ?? $code])
            ->all();
    }

    /**
     * d に有効な相手のコード(無ければ null)。
     */
    private function effectiveCode(SubjectColumnKind $kind, int $targetId, DateTimeInterface $date): ?string
    {
        $code = match ($kind) {
            SubjectColumnKind::User => User::query()->whereKey($targetId)->effectiveOn($date)->value('code'),
            SubjectColumnKind::Department => Department::query()->whereKey($targetId)->effectiveOn($date)
                ->value('code'),
            SubjectColumnKind::CompanyPost => CompanyPost::query()->whereKey($targetId)->effectiveOn($date)
                ->value('code'),
            SubjectColumnKind::Role => Role::query()->whereKey($targetId)->value('code'),
        };

        return $code === null ? null : (string) $code;
    }

    /**
     * 相手の d の名前があるロケールの名前(ロケールのコード → 名前)。
     *
     * @return array<string, string>
     */
    private function localeNames(SubjectColumnKind $kind, int $targetId, DateTimeInterface $date): array
    {
        $pairs = match ($kind) {
            SubjectColumnKind::User => UserTerm::query()->where('user_id', $targetId)->effectiveOn($date)
                ->with('userTermTranslations.locale')->first()?->userTermTranslations
                ->map(fn (UserTermTranslation $row) => [$row->locale?->code, $row->name]),
            SubjectColumnKind::Department => DepartmentTerm::query()->where('department_id', $targetId)
                ->effectiveOn($date)->with('departmentTermTranslations.locale')->first()?->departmentTermTranslations
                ->map(fn (DepartmentTermTranslation $row) => [$row->locale?->code, $row->name]),
            SubjectColumnKind::CompanyPost => CompanyPostTerm::query()->where('company_post_id', $targetId)
                ->effectiveOn($date)->with('companyPostTermTranslations.locale')->first()?->companyPostTermTranslations
                ->map(fn (CompanyPostTermTranslation $row) => [$row->locale?->code, $row->name]),
            SubjectColumnKind::Role => RoleTranslation::query()->where('role_id', $targetId)->with('locale')->get()
                ->map(fn (RoleTranslation $row) => [$row->locale?->code, $row->display_name]),
        };
        $names = [];

        foreach ($pairs ?? [] as [$localeCode, $name]) {
            $names[(string) $localeCode] = $name;
        }

        return $names;
    }
}
