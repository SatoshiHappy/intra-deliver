<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\CreateRecordModule;
use App\Enums\Menu\MenuIcon;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\DeletesResourceGroupTree;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを作る業務処理(docs の 設計/業務データの入れ物/認可とメニュー/README.md 1 節・3 節、メニューと初期データ.md 5.1・8 節の「業務モジュールを作る業務処理」、
 * 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3): 同じトランザクションでリソース・8 件のポリシー・「業務」の 2 つのアイテムができる。
 * 業務モジュールの icon に入力のアイコン(無ければ table)が入り、
 * 2 つのアイテムの icon_32 はその写し。アイテムの並びは作った順
 * (グループごとに、業務モジュールのアイテムの最大 + 10。最初は 100。初期データのプロジェクト 100・課題 110・会議 120・申請書の業務モジュール 2 つ
 * 130・140 の次は 150)。セット・グループが無ければ拒み、何も残らない。
 */
final class CreateRecordModuleTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use DeletesResourceGroupTree;

    /**
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'コードの重なり' => [['code' => 'project', 'name' => '別のプロジェクト'], ['code' => 'このコードは既に使用されています']],
            'コードの書式' => [['code' => 'Equipment', 'name' => '備品'], ['code' => 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください']],
            '予約語 admin' => [['code' => 'admin', 'name' => '管理'], ['code' => 'admin は予約語のためコードに使用できません']],
            '予約語 categories' => [['code' => 'categories', 'name' => '分類'], ['code' => 'categories は予約語のためコードに使用できません']],
            '名前の空白だけ' => [['code' => 'equipment', 'name' => '  '], ['name' => '名前を入力してください']],
            'アイコンが一覧に無い文字列' => [['code' => 'equipment', 'name' => '備品', 'icon' => 'unknown'], ['icon' => 'アイコンは一覧から選択してください']],
            'アイコンが許可表にあるが選べないもの' => [['code' => 'equipment', 'name' => '備品', 'icon' => 'house'], ['icon' => 'アイコンは一覧から選択してください']],
            'アイコンが配列' => [['code' => 'equipment', 'name' => '備品', 'icon' => ['table']], ['icon' => 'アイコンは一覧から選択してください']],
            'アイコンが数' => [['code' => 'equipment', 'name' => '備品', 'icon' => 1], ['icon' => 'アイコンは一覧から選択してください']],
            '説明の 4001 文字' => [['code' => 'equipment', 'name' => '備品', 'description' => str_repeat('あ', 4001)], ['description' => '説明は 4000 文字以内で入力してください']],
        ];
    }

    public function test_creates_the_module_with_its_resource_policies_and_menu_items(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');

        $module = app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => ' 備品 ', 'description' => '']);

        $this->assertSame(['equipment', '備品', null, 'table', null], [$module->code, $module->name, $module->description, $module->refresh()->icon, $module->parent_record_module_id]);
        $resource = AuthzResource::query()->where('uri', 'record-module://module/equipment')->sole();
        $group = AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations'])->findOrFail($resource->authz_resource_group_id);
        $this->assertSame(['record-module', 'record-module-equipment', 'record-modules'], [$resource->resource_type, $group->code, $group->parent?->code]);
        $this->assertSame(['備品', '備品'], $group->authzResourceGroupTranslations->map(fn (AuthzResourceGroupTranslation $translation) => $translation->display_name)->all());
        $this->assertEqualsCanonicalizing([
            'read ゲストユーザ', 'create ゲストユーザ', 'update ゲストユーザ', 'delete ゲストユーザ',
            'read 認証済みユーザ', 'create 認証済みユーザ', 'update 認証済みユーザ', 'delete 認証済みユーザ',
        ], $this->policiesOn($group->id));
        $this->assertSame([
            ['global-nav-main-records-equipment', 'global-nav-main', '/records/data/equipment', 'table', 150, ['備品', '備品']],
            ['sitemap-main-records-equipment', 'sitemap-main', '/records/data/equipment', 'table', 150, ['備品', '備品']],
        ], $this->itemsOf('equipment'));
        // 前からあるアイテムの並びは変わらない(プロジェクト 100・課題 110・プロジェクト計画の申請 130 の、2 つのグループの分)
        $items = Menu::query()->where('code', 'like', '%-records-project%');
        $sortNumbers = $items->orderBy('sort_number')->pluck('sort_number');
        $this->assertSame([100, 100, 110, 110, 130, 130], $sortNumbers->all());
    }

    public function test_the_returned_module_carries_the_column_defaults_without_a_reload(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');

        $module = app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品']);

        $this->assertSame([true, 'all'], [$module->is_attachment_enabled, $module->row_access_scope->value]);
    }

    public function test_puts_the_chosen_icon_on_both_menu_items(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');

        $module = app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品', 'icon' => 'clipboard-list']);

        $this->assertSame('clipboard-list', $module->refresh()->icon);
        $this->assertSame(['clipboard-list', 'clipboard-list'], array_column($this->itemsOf('equipment'), 3));
    }

    public function test_every_choice_of_the_enum_is_accepted(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');

        foreach (array_keys(MenuIcon::recordModuleChoices()) as $index => $icon) {
            $module = app(CreateRecordModule::class)->execute(['code' => "equipment{$index}", 'name' => '備品', 'icon' => $icon]);

            $this->assertSame($icon, $module->refresh()->icon);
            $this->assertSame([$icon, $icon], array_column($this->itemsOf("equipment{$index}"), 3));
        }
    }

    public function test_uses_table_when_the_icon_is_blank(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');

        app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品', 'icon' => '  ']);

        $this->assertSame(['table', 'table'], array_column($this->itemsOf('equipment'), 3));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $expected
     */
    #[DataProvider('rejectedInputs')]
    public function test_rejects_an_input_that_breaks_the_rules_and_writes_nothing(array $input, array $expected): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $counts = $this->counts();

        $this->assertSame($expected, $this->validationErrors(fn () => app(CreateRecordModule::class)->execute($input)));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rejects_without_the_set_and_leaves_nothing(): void
    {
        $this->deleteResourceGroupTree(RecordModuleAction::SET_CODE);
        $counts = $this->counts();

        $errors = $this->validationErrors(fn () => app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品']));

        $this->assertSame(['module' => '業務モジュールのセット record-modules がありません'], $errors);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * リソースグループのポリシー(「アクション 対象者の ja の表示名」)。
     *
     * @return list<string>
     */
    private function policiesOn(int $resourceGroupId): array
    {
        return array_values(AuthzPolicy::query()->with('authzSubjectGroup.authzSubjectGroupTranslations.locale')->where('authz_resource_group_id', $resourceGroupId)->get()
            ->map(fn (AuthzPolicy $policy) => $policy->action.' '.$policy->authzSubjectGroup?->authzSubjectGroupTranslations
                ->first(fn (AuthzSubjectGroupTranslation $translation) => $translation->locale?->code === 'ja')?->display_name)
            ->all());
    }

    /**
     * 業務モジュールのアイテム([メニュー ID, 親のメニュー ID, URL, アイコン, 並び, 表示名(ロケールのコードの順)])。
     *
     * @return list<array{string, string|null, string|null, string|null, int, list<string>}>
     */
    private function itemsOf(string $moduleCode): array
    {
        return array_values(Menu::query()->with(['parent', 'menuTranslations.locale'])->where('code', 'like', "%-records-{$moduleCode}")->orderBy('code')->get()
            ->map(fn (Menu $item) => [
                $item->code,
                $item->parent?->code,
                $item->url,
                $item->icon_32,
                $item->sort_number,
                array_values($item->menuTranslations->sortBy(fn (MenuTranslation $translation) => $translation->locale?->code)->map(fn (MenuTranslation $translation) => $translation->display_name)->all()),
            ])
            ->all());
    }

    /**
     * 業務モジュール・リソース・ポリシー・メニューの行の数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [RecordModule::query()->count(), AuthzResource::query()->count(), AuthzPolicy::query()->count(), Menu::query()->count()];
    }
}
