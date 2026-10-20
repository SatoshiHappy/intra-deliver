<?php

namespace App\Queries\Authz\Api;

use App\Models\Authz\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * ロールの候補(ワークフローの対象者の候補)。合い方は共通マスタの候補(Master の Api\MasterCandidates)と同じ: コード・そのロケールの表示名
 * (role_translations.display_name)の部分一致(大文字小文字を区別しない。%・_・\ は文字そのもの)。ロールは期間を持たないので基準日を取らない。
 * 空の語は空を返す。語の前後の空白は呼び手が除く。判定しない。鍵を取らない。
 *
 * Authz の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class RoleCandidates
{
    /**
     * @return list<array{id: int, code: string, name: string|null}> 名前 = そのロケールの表示名(無ければ null)。並び = sort_key・code、$limit 件まで
     */
    public function roles(string $q, string $localeCode, int $limit): array
    {
        $this->assertLimit($limit);

        if ($q === '') {
            return [];
        }

        $pattern = '%'.addcslashes($q, '\\%_').'%';
        $roles = Role::query()
            ->where(fn (Builder $match) => $match
                ->whereLike('roles.code', $pattern)
                ->orWhereHas('roleTranslations', fn (Builder $translations) => $translations
                    ->whereRelation('locale', 'code', $localeCode)
                    ->whereLike('role_translations.display_name', $pattern)))
            ->with('roleTranslations', fn (Relation $translations) => $translations->whereRelation('locale', 'code', $localeCode))
            ->orderBy('sort_key')
            ->orderBy('code')
            ->limit($limit)
            ->get();

        return array_values($roles->map(fn (Role $role) => [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->roleTranslations->first()?->display_name,
        ])->all());
    }

    /**
     * 件数は 1 以上(負の数を渡すと問い合わせが上限なしになり、「$limit 件まで」の保証が破れる)。
     *
     * @throws LogicException 1 未満のとき(呼び手の誤り)
     */
    private function assertLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new LogicException("件数は 1 以上にする: {$limit}");
        }
    }
}
