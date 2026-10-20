<?php

namespace App\Enums\Authz;

/**
 * 認可の対象者の条件のサブジェクトタイプ(本家のサブジェクトタイプ ID)と、対象者のカテゴリのコード・表示名の作り方
 * (docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_categories、設計/メニュー/認可との結び付け.md 2.4 のカテゴリの表示名の文言)。
 *
 * コード = 1 種ならその ID、2 種なら 2 つの ID をバイト順の昇順に並べて「:」でつないだもの、3 種以上なら (dummy)。
 * 表示名 = 1 種ならサブジェクトタイプの名称、2 種なら「A、Bの複合」(A・B はコードと同じ順)、3 種以上なら「その他複合」。
 * ja は本家の名称、en は設計が決めた文言。ja・en 以外のロケールは en の文言(設計が決めた)。
 */
enum SubjectType: string
{
    case User = 'imm_user';
    case Department = 'imm_department';
    case CompanyPost = 'imm_company_post';
    case Role = 'b_m_role';
    case Ipv4Address = 'im_authz_ipv4';
    case MetaSubject = 'im_authz_meta_subject';
    case Term = 'im_authz_term';

    /** 3 種以上のサブジェクトタイプのカテゴリのコード(本家のまま) */
    public const OTHER_COMPOSITE_CODE = '(dummy)';

    /**
     * サブジェクトタイプの組のカテゴリのコード。
     *
     * @param  non-empty-list<self>  $types  重なりと並びは問わない
     */
    public static function categoryCode(array $types): string
    {
        $sorted = self::sortedUnique($types);

        return count($sorted) <= 2
            ? implode(':', array_map(fn (self $type) => $type->value, $sorted))
            : self::OTHER_COMPOSITE_CODE;
    }

    /**
     * サブジェクトタイプの組のカテゴリの、ロケールの表示名。
     *
     * @param  non-empty-list<self>  $types  重なりと並びは問わない
     */
    public static function categoryDisplayName(array $types, string $localeCode): string
    {
        $sorted = self::sortedUnique($types);
        $isJapanese = $localeCode === 'ja';

        return match (count($sorted)) {
            1 => $sorted[0]->displayName($localeCode),
            2 => $isJapanese
                ? "{$sorted[0]->displayName($localeCode)}、{$sorted[1]->displayName($localeCode)}の複合"
                : "Composite of {$sorted[0]->displayName($localeCode)} and {$sorted[1]->displayName($localeCode)}",
            default => $isJapanese ? 'その他複合' : 'Other composite',
        };
    }

    /**
     * サブジェクトタイプの名称(ja は本家の名称、それ以外のロケールは en の文言)。
     */
    public function displayName(string $localeCode): string
    {
        return $localeCode === 'ja' ? $this->japaneseName() : $this->englishName();
    }

    /** 本家の名称 */
    private function japaneseName(): string
    {
        return match ($this) {
            self::User => 'ユーザ',
            self::Department => '会社組織',
            self::CompanyPost => '役職',
            self::Role => 'ロール',
            self::Ipv4Address => 'IPv4 アドレス',
            self::MetaSubject => '認証',
            self::Term => '期間',
        };
    }

    /** 設計が決めた en の文言 */
    private function englishName(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Department => 'Department',
            self::CompanyPost => 'Post',
            self::Role => 'Role',
            self::Ipv4Address => 'IPv4 address',
            self::MetaSubject => 'Authentication',
            self::Term => 'Term',
        };
    }

    /**
     * 重なりを除き、ID のバイト順の昇順(照合順序によらない)に並べる。
     *
     * @param  non-empty-list<self>  $types
     * @return non-empty-list<self>
     */
    private static function sortedUnique(array $types): array
    {
        $byValue = [];

        foreach ($types as $type) {
            $byValue[$type->value] = $type;
        }

        ksort($byValue, SORT_STRING);

        return array_values($byValue);
    }
}
