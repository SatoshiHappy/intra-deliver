<?php

namespace Tests\Feature\Models;

use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectCategoryTranslation;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Authz\RoleTranslation;
use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\UserTermTranslation;
use App\Models\Menu\MenuGroupCategoryTranslation;
use App\Models\Menu\MenuTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ロケール別の表(docs の 設計/共通マスタ/共通の規則.md 6 節: 親の行ごとに、1 つのロケールの行は 1 つ)。
 */
final class TranslationsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function translationTables(): array
    {
        return [
            'DepartmentTermTranslation' => [DepartmentTermTranslation::class, 'department_term_id'],
            'CompanyPostTermTranslation' => [CompanyPostTermTranslation::class, 'company_post_term_id'],
            'UserTermTranslation' => [UserTermTranslation::class, 'user_term_id'],
            'RoleTranslation' => [RoleTranslation::class, 'role_id'],
            'AuthzResourceGroupTranslation' => [AuthzResourceGroupTranslation::class, 'authz_resource_group_id'],
            'AuthzSubjectGroupTranslation' => [AuthzSubjectGroupTranslation::class, 'authz_subject_group_id'],
            'AuthzSubjectCategoryTranslation' => [AuthzSubjectCategoryTranslation::class, 'authz_subject_category_id'],
            'MenuGroupCategoryTranslation' => [MenuGroupCategoryTranslation::class, 'menu_group_category_id'],
            'MenuTranslation' => [MenuTranslation::class, 'menu_id'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('translationTables')]
    public function test_rejects_a_second_row_for_the_same_parent_and_locale(string $model, string $parent): void
    {
        $existing = Factory::factoryForModel($model)->createOne();
        $sameParentAndLocale = $existing->only([$parent, 'locale_id']);

        $this->assertRejectedBy(
            $existing->getTable()."_{$parent}_locale_id_unique",
            fn () => Factory::factoryForModel($model)->createOne($sameParentAndLocale),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('translationTables')]
    public function test_accepts_another_locale_for_the_same_parent(string $model, string $parent): void
    {
        $existing = Factory::factoryForModel($model)->createOne();

        $other = Factory::factoryForModel($model)->createOne([$parent => $existing->getAttribute($parent)]);

        $this->assertModelExists($other);
    }
}
