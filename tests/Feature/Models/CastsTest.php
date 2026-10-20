<?php

namespace Tests\Feature\Models;

use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\UserRole;
use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * モデルのキャスト(期間の列は日付と真偽値、旗は真偽値、ランクは整数)。列挙のキャストは各モデルのテスト、ソートキーは SortKeyColumnsTest。
 */
final class CastsTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function periodModels(): array
    {
        return [
            'DepartmentTerm' => [DepartmentTerm::class],
            'CompanyPostTerm' => [CompanyPostTerm::class],
            'UserTerm' => [UserTerm::class],
            'DepartmentInclusion' => [DepartmentInclusion::class],
            'DepartmentMembership' => [DepartmentMembership::class],
            'UserRole' => [UserRole::class],
        ];
    }

    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function flags(): array
    {
        return [
            'DepartmentSet.is_default' => [DepartmentSet::class, 'is_default'],
            'Department.is_root' => [Department::class, 'is_root'],
            'DepartmentMembership.is_main' => [DepartmentMembership::class, 'is_main'],
            'AuthzSubjectCondition.is_negated' => [AuthzSubjectCondition::class, 'is_negated'],
            'MenuGroupCategory.is_selectable' => [MenuGroupCategory::class, 'is_selectable'],
            'MenuGroupCategory.is_editable' => [MenuGroupCategory::class, 'is_editable'],
            'Menu.is_iframe_used' => [Menu::class, 'is_iframe_used'],
            'Menu.is_popup_used' => [Menu::class, 'is_popup_used'],
            'RecordModule.is_attachment_enabled' => [RecordModule::class, 'is_attachment_enabled'],
            'RecordField.is_required' => [RecordField::class, 'is_required'],
            'RecordField.is_multiline' => [RecordField::class, 'is_multiline'],
            'RecordField.is_listed' => [RecordField::class, 'is_listed'],
            'RecordField.is_searchable' => [RecordField::class, 'is_searchable'],
            'RecordField.is_title' => [RecordField::class, 'is_title'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('periodModels')]
    public function test_period_columns_are_read_as_dates_and_a_boolean(string $model): void
    {
        $row = Factory::factoryForModel($model)->createOne(['start_date' => '2020-04-01', 'end_date' => '2021-04-01', 'is_deleted' => true]);

        $row->refresh();

        $this->assertInstanceOf(CarbonInterface::class, $row->getAttribute('start_date'));
        $this->assertSame('2020-04-01', $row->getAttribute('start_date')->toDateString());
        $this->assertInstanceOf(CarbonInterface::class, $row->getAttribute('end_date'));
        $this->assertSame('2021-04-01', $row->getAttribute('end_date')->toDateString());
        $this->assertTrue($row->getAttribute('is_deleted'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('periodModels')]
    public function test_an_open_ended_period_is_read_as_a_null_end_date(string $model): void
    {
        $row = Factory::factoryForModel($model)->createOne(['end_date' => null]);

        $row->refresh();

        $this->assertNull($row->getAttribute('end_date'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('flags')]
    public function test_a_flag_is_read_as_a_boolean(string $model, string $flag): void
    {
        $row = Factory::factoryForModel($model)->createOne([$flag => true]);

        $row->refresh();

        $this->assertTrue($row->getAttribute($flag));
    }

    public function test_rank_is_read_as_an_integer(): void
    {
        $post = CompanyPost::factory()->create(['rank' => 10]);

        $post->refresh();

        $this->assertSame(10, $post->rank);
    }
}
