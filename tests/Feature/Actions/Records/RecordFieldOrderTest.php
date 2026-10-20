<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\UpdateRecordField;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の並び替えと、足す項目の並び順の既定(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、画面と検査.md 13 節の「項目の並び替えと追加の並び順」)。
 */
final class RecordFieldOrderTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    /** 並べ替えの誤りの文言 */
    private const NOT_PERMUTATION = '項目の並びが今の項目と一致しません。画面を再読み込みしてから再度操作してください';

    /** 並び順の上限(共通の規則 4 節) */
    private const SORT_KEY_MAX = 999_999_999_999_999;

    /**
     * 業務モジュール projects(title・memo・due の 3 項目)に送る codes のうち、全項目のコードの順列でないもの。
     *
     * @return array<string, array{list<mixed>}>
     */
    public static function notPermutations(): array
    {
        return [
            '足りない' => [['due', 'title']],
            '多い' => [['due', 'title', 'memo', 'extra']],
            '重複' => [['due', 'title', 'title']],
            '重複で数を合わせる' => [['due', 'title', 'title', 'memo']],
            'ほかの業務モジュールのコード' => [['due', 'title', 'note']],
            '文字列でない' => [['due', 'title', ['memo']]],
            '空' => [[]],
        ];
    }

    public function test_renumbers_the_sort_keys_in_the_given_order_by_tens(): void
    {
        $module = $this->projects();

        app(ReorderRecordFields::class)->execute('projects', ['due', 'title', 'memo']);

        $this->assertSame(['due' => 0, 'title' => 10, 'memo' => 20], $this->sortKeys($module));
    }

    public function test_the_same_order_succeeds_and_renumbers(): void
    {
        $module = $this->projects();

        app(ReorderRecordFields::class)->execute('projects', ['title', 'memo', 'due']);

        $this->assertSame(['title' => 0, 'memo' => 10, 'due' => 20], $this->sortKeys($module));
    }

    public function test_reorders_the_fields_of_a_details_module(): void
    {
        $child = $this->childOf($this->detailsField($this->projects(), 'tasks'));
        $this->field($child, 'hours', attributes: ['sort_key' => 0]);
        $this->field($child, 'note', attributes: ['sort_key' => 1]);

        app(ReorderRecordFields::class)->execute('tasks_rows', ['note', 'hours']);

        $this->assertSame(['note' => 0, 'hours' => 10], $this->sortKeys($child));
    }

    /**
     * @param  list<mixed>  $codes
     */
    #[DataProvider('notPermutations')]
    public function test_rejects_codes_that_are_not_a_permutation_and_writes_nothing(array $codes): void
    {
        $module = $this->projects();
        $this->field($this->recordModule('others'), 'note');
        $before = $this->sortKeys($module);

        $this->assertSame(['codes' => self::NOT_PERMUTATION], $this->validationErrors(fn () => app(ReorderRecordFields::class)->execute('projects', $codes)));
        $this->assertSame($before, $this->sortKeys($module));
    }

    public function test_a_new_field_without_a_sort_key_goes_after_the_last_field(): void
    {
        $module = $this->projects();
        $this->recordModule('empties');

        $added = app(AddRecordField::class)->execute('projects', ['code' => 'owner', 'name' => '担当', 'type' => 'string']);
        $first = app(AddRecordField::class)->execute('empties', ['code' => 'owner', 'name' => '担当', 'type' => 'string']);
        $given = app(AddRecordField::class)->execute('projects', ['code' => 'place', 'name' => '場所', 'type' => 'string', 'sort_key' => 3]);

        $this->assertSame([12, 0, 3], [$added->sort_key, $first->sort_key, $given->sort_key]);
        $this->assertSame(['title' => 0, 'memo' => 1, 'due' => 2, 'place' => 3, 'owner' => 12], $this->sortKeys($module));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unspecifiedSortKeys(): array
    {
        return ['空文字' => [''], 'null' => [null]];
    }

    #[DataProvider('unspecifiedSortKeys')]
    public function test_an_empty_or_null_sort_key_is_unspecified_so_a_new_field_goes_last_and_a_changed_one_keeps_its_value(mixed $sortKey): void
    {
        $module = $this->projects();

        $added = app(AddRecordField::class)->execute('projects', ['code' => 'owner', 'name' => '担当', 'type' => 'string', 'sort_key' => $sortKey]);
        $changed = app(UpdateRecordField::class)->execute('projects', 'memo', ['name' => 'メモ', 'sort_key' => $sortKey]);

        $this->assertSame([12, 1], [$added->sort_key, $changed->sort_key]);
        $this->assertSame(['title' => 0, 'memo' => 1, 'due' => 2, 'owner' => 12], $this->sortKeys($module));
    }

    public function test_rejects_a_new_field_when_the_default_sort_key_passes_the_limit_and_writes_nothing(): void
    {
        $module = $this->projects();
        $this->field($module, 'last', attributes: ['sort_key' => self::SORT_KEY_MAX - 9]);
        $fields = RecordField::query()->count();

        $errors = $this->validationErrors(fn () => app(AddRecordField::class)->execute('projects', ['code' => 'owner', 'name' => '担当', 'type' => 'string']));
        app(ReorderRecordFields::class)->execute('projects', ['title', 'memo', 'due', 'last']);
        $added = app(AddRecordField::class)->execute('projects', ['code' => 'owner', 'name' => '担当', 'type' => 'string']);

        $this->assertSame(['sort_key' => '並び順が上限に達しています。項目の表で並び順を変更すると解消されます'], $errors);
        $this->assertSame([$fields + 1, 40], [RecordField::query()->count(), $added->sort_key]);
    }

    /**
     * 業務モジュール projects(並び順 0, 1, 2 の title・memo・due)。
     */
    private function projects(): RecordModule
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['sort_key' => 0]);
        $this->field($module, 'memo', attributes: ['sort_key' => 1]);
        $this->field($module, 'due', attributes: ['sort_key' => 2]);

        return $module;
    }

    /**
     * 項目のコード → 並び順(並び順・コードの順)。
     *
     * @return array<string, int>
     */
    private function sortKeys(RecordModule $module): array
    {
        return RecordField::query()->where('record_module_id', $module->id)->orderBy('sort_key')->orderBy('code')->pluck('sort_key', 'code')->all();
    }
}
