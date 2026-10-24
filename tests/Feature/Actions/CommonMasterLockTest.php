<?php

namespace Tests\Feature\Actions;

use App\Actions\Authz\AddAuthzColumn;
use App\Actions\Authz\AddSubRole;
use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Authz\ChangeAuthzCell;
use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\CreateRole;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\DeleteAuthzColumn;
use App\Actions\Authz\DeletePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\GrantRole;
use App\Actions\Authz\RenameResourceGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Authz\UpdatePolicyEffect;
use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Actions\Master\AssignMembershipPost;
use App\Actions\Master\CreateCompany;
use App\Actions\Master\CreateCompanyPost;
use App\Actions\Master\CreateDepartment;
use App\Actions\Master\CreateDepartmentInclusion;
use App\Actions\Master\CreateDepartmentMembership;
use App\Actions\Master\CreateLocale;
use App\Actions\Master\CreateUser;
use App\Actions\Master\InvalidateUserTerm;
use App\Actions\Master\SplitDepartmentTerm;
use App\Actions\Master\SplitUserTerm;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\CreateMenuGroup;
use App\Actions\Menu\CreateMenuGroupCategory;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\RemoveMenu;
use App\Actions\Menu\UpdateMenuDisplay;
use App\Actions\Menu\UpdateMenuSortNumbers;
use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\AddRecordModuleItems;
use App\Actions\Records\AddRecordRule;
use App\Actions\Records\CreateModuleCategory;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\DeleteRecordFieldHeading;
use App\Actions\Records\DeleteRecordRule;
use App\Actions\Records\ModuleCategoryFolders;
use App\Actions\Records\ReorderModuleCategories;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Actions\Records\SetRecordRelatedColumns;
use App\Actions\Records\SyncRecordModuleItems;
use App\Actions\Records\UpdateRecordField;
use App\Actions\Records\UpdateRecordFieldHeading;
use App\Actions\Records\UpdateRecordModule;
use App\Actions\Records\UpdateRecordRule;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Menu\MenuType;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordHeadingGroups;
use App\Support\Period;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 共通マスタの書き込みの業務処理は、すべて同じ勧告ロック(CommonMasterWriteLock)を取って直列になる(メインの決定 2026-10-04)。
 * `CommonMasterWriteLock` を使うクラスがすべて writes() に載っていることを、app の走査で照合する(業務処理を足したら載せる)。
 *
 * テストはトランザクションの中で走るので、業務処理が取ったトランザクションレベルの勧告ロックはテストの終わりまで残る。
 * 別の接続から pg_try_advisory_xact_lock で同じ鍵を取れないことで、業務処理がロックを取ったことを確かめる。
 */
final class CommonMasterLockTest extends TransactionalTestCase
{
    use BuildsAuthzEditing;
    use BuildsCommonMaster;

    private const PROBE = 'common_master_lock_probe';

    protected function tearDown(): void
    {
        DB::purge(self::PROBE);
        parent::tearDown();
    }

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    public static function writes(): array
    {
        return [...self::masterWrites(), ...self::authzWrites(), ...self::menuWrites(), ...self::recordsWrites()];
    }

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    private static function masterWrites(): array
    {
        return [
            CreateLocale::class => [fn (self $test) => app(CreateLocale::class)->execute('fr')],
            CreateCompany::class => [fn (self $test) => app(CreateCompany::class)->execute('c', 's', 'r', self::day('2024-04-01'), [])],
            CreateDepartment::class => [fn (self $test) => app(CreateDepartment::class)->execute(DepartmentSet::factory()->create()->id, 'd', self::day('2024-04-01'), [])],
            CreateCompanyPost::class => [fn (self $test) => app(CreateCompanyPost::class)->execute(DepartmentSet::factory()->create()->id, 'p', 10, self::day('2024-04-01'), [])],
            CreateUser::class => [fn (self $test) => app(CreateUser::class)->execute('u', self::day('2024-04-01'), [])],
            CreateDepartmentInclusion::class => [fn (self $test) => $test->createInclusion()],
            CreateDepartmentMembership::class => [fn (self $test) => $test->createMembership()],
            AssignMembershipPost::class => [fn (self $test) => $test->assignMembershipPost()],
            SplitDepartmentTerm::class => [fn (self $test) => app(SplitDepartmentTerm::class)->execute($test->department(DepartmentSet::factory()->create())->id, self::day('2024-04-01'))],
            SplitUserTerm::class => [fn (self $test) => app(SplitUserTerm::class)->execute($test->user()->id, self::day('2024-04-01'))],
            InvalidateUserTerm::class => [fn (self $test) => app(InvalidateUserTerm::class)->execute($test->user()->id, self::day('2020-01-01'))],
        ];
    }

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    private static function authzWrites(): array
    {
        return [
            CreateRole::class => [fn (self $test) => app(CreateRole::class)->execute('r', 'role_r', [])],
            AddSubRole::class => [fn (self $test) => app(AddSubRole::class)->execute(Role::factory()->create()->id, Role::factory()->create()->id)],
            GrantRole::class => [fn (self $test) => app(GrantRole::class)->execute($test->user()->id, Role::factory()->create()->id, self::day('2024-04-01'))],
            CreatePolicy::class => [fn (self $test) => app(CreatePolicy::class)->execute(AuthzResourceGroup::factory()->create()->id, AuthzSubjectGroup::factory()->create()->id, 'service', 'execute', PolicyEffect::Permit)],
            CreateResource::class => [fn (self $test) => app(CreateResource::class)->execute('example.home', AuthzResourceGroup::factory()->create()->id, 'service', 'service://example/home', [])],
            CreateResourceGroup::class => [fn (self $test) => app(CreateResourceGroup::class)->execute('example.menu', AuthzResourceGroup::factory()->create()->id, [])],
            CreateRoutePrefixResourceGroup::class => [fn (self $test) => app(CreateRoutePrefixResourceGroup::class)->execute('example', [])],
            CreateRouteResource::class => [fn (self $test) => $test->createRouteResource()],
            CreateSubjectGroup::class => [fn (self $test) => app(CreateSubjectGroup::class)->execute(SubjectCombination::All, [new SubjectConditionInput(roleId: Role::factory()->create()->id)], [])],
            FindOrCreateSingleConditionSubjectGroup::class => [fn (self $test) => app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(roleId: Role::factory()->create()->id), [])],
            RenameResourceGroup::class => [fn (self $test) => app(RenameResourceGroup::class)->execute(AuthzResourceGroup::factory()->create()->code, ['ja' => '新名'])],
            CreateSelectionSubjectGroup::class => [fn (self $test) => app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition(SelectionConditionKind::User, $test->user()->id)], ['ja' => '承認者'])],
            ChangeAuthzCell::class => [fn (self $test) => $test->changeAuthzCell()],
            UpdatePolicyEffect::class => [fn (self $test) => $test->updatePolicyEffect()],
            DeletePolicy::class => [fn (self $test) => app(DeletePolicy::class)->execute($test->policyId())],
            AddAuthzColumn::class => [fn (self $test) => $test->addAuthzColumn()],
            DeleteAuthzColumn::class => [fn (self $test) => $test->deleteAuthzColumn()],
        ];
    }

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    private static function menuWrites(): array
    {
        return [
            AddMenu::class => [fn (self $test) => app(AddMenu::class)->execute(Menu::factory()->create(['type' => MenuType::Folder])->id, new MenuFolderInput('example-reports', ['ja' => 'レポート']))],
            CreateMenuGroup::class => [fn (self $test) => $test->createMenuGroup()],
            CreateMenuGroupCategory::class => [fn (self $test) => app(CreateMenuGroupCategory::class)->execute('example_nav_pc', ['ja' => '例のナビ'])],
            RemoveMenu::class => [fn (self $test) => app(RemoveMenu::class)->execute([Menu::factory()->item()->create()->code])],
            UpdateMenuDisplay::class => [fn (self $test) => app(UpdateMenuDisplay::class)->execute([Menu::factory()->item()->create()->code], ['ja' => '新名'], 'truck')],
            UpdateMenuSortNumbers::class => [fn (self $test) => app(UpdateMenuSortNumbers::class)->execute([Menu::factory()->create()->code => 5])],
        ];
    }

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    private static function recordsWrites(): array
    {
        return [
            AddRecordField::class => [fn (self $test) => app(AddRecordField::class)->execute('project', ['code' => 'memo', 'name' => 'メモ', 'type' => 'string'])],
            AddRecordFieldHeading::class => [fn (self $test) => $test->addHeading()],
            AddRecordModuleItems::class => [fn (self $test) => app(AddRecordModuleItems::class)->execute(RecordModule::factory()->create(), ['ja' => '備品'])],
            AddRecordRule::class => [fn (self $test) => app(AddRecordRule::class)->execute('project', ['kind' => 'compare', 'target' => 'end_date', 'operator' => 'gt', 'compared' => 'start_date'])],
            CreateModuleCategory::class => [fn (self $test) => app(CreateModuleCategory::class)->execute(['name' => '営業'])],
            CreateRecordModule::class => [fn (self $test) => app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品'])],
            DeleteRecordField::class => [fn (self $test) => $test->deleteRecordField()],
            DeleteRecordFieldHeading::class => [fn (self $test) => $test->deleteHeading()],
            DeleteRecordRule::class => [fn (self $test) => app(DeleteRecordRule::class)->execute('project', $test->projectRule()->id)],
            ModuleCategoryFolders::class => [fn (self $test) => app(ModuleCategoryFolders::class)->write($test->moduleCategory(), false, fn () => null)],
            ReorderModuleCategories::class => [fn (self $test) => app(ReorderModuleCategories::class)->execute([$test->moduleCategory()])],
            ReorderRecordFields::class => [fn (self $test) => $test->reorderFields()],
            ReorderRecordModuleItems::class => [fn (self $test) => app(ReorderRecordModuleItems::class)->execute(null, ['project', 'project_issue', 'meeting'])],
            SetRecordRelatedColumns::class => [fn (self $test) => $test->setRelatedColumns()],
            SyncRecordModuleItems::class => [fn (self $test) => app(SyncRecordModuleItems::class)->execute(RecordModule::factory()->create(), [], ['ja' => '備品'])],
            UpdateRecordField::class => [fn (self $test) => app(UpdateRecordField::class)->execute('project', 'summary', ['name' => '要旨'])],
            UpdateRecordFieldHeading::class => [fn (self $test) => $test->renameHeading()],
            UpdateRecordModule::class => [fn (self $test) => app(UpdateRecordModule::class)->execute('project', ['description' => '説明'])],
            UpdateRecordRule::class => [fn (self $test) => app(UpdateRecordRule::class)->execute('project', $test->projectRule()->id, ['kind' => 'compare', 'target' => 'end_date', 'operator' => 'gt', 'compared' => 'start_date'])],
        ];
    }

    /**
     * @param  Closure(self): mixed  $write
     */
    #[DataProvider('writes')]
    public function test_every_write_takes_the_common_master_lock(Closure $write): void
    {
        $this->assertTrue($this->anotherConnectionCanTakeTheLock(), '業務処理の前はロックが空いている');

        $write($this);

        $this->assertFalse($this->anotherConnectionCanTakeTheLock(), '業務処理がロックを取り、トランザクションの終わりまで持つ');
    }

    public function test_writes_lists_every_class_that_uses_the_common_master_lock(): void
    {
        $users = self::classesUsingTheCommonMasterLock();
        $listed = array_keys(self::writes());
        sort($users);
        sort($listed);

        $this->assertSame($users, $listed, '`CommonMasterWriteLock` を使うクラスと writes() の一覧が違う。足した業務処理を writes() に載せる');
    }

    /**
     * 前提のデータは factory で作り(ロックを取らない)、業務処理だけがロックを取るようにする。
     */
    public function createInclusion(): DepartmentInclusion
    {
        $set = DepartmentSet::factory()->create();

        return app(CreateDepartmentInclusion::class)->execute($this->department($set)->id, $this->department($set)->id, new Period(self::day('2024-04-01')));
    }

    public function createMembership(): DepartmentMembership
    {
        return app(CreateDepartmentMembership::class)->execute($this->user()->id, $this->department(DepartmentSet::factory()->create())->id, new Period(self::day('2024-04-01')), isMain: true);
    }

    public function assignMembershipPost(): DepartmentMembershipPost
    {
        $set = DepartmentSet::factory()->create();
        $membership = $this->join($this->user(), $this->department($set), isMain: true);

        return app(AssignMembershipPost::class)->execute($membership->id, $this->companyPost($set, 10)->id);
    }

    public function createRouteResource(): AuthzResource
    {
        Route::get('/example/items', fn () => 'items')->name('example.items.index');
        $set = AuthzResourceGroup::query()->where('code', 'http-services')->sole();
        $prefix = AuthzResourceGroup::factory()->create(['code' => 'http-services.example', 'parent_id' => $set->id]);
        AuthzResourceGroup::factory()->create(['code' => 'http-services.example.items', 'parent_id' => $prefix->id]);

        return app(CreateRouteResource::class)->execute('example.items.index', []);
    }

    public function createMenuGroup(): MenuGroup
    {
        $category = MenuGroupCategory::factory()->create();
        $set = AuthzResourceGroup::query()->where('code', 'im-menu-group')->sole();
        AuthzResourceGroup::factory()->create(['code' => "im-menu-group-cat-{$category->code}", 'parent_id' => $set->id]);

        return app(CreateMenuGroup::class)->execute($category->id, new MenuFolderInput('example-group', ['ja' => 'グループ']));
    }

    /**
     * 初期データのプロジェクトは申請済みのレコードを持ち項目を消せないので、factory の業務モジュールの項目を消す。
     */
    public function deleteRecordField(): void
    {
        $field = RecordField::factory()->create();

        app(DeleteRecordField::class)->execute(RecordModule::query()->findOrFail($field->record_module_id)->code, $field->code);
    }

    /**
     * 業務モジュールの定義を変える業務処理は、認可のリソースを持つ初期データの業務モジュール「プロジェクト」(project)を使う。
     */
    public function projectRule(): RecordRule
    {
        return RecordRule::query()->where('record_module_id', self::project()->id)->sole();
    }

    public function setRelatedColumns(): void
    {
        app(SetRecordRelatedColumns::class)->execute('project_issue', ['entry' => 'project', 'columns' => ['title']]);
    }

    public function addHeading(): void
    {
        app(AddRecordFieldHeading::class)->execute('project', ['name' => '補足']);
    }

    public function renameHeading(): void
    {
        app(UpdateRecordFieldHeading::class)->execute('project', $this->projectHeading()->id, ['name' => '期間']);
    }

    public function deleteHeading(): void
    {
        app(DeleteRecordFieldHeading::class)->execute('project', $this->projectHeading()->id);
    }

    /**
     * 「プロジェクト」の見出しと項目の 1 本の並び(見本の見出しを含む)を逆順にして並び替える。
     */
    public function reorderFields(): void
    {
        $items = app(RecordHeadingGroups::class)->adminProps(self::project()->id)['items'];

        app(ReorderRecordFields::class)->execute('project', array_reverse($items));
    }

    private function projectHeading(): RecordFieldHeading
    {
        return RecordFieldHeading::query()->where('record_module_id', self::project()->id)->firstOrFail();
    }

    /**
     * 両方の木の「業務」のルートの直下に、分類のフォルダを factory で作る(分類を作る業務処理はロックを取るので使わない)。
     */
    public function moduleCategory(): string
    {
        $categoryId = '01'.str_repeat('0', 24);

        foreach (WorkMenuGroup::cases() as $group) {
            $root = Menu::query()->where('code', $group->value)->sole();
            Menu::factory()->create(['code' => $group->categoryFolderCode($categoryId), 'parent_id' => $root->id, 'sort_number' => 10]);
        }

        return $categoryId;
    }

    private static function project(): RecordModule
    {
        return RecordModule::query()->where('code', 'project')->sole();
    }

    /**
     * app の下で CommonMasterWriteLock を使うクラス(完全なクラス名)。
     *
     * @return list<string>
     */
    private static function classesUsingTheCommonMasterLock(): array
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen(app_path()) + 1, -strlen('.php')));
            if (! trait_exists($class) && in_array(CommonMasterWriteLock::class, class_uses_recursive($class), true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * 別の接続(自動コミット)で同じ鍵の勧告ロックを試しに取る。取れたらその文の終わりで外れる。
     */
    private function anotherConnectionCanTakeTheLock(): bool
    {
        config(['database.connections.'.self::PROBE => config('database.connections.'.config('database.default'))]);
        $row = DB::connection(self::PROBE)->selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [CreateLocale::COMMON_MASTER_LOCK_KEY]);

        return $row->acquired === true;
    }
}
