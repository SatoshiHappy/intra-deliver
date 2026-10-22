<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\DeleteRecordFieldHeading;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\UpdateRecordFieldHeading;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 見出しの追加・変更・削除(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節、6 節の「業務処理」):
 * 明細のモジュールには足せず、名前の空・1000 文字を超えるは 422、見出しを消しても項目は残る、
 * 持ち主のあるレコードがあっても拒まない、見出しが無い・その業務モジュールのでないは 404。
 */
final class RecordFieldHeadingTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function rejectedNames(): array
    {
        return [
            '空' => ['', '見出しの名前を入力してください'],
            '空白だけ' => ['  ', '見出しの名前を入力してください'],
            'null' => [null, '見出しの名前を入力してください'],
            '1001 文字' => [str_repeat('あ', 1001), '見出しの名前は 1000 文字までです'],
            '文字列でない' => [['x'], '見出しの名前は文字列で入力してください'],
        ];
    }

    #[DataProvider('rejectedNames')]
    public function test_rejects_a_bad_name_on_add_and_update_and_writes_nothing(mixed $name, string $message): void
    {
        $module = $this->projects();
        $heading = $this->heading($module, '元', 5);

        $added = $this->validationErrors(fn () => $this->add('projects', $name));
        $updated = $this->validationErrors(fn () => $this->rename($heading, $name));

        $this->assertSame([['name' => $message], ['name' => $message]], [$added, $updated]);
        $this->assertSame([['元', 5]], $this->headingRows($module));
    }

    public function test_accepts_a_name_of_1000_characters_and_trims_the_edges(): void
    {
        $this->projects();

        $long = $this->add('projects', str_repeat('あ', 1000));
        $trimmed = $this->add('projects', '  期間と予算 ');

        $this->assertSame([1000, '期間と予算'], [mb_strlen($long->name), $trimmed->name]);
    }

    public function test_adds_at_the_end_by_the_maximum_of_headings_and_fields_plus_ten(): void
    {
        $module = $this->projects();
        $empty = $this->recordModule('empties');

        $first = $this->add('projects', 'A');
        $second = $this->add('projects', 'B');
        $onlyHeading = $this->add('empties', 'C');
        $this->heading($empty, 'D', 30);
        $afterHeading = $this->add('empties', 'E');

        $keys = [$first->sort_key, $second->sort_key, $onlyHeading->sort_key, $afterHeading->sort_key];
        $this->assertSame([12, 22, 0, 40], $keys);
        $this->assertSame($module->id, $first->record_module_id);
    }

    public function test_rejects_adding_a_heading_to_a_details_module(): void
    {
        $child = $this->childOf($this->detailsField($this->projects(), 'tasks'));

        $errors = $this->validationErrors(fn () => $this->add($child->code, '見出し'));

        $this->assertSame(['module' => '明細には見出しを追加できません'], $errors);
        $this->assertSame([], $this->headingRows($child));
    }

    public function test_updates_only_the_name(): void
    {
        $heading = $this->heading($this->projects(), '元', 5);

        $this->rename($heading, '新');

        $this->assertSame(['新', 5], [$heading->refresh()->name, $heading->sort_key]);
    }

    public function test_deleting_a_heading_keeps_the_fields_and_the_other_headings(): void
    {
        $module = $this->projects();
        $removed = $this->heading($module, '消す', 1);
        $this->heading($module, '残す', 2);

        app(DeleteRecordFieldHeading::class)->execute('projects', $removed->id);

        $this->assertSame([['残す', 2]], $this->headingRows($module));
        $codes = RecordField::query()->where('record_module_id', $module->id)->orderBy('sort_key')->pluck('code');
        $this->assertSame(['title', 'memo', 'due'], $codes->all());
    }

    public function test_a_missing_module_or_heading_or_another_modules_heading_is_not_found(): void
    {
        $this->projects();
        $others = $this->recordModule('others');
        $other = $this->heading($others, '別', 0);
        $missing = $other->id + 1000;
        $delete = fn (string $module, int $id) => fn () => app(DeleteRecordFieldHeading::class)->execute($module, $id);

        foreach ([
            fn () => $this->add('nothing', 'X'),
            fn () => app(UpdateRecordFieldHeading::class)->execute('nothing', $other->id, ['name' => 'X']),
            fn () => app(UpdateRecordFieldHeading::class)->execute('projects', $missing, ['name' => 'X']),
            fn () => app(UpdateRecordFieldHeading::class)->execute('projects', $other->id, ['name' => 'X']),
            $delete('projects', $missing),
            $delete('projects', $other->id),
        ] as $action) {
            try {
                $action();
                $this->fail('404 にならなかった');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([['別', 0]], $this->headingRows($others));
    }

    public function test_a_record_with_a_holder_does_not_block_add_update_delete_or_reorder(): void
    {
        $module = $this->projects();
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $this->registerRecordHolds([$record->id => 'case:1']);

        $heading = $this->add('projects', 'A');
        $this->rename($heading, 'B');
        app(ReorderRecordFields::class)->execute('projects', [
            ['kind' => 'heading', 'id' => $heading->id],
            ['kind' => 'field', 'code' => 'title'],
            ['kind' => 'field', 'code' => 'memo'],
            ['kind' => 'field', 'code' => 'due'],
        ]);
        app(DeleteRecordFieldHeading::class)->execute('projects', $heading->id);

        $this->assertSame([], $this->headingRows($module));
    }

    private function add(string $moduleCode, mixed $name): RecordFieldHeading
    {
        return app(AddRecordFieldHeading::class)->execute($moduleCode, ['name' => $name]);
    }

    private function rename(RecordFieldHeading $heading, mixed $name): RecordFieldHeading
    {
        $moduleCode = RecordModule::query()->findOrFail($heading->record_module_id)->code;

        return app(UpdateRecordFieldHeading::class)->execute($moduleCode, $heading->id, ['name' => $name]);
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
     * 業務モジュールの見出しの [名前, 並び順](並びの順)。
     *
     * @return list<array{string, int}>
     */
    private function headingRows(RecordModule $module): array
    {
        $headings = RecordFieldHeading::query()->where('record_module_id', $module->id)->orderBy('sort_key')->get();

        $rows = $headings->map(fn (RecordFieldHeading $heading) => [$heading->name, $heading->sort_key]);

        return array_values($rows->all());
    }
}
