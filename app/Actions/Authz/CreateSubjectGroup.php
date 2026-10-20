<?php

namespace App\Actions\Authz;

use App\Actions\Master\ResolvesLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Authz\SubjectType;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\Locale;
use App\Queries\Authz\SubjectTypeQuery;
use Illuminate\Validation\ValidationException;

/**
 * 認可の対象者を、条件・表示名・説明と一緒に作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/対象者.md)。
 *
 * 止める規則: 条件は 1 件以上。IPv4 アドレスのパターンの [m-n] は m ≤ n。除外の制約(本家の認可設定画面の制約を業務処理で止める):
 * all では少なくとも 1 つの条件が除外でない、any では除外の条件を置かない。子の対象者の循環は作るときには起きない(新しい行は誰の子でもない)。
 * 作った対象者のカテゴリのコード(子の対象者をたどったサブジェクトタイプから決まる)のカテゴリの行が無ければ、locales のすべてのロケールの表示名と
 * 一緒に、並び順をカテゴリの並びの表({@see self::CATEGORY_SORT_KEYS})で決めて作る(表示名の文言は {@see SubjectType}。並びを変える業務処理が無いので
 * 作るときに決める = docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.2)。サブジェクトタイプが 1 つも無い(子の対象者が条件を持たない)ときは、カテゴリが
 * 決まらないので拒む(条件を 1 件以上にする規則の目的 =「カテゴリが決まるように」と同じ扱い)。
 * 用途 selection の対象者を子の対象者にしない(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups の「用途 `selection` の規則」)。
 * 用途は呼び手が渡す(既定 policy。selection は {@see Api\CreateSelectionSubjectGroup} だけが渡す)。
 * 条件が相手を 1 つだけ指すこと、比較・パターンの書式は DB が止める。
 */
class CreateSubjectGroup
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * カテゴリの並びの表(1 種のサブジェクトタイプのカテゴリのコード → 並び順。本家の順 = 認証 → ロール → ユーザー → 組織 → 役職)。
     * 既にあるカテゴリの行を埋めるマイグレーションも同じ値を持つ。
     */
    public const CATEGORY_SORT_KEYS = [
        SubjectType::MetaSubject->value => 1,
        SubjectType::Role->value => 2,
        SubjectType::User->value => 3,
        SubjectType::Department->value => 4,
        SubjectType::CompanyPost->value => 5,
    ];

    /** 表に無いカテゴリ(そのほかの 1 種と、2 種以上)の並び順 */
    public const OTHER_CATEGORY_SORT_KEY = 6;

    public function __construct(private SubjectTypeQuery $subjectTypes) {}

    /**
     * @param  list<SubjectConditionInput>  $conditions
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 規則に反するとき、無いロケールのコードがあるとき
     */
    public function execute(
        SubjectCombination $combination,
        array $conditions,
        array $translations,
        int $sortKey = 0,
        SubjectGroupPurpose $purpose = SubjectGroupPurpose::Policy,
    ): AuthzSubjectGroup {
        $this->validateConditions($combination, $conditions);
        $localeIds = $this->localeIds($translations);

        return $this->writeCommonMaster(function () use ($combination, $conditions, $translations, $sortKey, $purpose, $localeIds) {
            $this->rejectSelectionChildren($conditions);

            $subjectGroup = AuthzSubjectGroup::query()->create(['combination' => $combination, 'sort_key' => $sortKey, 'purpose' => $purpose]);

            foreach ($conditions as $condition) {
                $subjectGroup->authzSubjectConditions()->create($condition->attributes());
            }

            foreach ($translations as $localeCode => $values) {
                $subjectGroup->authzSubjectGroupTranslations()->create([
                    'locale_id' => $localeIds[$localeCode],
                    'display_name' => $values['display_name'],
                    'description' => $values['description'] ?? null,
                ]);
            }

            $this->ensureCategory($subjectGroup);

            return $subjectGroup;
        });
    }

    /**
     * @param  list<SubjectConditionInput>  $conditions
     *
     * @throws ValidationException 規則に反するとき
     */
    private function validateConditions(SubjectCombination $combination, array $conditions): void
    {
        if ($conditions === []) {
            throw ValidationException::withMessages(['conditions' => ['条件を 1 件以上指定してください']]);
        }

        foreach ($conditions as $condition) {
            $this->validateIpv4Ranges($condition->ipv4Pattern);
        }

        $negatedCount = count(array_filter($conditions, fn (SubjectConditionInput $condition) => $condition->isNegated));

        if ($combination === SubjectCombination::All && $negatedCount === count($conditions)) {
            throw ValidationException::withMessages(['is_negated' => ['「すべてに一致する」では、少なくとも 1 つの条件を除外でなくしてください']]);
        }

        if ($combination === SubjectCombination::Any && $negatedCount > 0) {
            throw ValidationException::withMessages(['is_negated' => ['「いずれかに一致する」では、除外の条件を指定できません']]);
        }
    }

    /**
     * 子の対象者に用途 selection の対象者を指す条件が無いか(用途は変わらないが、読んでから書く規則なので勧告ロックの中で読む)。
     *
     * @param  list<SubjectConditionInput>  $conditions
     *
     * @throws ValidationException 用途 selection の対象者を子にする条件があるとき
     */
    private function rejectSelectionChildren(array $conditions): void
    {
        $childIds = array_values(array_filter(array_map(fn (SubjectConditionInput $condition) => $condition->childSubjectGroupId, $conditions), fn (?int $id) => $id !== null));

        if ($childIds !== [] && AuthzSubjectGroup::query()->whereKey($childIds)->where('purpose', SubjectGroupPurpose::Selection)->exists()) {
            throw ValidationException::withMessages(['child_authz_subject_group_id' => ['ほかのモジュールの指定のための対象者は、子の対象者にできません']]);
        }
    }

    /**
     * IPv4 アドレスのパターンの範囲 [m-n] がすべて m ≤ n か(書式そのものは DB の検査制約が止める)。
     *
     * @throws ValidationException m > n の範囲があるとき
     */
    private function validateIpv4Ranges(?string $ipv4Pattern): void
    {
        preg_match_all('/\[(\d+)-(\d+)\]/', $ipv4Pattern ?? '', $ranges, PREG_SET_ORDER);

        foreach ($ranges as $range) {
            if (intval($range[1]) > intval($range[2])) {
                throw ValidationException::withMessages(['ipv4_pattern' => ["IPv4 アドレスのパターンの範囲 [m-n] は m ≤ n にしてください: {$ipv4Pattern}"]]);
            }
        }
    }

    /**
     * 対象者のカテゴリのコードのカテゴリの行が無ければ、すべてのロケールの表示名と一緒に作る(あれば表示名を含めて変えない)。
     *
     * @throws ValidationException サブジェクトタイプが 1 つも無く、カテゴリが決まらないとき
     */
    private function ensureCategory(AuthzSubjectGroup $subjectGroup): void
    {
        $types = $this->subjectTypes->subjectTypesOf($subjectGroup->id);

        if ($types === []) {
            throw ValidationException::withMessages(['child_authz_subject_group_id' => ['子の対象者に条件が無いため、カテゴリを判定できません']]);
        }

        $code = SubjectType::categoryCode($types);

        if (AuthzSubjectCategory::query()->where('code', $code)->exists()) {
            return;
        }

        $sortKey = self::CATEGORY_SORT_KEYS[$code] ?? self::OTHER_CATEGORY_SORT_KEY;
        $category = AuthzSubjectCategory::query()->create(['code' => $code, 'sort_key' => $sortKey]);

        foreach (Locale::query()->get() as $locale) {
            $category->authzSubjectCategoryTranslations()->create([
                'locale_id' => $locale->id,
                'display_name' => SubjectType::categoryDisplayName($types, $locale->code),
            ]);
        }
    }
}
