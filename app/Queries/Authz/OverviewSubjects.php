<?php

namespace App\Queries\Authz;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Authz\SubjectType;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 認可設定の画面の格子の列の対象者(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1): 用途 policy の対象者のすべて
 * (用途 selection は除く。ポリシーの有無で決めない)。並びはカテゴリの sort_key・code、次に対象者の sort_key・id。カテゴリのコードは
 * {@see SubjectType::categoryCode()}(業務処理と同じ)で、表示名はカテゴリ・対象者のロケールの行(無ければ null)。
 * matches = 判定を求める者が d にその対象者に合う(列の対象者を {@see SubjectQuery::matchingSubjectGroupIds()} に 1 回渡して作る)。
 *
 * @phpstan-type GridSubject array{id: int, name: string|null, category: array{code: string, name: string|null}, matches: bool}
 */
class OverviewSubjects
{
    public function __construct(
        private SubjectQuery $subjects,
        private SubjectTypeQuery $subjectTypes,
    ) {}

    /**
     * @return list<GridSubject>
     */
    public function subjectsOn(Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $columns = $this->columnsOf($localeCode);
        usort($columns, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        $matching = $this->subjects->matchingSubjectGroupIds($requester, array_column($columns, 'id'), $date);

        return array_map(fn (array $column) => [
            'id' => $column['id'],
            'name' => $column['name'],
            'category' => $column['category'],
            'matches' => in_array($column['id'], $matching, true),
        ], $columns);
    }

    /**
     * 列の対象者と、並びの鍵([カテゴリの sort_key, カテゴリの code, 対象者の sort_key, 対象者の id])。
     *
     * @return list<array{id: int, name: string|null, category: array{code: string, name: string|null}, order: list<int|string>}>
     */
    private function columnsOf(string $localeCode): array
    {
        $inLocale = fn (Relation $translations) => $translations->whereRelation('locale', 'code', $localeCode);
        $categories = AuthzSubjectCategory::query()->with('authzSubjectCategoryTranslations', $inLocale)->get()->keyBy('code');

        return array_values(AuthzSubjectGroup::query()
            ->where('purpose', SubjectGroupPurpose::Policy)
            ->with('authzSubjectGroupTranslations', $inLocale)
            ->get()
            ->map(function (AuthzSubjectGroup $subjectGroup) use ($categories) {
                $types = $this->subjectTypes->subjectTypesOf($subjectGroup->id);
                $categoryCode = $types === [] ? '' : SubjectType::categoryCode($types);
                $category = $categories->get($categoryCode);

                return [
                    'id' => $subjectGroup->id,
                    'name' => $subjectGroup->authzSubjectGroupTranslations->first()?->display_name,
                    'category' => ['code' => $categoryCode, 'name' => $category?->authzSubjectCategoryTranslations->first()?->display_name],
                    'order' => [$category->sort_key ?? 0, $categoryCode, $subjectGroup->sort_key, $subjectGroup->id],
                ];
            })
            ->all());
    }
}
