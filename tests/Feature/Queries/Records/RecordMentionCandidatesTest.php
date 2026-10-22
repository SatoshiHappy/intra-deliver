<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordMentionCandidates;
use Tests\Support\BuildsRecordComments;
use Tests\Support\TransactionalTestCase;

/**
 * コメントの宛先の候補(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2 節、9 節の「宛先」の候補の分): そのレコードを
 * 閲覧できる人だけ・共通マスタの候補と同じ並び(sort_key・code)・20 件まで。閲覧できない人がその並びで先に 20 人を超えて並んでも、
 * 閲覧できる人が候補に出る。
 * 兼務の組織でだけ閲覧できる人も出る。「ある組織がカレント組織の人だけが読める」業務モジュールで確かめる。
 */
final class RecordMentionCandidatesTest extends TransactionalTestCase
{
    use BuildsRecordComments;

    /**
     * 閲覧できる人 B01〜B25 は sort_key が名前と逆の順(B25 が先)で、閲覧できない人 A01〜A25 より後ろ。返す順は sort_key の順(名前の順でない)。
     * 絞る語に名前の日本語を含めるのは、候補がユーザーコードも部分一致で見るので、英数字だけの語がほかの人のコードに当たりうるため。
     */
    public function test_lists_only_viewers_in_the_master_order_even_when_non_viewers_come_first(): void
    {
        [$viewing, $other] = [$this->defaultSetDepartment(), $this->defaultSetDepartment()];
        [, $recordId] = $this->departmentOnlyModule('notes', $viewing);

        foreach (range(1, 25) as $number) {
            $viewer = $this->namedUser(sprintf('検索 B%02d', $number));
            $this->join($viewer, $viewing, isMain: true);
            User::query()->whereKey($viewer->id)->update(['sort_key' => 1000 - $number]);
            $nonViewer = $this->namedUser(sprintf('検索 A%02d', $number));
            $this->join($nonViewer, $other, isMain: true);
            User::query()->whereKey($nonViewer->id)->update(['sort_key' => $number]);
        }

        $this->namedUser('検索 C 所属なし');
        $rows = $this->candidates($recordId, '検索');

        $expected = array_map(fn (int $number) => sprintf('検索 B%02d', $number), range(25, 6));
        $this->assertSame($expected, array_column($rows, 'name'));
        $this->assertSame(['id', 'name', 'department'], array_keys($rows[0]));
        $this->assertSame(['検索 B25'], array_column($this->candidates($recordId, '検索 B25'), 'name'));
        $this->assertSame([], $this->candidates($recordId, '検索 A01'));
    }

    public function test_a_person_who_can_view_only_in_a_concurrent_department_is_listed(): void
    {
        [$viewing, $home, $other] = array_map(fn () => $this->defaultSetDepartment(), range(1, 3));
        [, $recordId] = $this->departmentOnlyModule('notes', $viewing);
        $concurrent = $this->namedUser('兼務 の人');
        $this->join($concurrent, $home, isMain: true);
        $this->join($concurrent, $viewing);
        $mainOnly = $this->namedUser('兼務 でない人');
        $this->join($mainOnly, $home, isMain: true);
        $this->join($mainOnly, $other);

        $this->assertSame([$concurrent->id], array_column($this->candidates($recordId, '兼務'), 'id'));
    }

    public function test_a_person_who_is_not_effective_today_is_not_listed(): void
    {
        $viewing = $this->defaultSetDepartment();
        [, $recordId] = $this->departmentOnlyModule('notes', $viewing);
        $this->join($this->namedUser('在籍 の人'), $viewing, isMain: true);
        $this->join($this->namedUser('退職 した人', [['2020-01-01', '2026-10-10', false]]), $viewing, isMain: true);

        $this->assertSame(['在籍 の人'], array_column($this->candidates($recordId, '在籍'), 'name'));
        $this->assertSame([], $this->candidates($recordId, '退職'));
    }

    public function test_an_empty_word_gives_nothing_and_a_row_has_the_name_and_the_main_department(): void
    {
        $recordId = $this->seededProject();

        $this->assertSame([], $this->candidates($recordId, '', 'project'));
        $this->assertSame(
            [['id' => User::query()->where('code', 'u0009')->sole()->id, 'name' => '吉田 達也', 'department' => '総務部']],
            $this->candidates($recordId, '達也', 'project'),
        );
    }

    /**
     * @return list<array{id: int, name: string, department: string|null}>
     */
    private function candidates(int $recordId, string $keyword, string $module = 'notes'): array
    {
        $context = $this->contextFor();
        $candidates = app(RecordMentionCandidates::class);

        return $candidates->candidates($module, $recordId, $keyword, $context->date, $context->localeCode);
    }

    private function seededProject(): int
    {
        $module = RecordModule::query()->where('code', 'project')->sole();

        return Record::query()->where('record_module_id', $module->id)->orderBy('id')->firstOrFail()->id;
    }
}
