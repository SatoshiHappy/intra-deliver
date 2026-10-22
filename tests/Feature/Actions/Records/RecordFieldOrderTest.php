<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\UpdateRecordField;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordHeadingGroups;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 項目と見出しの並び替えと、足す項目の並び順の既定(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、画面と検査.md 13 節の「項目の並び替えと追加の並び順」、
 * レコードの共通機能/詳細の画面/見出し.md の 4 節・6 節の「業務処理」)。
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
     * 業務モジュール projects(title・memo・due の 3 項目と見出し 1 つ)に送る items のうち、全見出しと全項目の順列でないもの。
     * 値は (projects の見出しの id, ほかの業務モジュールの見出しの id) から items を作る関数。
     *
     * @return array<string, array{Closure(int, int): list<mixed>}>
     */
    public static function notPermutations(): array
    {
        $f = fn (string $code): array => ['kind' => 'field', 'code' => $code];
        $h = fn (int|string $id): array => ['kind' => 'heading', 'id' => $id];

        return [
            '項目が足りない' => [fn (int $own) => [$f('due'), $f('title'), $h($own)]],
            '見出しが足りない' => [fn () => [$f('due'), $f('title'), $f('memo')]],
            '多い' => [fn (int $own) => [$f('due'), $f('title'), $f('memo'), $h($own), $f('extra')]],
            '重複' => [fn (int $own) => [$f('due'), $f('title'), $f('title'), $h($own)]],
            '重複で数を合わせる' => [fn (int $own) => [$f('due'), $f('title'), $f('title'), $f('memo'), $h($own)]],
            '見出しの重複' => [fn (int $own) => [$f('due'), $f('title'), $f('memo'), $h($own), $h($own)]],
            'ほかの業務モジュールのコード' => [fn (int $own) => [$f('due'), $f('title'), $f('note'), $h($own)]],
            'ほかの業務モジュールの見出し' => [fn (int $own, int $other) => [$f('due'), $f('title'), $f('memo'), $h($other)]],
            'コードを見出しとして送る' => [fn () => [$f('due'), $f('title'), $f('memo'), ['kind' => 'heading', 'code' => 'x']]],
            '見出しの id が文字列' => [fn (int $own) => [$f('due'), $f('title'), $f('memo'), $h((string) $own)]],
            '種類が違う' => [fn (int $own) => [$f('due'), $f('title'), $f('memo'), ['kind' => 'group', 'id' => $own]]],
            '文字列でない' => [fn (int $own) => [$f('due'), $f('title'), ['memo'], $h($own)]],
            '空' => [fn () => []],
        ];
    }

    public function test_renumbers_the_sort_keys_in_the_given_order_by_tens(): void
    {
        $module = $this->projects();

        app(ReorderRecordFields::class)->execute('projects', $this->fieldItems(['due', 'title', 'memo']));

        $this->assertSame(['due' => 0, 'title' => 10, 'memo' => 20], $this->sortKeys($module));
    }

    public function test_renumbers_headings_and_fields_as_one_sequence_by_tens(): void
    {
        $module = $this->projects();
        $first = $this->heading($module, 'A', 5);
        $second = $this->heading($module, 'B', 6);

        app(ReorderRecordFields::class)->execute('projects', [
            ...$this->fieldItems(['due']),
            ['kind' => 'heading', 'id' => $second->id],
            ...$this->fieldItems(['title']),
            ['kind' => 'heading', 'id' => $first->id],
            ...$this->fieldItems(['memo']),
        ]);

        $this->assertSame(['due' => 0, 'title' => 20, 'memo' => 40], $this->sortKeys($module));
        $headings = RecordFieldHeading::query()->where('record_module_id', $module->id)->pluck('sort_key', 'id');
        $this->assertSame([$first->id => 30, $second->id => 10], $headings->all());
    }

    public function test_the_same_order_succeeds_and_renumbers(): void
    {
        $module = $this->projects();

        app(ReorderRecordFields::class)->execute('projects', $this->fieldItems(['title', 'memo', 'due']));

        $this->assertSame(['title' => 0, 'memo' => 10, 'due' => 20], $this->sortKeys($module));
    }

    public function test_reorders_the_fields_of_a_details_module(): void
    {
        $child = $this->childOf($this->detailsField($this->projects(), 'tasks'));
        $this->field($child, 'hours', attributes: ['sort_key' => 0]);
        $this->field($child, 'note', attributes: ['sort_key' => 1]);

        app(ReorderRecordFields::class)->execute('tasks_rows', $this->fieldItems(['note', 'hours']));

        $this->assertSame(['note' => 0, 'hours' => 10], $this->sortKeys($child));
    }

    public function test_a_details_module_has_no_headings_so_a_heading_item_is_rejected(): void
    {
        $child = $this->childOf($this->detailsField($this->projects(), 'tasks'));
        $this->field($child, 'hours', attributes: ['sort_key' => 0]);
        $other = $this->heading($this->recordModule('others'), 'X', 0);

        $items = [['kind' => 'heading', 'id' => $other->id], ...$this->fieldItems(['hours'])];
        $errors = $this->validationErrors(fn () => app(ReorderRecordFields::class)->execute('tasks_rows', $items));

        $this->assertSame(['items' => self::NOT_PERMUTATION], $errors);
    }

    /**
     * @param  Closure(int, int): list<mixed>  $makeItems
     */
    #[DataProvider('notPermutations')]
    public function test_rejects_items_that_are_not_a_permutation_and_writes_nothing(Closure $makeItems): void
    {
        $module = $this->projects();
        $heading = $this->heading($module, 'A', 1);
        $others = $this->recordModule('others');
        $other = $this->heading($others, 'X', 0);
        $this->field($others, 'note');
        $before = [$this->sortKeys($module), $heading->refresh()->sort_key];
        $items = $makeItems($heading->id, $other->id);

        $errors = $this->validationErrors(fn () => app(ReorderRecordFields::class)->execute('projects', $items));

        $this->assertSame(['items' => self::NOT_PERMUTATION], $errors);
        $this->assertSame($before, [$this->sortKeys($module), $heading->refresh()->sort_key]);
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

    public function test_a_field_added_after_a_heading_added_at_the_end_goes_into_that_headings_group(): void
    {
        $module = $this->projects();

        $heading = app(AddRecordFieldHeading::class)->execute('projects', ['name' => '補足']);
        $owner = ['code' => 'owner', 'name' => '担当', 'type' => 'string'];
        $added = app(AddRecordField::class)->execute('projects', $owner);

        $this->assertSame([12, 22], [$heading->sort_key, $added->sort_key]);
        $this->assertSame([
            ['id' => null, 'name' => null, 'fieldCodes' => ['title', 'memo', 'due']],
            ['id' => $heading->id, 'name' => '補足', 'fieldCodes' => ['owner']],
        ], app(RecordHeadingGroups::class)->groups($module->id));
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
        app(ReorderRecordFields::class)->execute('projects', $this->fieldItems(['title', 'memo', 'due', 'last']));
        $added = app(AddRecordField::class)->execute('projects', ['code' => 'owner', 'name' => '担当', 'type' => 'string']);

        $this->assertSame(['sort_key' => '並び順が上限に達しています。項目の表で並び順を変更すると解消されます'], $errors);
        $this->assertSame([$fields + 1, 40], [RecordField::query()->count(), $added->sort_key]);
    }

    public function test_the_default_sort_key_counts_headings_and_a_heading_past_the_limit_is_rejected_too(): void
    {
        $module = $this->projects();
        $this->heading($module, '末尾', self::SORT_KEY_MAX - 9);

        $owner = ['code' => 'owner', 'name' => '担当', 'type' => 'string'];
        $fieldErrors = $this->validationErrors(fn () => app(AddRecordField::class)->execute('projects', $owner));
        $heading = ['name' => '補足'];
        $addHeading = fn () => app(AddRecordFieldHeading::class)->execute('projects', $heading);
        $headingErrors = $this->validationErrors($addHeading);

        $limit = ['sort_key' => '並び順が上限に達しています。項目の表で並び順を変更すると解消されます'];
        $this->assertSame([$limit, $limit], [$fieldErrors, $headingErrors]);
        $this->assertSame(3, RecordField::query()->where('record_module_id', $module->id)->count());
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

    private function heading(RecordModule $module, string $name, int $sortKey): RecordFieldHeading
    {
        return RecordFieldHeading::factory()->create([
            'record_module_id' => $module->id,
            'name' => $name,
            'sort_key' => $sortKey,
        ]);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{kind: 'field', code: string}>
     */
    private function fieldItems(array $codes): array
    {
        return array_map(fn (string $code) => ['kind' => 'field', 'code' => $code], $codes);
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
