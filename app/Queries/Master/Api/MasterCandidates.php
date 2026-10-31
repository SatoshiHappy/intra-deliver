<?php

namespace App\Queries\Master\Api;

use App\Queries\Master\MasterSearchQuery;
use DateTimeInterface;
use LogicException;

/**
 * ユーザー・組織・役職の候補(ワークフローの処理対象者・対象者の候補)。合う語の範囲・並びは共通マスタの検索と同じ規則の 1 か所
 * ({@see MasterSearchQuery}。コード・名前・検索用名称の部分一致、%・_・\ は文字そのもの、組織は組織図の木にあるものだけ)を、基準日と件数を変えて呼ぶ。
 * 共通マスタの検索と違い、空の語は空を返す(先頭を出さない)。語の前後の空白は呼び手が除く。判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class MasterCandidates
{
    public function __construct(private MasterSearchQuery $search) {}

    /**
     * @return list<array{id: int, code: string, name: string|null, mainDepartmentName: string|null}> d に有効なユーザー(sort_key・code の順で $limit 件まで)。
     *                                                                                                主所属の組織の名前 = d に成り立つ主所属のうち木にある組織の d・ロケールの名前(無ければ null)
     */
    public function usersOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
    {
        $this->assertLimit($limit);

        if ($q === '') {
            return [];
        }

        return array_map(fn (array $user) => [
            'id' => $user['id'],
            'code' => $user['code'],
            'name' => $user['name'],
            'mainDepartmentName' => $user['mainDepartment']['name'] ?? null,
        ], $this->search->users($q, $d, $localeCode, $limit));
    }

    /**
     * @return list<array{id: int, code: string, name: string|null}> 組織図の木の組織(sort_key・code の順で $limit 件まで)
     */
    public function departmentsOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
    {
        $this->assertLimit($limit);

        return $q === '' ? [] : $this->search->departments($q, $d, $localeCode, $limit);
    }

    /**
     * @return list<array{id: int, code: string, name: string|null}> d に有効な役職(sort_key・code の順で $limit 件まで)
     */
    public function companyPostsOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
    {
        $this->assertLimit($limit);

        return $q === '' ? [] : $this->search->companyPosts($q, $d, $localeCode, $limit);
    }

    /**
     * d に有効な役職の全部(語で絞らない。並びは {@see self::companyPostsOn()} と同じ。ワークフローの定義の管理の役職の選択の欄が使う)。
     *
     * @return list<array{id: int, code: string, name: string|null}>
     */
    public function effectiveCompanyPostsOn(DateTimeInterface $d, string $localeCode): array
    {
        return $this->search->companyPosts('', $d, $localeCode, null);
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
