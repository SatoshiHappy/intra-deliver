<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\SetRecordRelatedColumns;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRelatedColumn;
use App\Models\Records\RecordRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRelatedRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 関連レコードの列を選ぶ業務処理(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 2 節・9 節の「列」): 入口の行を置き換え、
 * sort_key 1 から並びの順に書く。判定の順 = 業務モジュール 404 → 明細のモジュール 422 module → 入口の項目 404 → 列の 422 columns。版にしない。
 * 列の項目を消すと行も消える。
 *
 * @phpstan-import-type RelatedFixture from BuildsRelatedRecords
 */
final class SetRecordRelatedColumnsTest extends TransactionalTestCase
{
    use BuildsRelatedRecords;
    use CatchesValidationErrors;

    /** @var RelatedFixture */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->relatedFixture();

        foreach (['amount', 'due'] as $code) {
            $this->field($this->f['estimates'], $code);
        }
    }

    public function test_replaces_the_rows_of_the_entry_in_order_and_an_empty_list_resets_them(): void
    {
        $revisions = RecordRevision::query()->count();

        $this->set('estimates', 'customer', ['amount', 'title']);
        $this->assertSame([['amount', 1], ['title', 2]], $this->columnsOf('customer'));

        $this->set('estimates', 'customer', ['due']);
        $this->assertSame([['due', 1]], $this->columnsOf('customer'));

        $this->set('estimates', 'customer', []);
        $this->assertSame([], $this->columnsOf('customer'));
        $this->assertSame($revisions, RecordRevision::query()->count());
    }

    public function test_a_details_entry_is_set_on_the_parent_module_with_its_dotted_code(): void
    {
        $this->set('estimates', 'lines.product', ['title', 'customer']);

        $moduleIds = RecordRelatedColumn::query()->where('record_field_id', $this->f['product']->id)
            ->pluck('record_module_id')->all();
        $this->assertSame([$this->f['estimates']->id, $this->f['estimates']->id], $moduleIds);
        $this->assertSame([['title', 1], ['customer', 2]], $this->columnsOf('product'));
    }

    public function test_a_details_module_is_refused_with_the_key_module(): void
    {
        $child = RecordModule::query()->findOrFail($this->f['lines']->child_record_module_id);

        $this->assertSame(
            ['module' => '明細には設定できません'],
            $this->validationErrors(fn () => $this->set($child->code, 'product', [])),
        );
    }

    #[TestWith(['nothing'])]
    #[TestWith(['title'])]
    #[TestWith(['lines'])]
    #[TestWith(['lines.nothing'])]
    #[TestWith([['customer']])]
    public function test_a_missing_module_or_an_entry_that_is_not_a_record_reference_is_not_found(mixed $entry): void
    {
        foreach ([['nothing_module', 'customer'], ['estimates', $entry]] as [$module, $code]) {
            try {
                app(SetRecordRelatedColumns::class)->execute($module, ['entry' => $code, 'columns' => []]);
                $this->fail('見つかった');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_six_columns_are_refused(): void
    {
        foreach (['c', 'd', 'e', 'g'] as $code) {
            $this->field($this->f['estimates'], $code);
        }

        $this->assertSame(
            ['columns' => '列は 5 つまで選択できます'],
            $this->validationErrors(
                fn () => $this->set('estimates', 'customer', ['title', 'amount', 'due', 'c', 'd', 'e']),
            ),
        );
    }

    /**
     * @param  list<mixed>  $columns
     */
    #[TestWith([['lines']])]
    #[TestWith([['project']])]
    #[TestWith([['title', 'title']])]
    #[TestWith([['nothing']])]
    #[TestWith([[1]])]
    public function test_details_fields_fields_of_another_module_and_duplicates_are_refused(array $columns): void
    {
        $this->assertSame(
            ['columns' => '選択できない項目が含まれています'],
            $this->validationErrors(fn () => $this->set('estimates', 'customer', $columns)),
        );
        $this->assertSame([], $this->columnsOf('customer'));
    }

    #[TestWith([null])]
    #[TestWith(['title'])]
    #[TestWith([['a' => 'title']])]
    public function test_columns_that_are_not_a_list_are_refused(mixed $columns): void
    {
        $this->assertSame(
            ['columns' => '列の指定が正しくありません'],
            $this->validationErrors(fn () => app(SetRecordRelatedColumns::class)
                ->execute('estimates', ['entry' => 'customer', 'columns' => $columns])),
        );
    }

    public function test_deleting_a_column_field_removes_its_rows(): void
    {
        $this->set('estimates', 'customer', ['amount', 'title']);

        app(DeleteRecordField::class)->execute('estimates', 'amount');

        $this->assertSame([['title', 2]], $this->columnsOf('customer'));
    }

    public function test_each_entry_keeps_its_own_columns(): void
    {
        $this->set('estimates', 'customer', ['amount']);

        $this->set('estimates', 'billing', ['due']);

        $this->assertSame([['amount', 1]], $this->columnsOf('customer'));
        $this->assertSame([['due', 1]], $this->columnsOf('billing'));
    }

    /**
     * @param  list<mixed>  $columns
     */
    private function set(string $module, string $entry, array $columns): void
    {
        app(SetRecordRelatedColumns::class)->execute($module, ['entry' => $entry, 'columns' => $columns]);
    }

    /**
     * 入口の項目の列(項目のコードと sort_key。並びの順)。
     *
     * @return list<array{string, int}>
     */
    private function columnsOf(string $entryField): array
    {
        return array_values(RecordRelatedColumn::query()
            ->where('record_field_id', $this->f[$entryField]->id)
            ->with('columnRecordField')
            ->orderBy('sort_key')
            ->get()
            ->map(fn (RecordRelatedColumn $row) => [(string) $row->columnRecordField?->code, $row->sort_key])
            ->all());
    }
}
