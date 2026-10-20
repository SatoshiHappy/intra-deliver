<?php

namespace Tests\Unit\Enums\Authz;

use App\Enums\Authz\SubjectType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 対象者のカテゴリのコードと表示名(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_categories、
 * 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_categories のカテゴリの表示名の文言: 1 種 = その ID と名称、2 種 = ID のバイト順の昇順に「:」でつなぎ「A、Bの複合」、
 * 3 種以上 = (dummy) と「その他複合」。ja・en 以外のロケールは en の文言)。
 */
final class SubjectTypeTest extends TestCase
{
    /**
     * @return array<string, array{list<SubjectType>, string, string, string}>
     */
    public static function categories(): array
    {
        return [
            '1 種' => [[SubjectType::MetaSubject], 'im_authz_meta_subject', '認証', 'Authentication'],
            '1 種を重ねたもの' => [[SubjectType::Role, SubjectType::Role], 'b_m_role', 'ロール', 'Role'],
            '2 種(渡した順と逆のバイト順)' => [[SubjectType::Ipv4Address, SubjectType::Role], 'b_m_role:im_authz_ipv4', 'ロール、IPv4 アドレスの複合', 'Composite of Role and IPv4 address'],
            '2 種(「_」が英字より前になるバイト順)' => [[SubjectType::Term, SubjectType::CompanyPost], 'im_authz_term:imm_company_post', '期間、役職の複合', 'Composite of Term and Post'],
            '3 種' => [[SubjectType::User, SubjectType::Department, SubjectType::Role], '(dummy)', 'その他複合', 'Other composite'],
        ];
    }

    /**
     * @param  non-empty-list<SubjectType>  $types
     */
    #[DataProvider('categories')]
    public function test_builds_the_category_code_and_display_names(array $types, string $code, string $japanese, string $english): void
    {
        $this->assertSame($code, SubjectType::categoryCode($types));
        $this->assertSame(
            [$japanese, $english, $english],
            [SubjectType::categoryDisplayName($types, 'ja'), SubjectType::categoryDisplayName($types, 'en'), SubjectType::categoryDisplayName($types, 'fr')],
        );
    }

    public function test_names_every_subject_type_in_japanese_and_english(): void
    {
        $this->assertSame(
            [
                ['ユーザ', 'User'], ['会社組織', 'Department'], ['役職', 'Post'], ['ロール', 'Role'],
                ['IPv4 アドレス', 'IPv4 address'], ['認証', 'Authentication'], ['期間', 'Term'],
            ],
            array_map(fn (SubjectType $type) => [$type->displayName('ja'), $type->displayName('en')], SubjectType::cases()),
        );
    }
}
