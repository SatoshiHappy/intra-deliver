<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\RecordRowAccessInput;
use App\Actions\Records\UpdateRecordModule;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ChangesModuleAfterLock;
use Tests\Support\TransactionalTestCase;

/**
 * チェックと参照者の書き込み(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の W1a〜W8、規則と業務処理/README.md 9.1・9.2 の 3〜5・9):
 * 表の各行を登録(元 = 業務モジュールの既定と空)と更新(元 = 今の値)で通す。誤りは 422 で何も書かない。書く順(外れから付きでは残っていた行を消す、
 * 外すと行を消す、変えなければ行に触れない)。明細の行は持たない。文言 = 失敗と定義の変更.md 10 節。
 */
final class RecordRowAccessWriteTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use CatchesValidationErrors;
    use ChangesModuleAfterLock;

    private RecordModule $module;

    private User $writer;

    /** @var list<User> */
    private array $users;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->relatedModule('meetings');
        $this->writer = $this->user();
        $this->users = [$this->user(), $this->user(), $this->user()];
        $this->department = $this->department(DepartmentSet::factory()->create());
    }

    public function test_create_w1a_w1b_without_the_keys_takes_the_default_and_no_viewers(): void
    {
        $restricted = $this->create();
        $this->setDefault(false);
        $open = $this->create();

        $this->assertSame([true, []], $this->state($restricted));
        $this->assertSame([false, []], $this->state($open));
    }

    /**
     * 登録の既定は、業務モジュールの共有の鍵の後に読んだ値(鍵の直後に偽 → 真に書き換えると、送らない登録のチェックは真)。
     */
    public function test_create_takes_the_default_read_after_the_module_lock(): void
    {
        $this->setDefault(false);
        $this->changeModuleAfterLock($this->module, ['row_access_restricted_by_default' => true]);

        $this->assertSame([true, []], $this->state($this->create()));
    }

    public function test_create_w2_empty_viewers_keep_the_default(): void
    {
        $this->setDefault(false);

        $this->assertSame([false, []], $this->state($this->create(viewers: [])));
    }

    public function test_create_w3_w8_viewers_on_a_record_without_the_restriction_are_rejected(): void
    {
        $this->setDefault(false);
        $count = Record::query()->count();

        $this->assertSame(
            ['row_access_viewers' => RecordRowAccessInput::NOT_RESTRICTED],
            $this->validationErrors(fn () => $this->create(viewers: [$this->party($this->users[0])])),
        );
        $this->setDefault(true);
        $this->assertSame(
            ['row_access_viewers' => '参照を制限しないレコードには、参照者を設定できません'],
            $this->validationErrors(fn () => $this->create(false, [$this->party($this->users[0])])),
        );
        $this->assertSame($count, Record::query()->count());
    }

    public function test_create_w4_w5_w6_w7(): void
    {
        $viewers = [$this->party($this->users[1]), $this->party($this->department), $this->party($this->users[0])];
        $w4 = $this->create(viewers: $viewers);
        $w5a = $this->create(true);
        $w7 = $this->create(false, []);
        $w7Missing = $this->create(false);
        $this->setDefault(false);
        $w5b = $this->create(true);
        $w6 = $this->create(true, [$this->party($this->users[2])]);

        $this->assertSame([true, $viewers], $this->state($w4));
        $this->assertSame([true, []], $this->state($w5a));
        $this->assertSame([false, []], $this->state($w7));
        $this->assertSame([false, []], $this->state($w7Missing));
        $this->assertSame([true, []], $this->state($w5b));
        $this->assertSame([true, [$this->party($this->users[2])]], $this->state($w6));
    }

    public function test_update_w1a_w1b_without_the_keys_keep_the_current_values_and_rows(): void
    {
        $restricted = $this->create(true, [$this->party($this->users[0]), $this->party($this->department)]);
        $open = $this->create(false);
        $ids = $this->viewerIds($restricted);

        $this->update($restricted);
        $this->update($open);

        $this->assertSame(
            [true, [$this->party($this->users[0]), $this->party($this->department)]],
            $this->state($restricted),
        );
        $this->assertSame($ids, $this->viewerIds($restricted));
        $this->assertSame([false, []], $this->state($open));
    }

    public function test_update_w2_w4_change_the_viewers_and_keep_the_check(): void
    {
        $w2 = $this->create(true, [$this->party($this->users[0])]);
        $w4 = $this->create(true, [$this->party($this->users[0])]);
        $open = $this->create(false);

        $this->update($w2, viewers: []);
        $this->update($w4, viewers: [$this->party($this->department), $this->party($this->users[1])]);
        $this->update($open, viewers: []);

        $this->assertSame([true, []], $this->state($w2));
        $this->assertSame([0], [$this->viewerRows($w2)->count()]);
        $this->assertSame([true, [$this->party($this->department), $this->party($this->users[1])]], $this->state($w4));
        $this->assertSame([false, []], $this->state($open));
    }

    public function test_update_w3_w8_are_rejected_and_nothing_is_written(): void
    {
        $open = $this->create(false);
        $restricted = $this->create(true, [$this->party($this->users[0])]);
        $version = Record::query()->findOrFail($restricted)->version();

        $this->assertSame(
            ['row_access_viewers' => RecordRowAccessInput::NOT_RESTRICTED],
            $this->validationErrors(fn () => $this->update($open, viewers: [$this->party($this->users[1])])),
        );
        $this->assertSame(
            ['row_access_viewers' => RecordRowAccessInput::NOT_RESTRICTED],
            $this->validationErrors(fn () => $this->update($restricted, false, [$this->party($this->users[1])])),
        );
        $this->assertSame([false, []], $this->state($open));
        $this->assertSame([true, [$this->party($this->users[0])]], $this->state($restricted));
        $this->assertSame($version, Record::query()->findOrFail($restricted)->version());
    }

    public function test_update_w5a_keeps_the_viewers_and_w7_removes_them(): void
    {
        $w5a = $this->create(true, [$this->party($this->users[0])]);
        $w7 = $this->create(true, [$this->party($this->users[0])]);
        $w7Empty = $this->create(true, [$this->party($this->users[1])]);
        $ids = $this->viewerIds($w5a);

        $this->update($w5a, true);
        $this->update($w7, false);
        $this->update($w7Empty, false, []);

        $this->assertSame([true, [$this->party($this->users[0])]], $this->state($w5a));
        $this->assertSame($ids, $this->viewerIds($w5a));
        $this->assertSame([[false, []], 0], [$this->state($w7), $this->viewerRows($w7)->count()]);
        $this->assertSame(0, $this->viewerRows($w7Empty)->count());
    }

    /**
     * 9.2 の 9: 元のチェックが外れのレコードに残っていた参照者の行は、付きに変えると消え、入力の行だけになる(W5b・W6)。
     */
    public function test_update_w5b_w6_remove_the_rows_left_on_a_record_without_the_restriction(): void
    {
        $w5b = $this->create(false);
        $w6 = $this->create(false);
        $this->addViewerRows($w5b, [$this->users[0]]);
        $this->addViewerRows($w6, [$this->users[0], $this->users[1]]);

        $this->update($w5b, true);
        $this->update($w6, true, [$this->party($this->users[1]), $this->party($this->users[2])]);

        $this->assertSame([true, []], $this->state($w5b));
        $this->assertSame(0, $this->viewerRows($w5b)->count());
        $this->assertSame([true, [$this->party($this->users[1]), $this->party($this->users[2])]], $this->state($w6));
        $this->assertSame([0, 1], $this->viewerRows($w6)->orderBy('sort_key')->pluck('sort_key')->all());
    }

    public function test_the_same_viewers_are_not_rewritten_and_a_reorder_rewrites_the_rows(): void
    {
        $viewers = [$this->party($this->users[0]), $this->party($this->department)];
        $id = $this->create(true, $viewers);
        $ids = $this->viewerIds($id);

        $this->update($id, true, $viewers);
        $this->assertSame($ids, $this->viewerIds($id));

        $this->update($id, true, array_reverse($viewers));
        $this->assertSame([true, array_reverse($viewers)], $this->state($id));
        $this->assertNotSame($ids, $this->viewerIds($id));
    }

    public function test_the_details_rows_have_neither_the_check_nor_viewers(): void
    {
        $id = $this->create(true, [$this->party($this->users[0])], [['assignee' => $this->users[1]->id]]);
        $row = Record::query()->where('parent_record_id', $id)->sole();

        $this->assertFalse($row->row_access_restricted);
        $this->assertSame(0, $this->viewerRows($row->id)->count());
    }

    /**
     * 業務モジュールの管理 7 節: 既定を変えた後に登録したレコードのチェックの初期値が変わり、今あるレコードは変わらない。
     */
    public function test_changing_the_default_changes_only_the_records_registered_after(): void
    {
        $before = $this->create();
        app(UpdateRecordModule::class)->execute('meetings', ['row_access_restricted_by_default' => false]);
        $after = $this->create();

        $this->assertSame([true, false], [$this->state($before)[0], $this->state($after)[0]]);
    }

    private function setDefault(bool $restricted): void
    {
        $this->module->update(['row_access_restricted_by_default' => $restricted]);
    }

    /**
     * @param  list<array{type: string, id: int}>|null  $viewers
     * @param  list<array<string, mixed>>  $rows
     */
    private function create(mixed $restricted = null, mixed $viewers = null, array $rows = []): int
    {
        $input = new RecordInput(
            ['title' => '定例'],
            $rows === [] ? [] : ['rows' => array_map(fn (array $row) => new RecordDetailRowInput(values: $row), $rows)],
            rowAccessRestricted: $restricted,
            rowAccessViewers: $viewers,
        );

        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $input);
    }

    private function update(int $id, mixed $restricted = null, mixed $viewers = null): void
    {
        $input = new RecordInput(['title' => '定例'], rowAccessRestricted: $restricted, rowAccessViewers: $viewers);
        app(UpdateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $id, $input, null);
    }

    /**
     * @return array{type: string, id: int}
     */
    private function party(User|Department $party): array
    {
        return ['type' => $party instanceof User ? 'user' : 'department', 'id' => $party->id];
    }

    /**
     * チェックと、チェックが付いているときの参照者(sort_key の順。外れなら行を読まずに空 = 読み手の規則)。
     *
     * @return array{bool, list<array{type: 'user'|'department', id: int}>}
     */
    private function state(int $id): array
    {
        $record = Record::query()->findOrFail($id);
        $viewers = $record->row_access_restricted
            ? array_values($record->rowAccessViewers->map(fn (RecordRowAccessViewer $row) => $row->party())->all())
            : [];

        return [$record->row_access_restricted, $viewers];
    }

    /**
     * @return list<int>
     */
    private function viewerIds(int $id): array
    {
        return array_values($this->viewerRows($id)->orderBy('id')->pluck('id')->all());
    }

    /**
     * @return Builder<RecordRowAccessViewer>
     */
    private function viewerRows(int $id): Builder
    {
        return RecordRowAccessViewer::query()->where('record_id', $id);
    }
}
