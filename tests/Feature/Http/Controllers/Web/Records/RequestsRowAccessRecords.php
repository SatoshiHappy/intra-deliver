<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRowAccessRecords;

/**
 * 行の権限の HTTP の入口のテストの要求と確かめ(業務モジュール meetings = {@see BuildsRowAccessRecords})。
 *
 * @phpstan-import-type RowAccessFixture from BuildsRowAccessRecords
 */
trait RequestsRowAccessRecords
{
    private const URL = '/records/data/meetings';

    /**
     * 関係者でも参照者でもない人(またはゲスト)から見て、一覧と候補に出ず件数に入らず、詳細・編集の画面・更新・削除が 404 で何も書かれない。
     *
     * @param  RowAccessFixture  $fixture
     * @param  int  $total  一覧の件数(stranger は自分の作った別のレコード、ゲストは参照制限の無いレコードを見る)
     */
    protected function assertHidden(array $fixture, int $total, string $who): void
    {
        $id = $fixture['recordId'];

        $this->assertTotal(self::URL, $total);
        $this->assertNotContains($id, $this->candidateIds(), $who);
        $this->get(self::URL."/{$id}")->assertNotFound();
        $this->get(self::URL."/{$id}/edit")->assertNotFound();
        $this->update($id, '書き換え')->assertNotFound();
        $this->delete(self::URL."/{$id}")->assertNotFound();
        $this->assertSame('定例', $this->titleOf($fixture['module'], $id), $who);
    }

    protected function assertTotal(string $url, int $total): void
    {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('records.total', $total));
    }

    /**
     * 候補の口が返すレコードの id。
     *
     * @return list<int>
     */
    protected function candidateIds(): array
    {
        return array_column((array) $this->getJson(self::URL.'/candidates')->assertOk()->json('records'), 'id');
    }

    /**
     * 表示の項目 title だけを変える更新(開いたときの版を送る)。
     *
     * @return TestResponse<Response>
     */
    protected function update(int $id, string $title): TestResponse
    {
        return $this->put(self::URL."/{$id}", ['values' => ['title' => $title], 'version' => $this->versionOf($id)]);
    }

    protected function versionOf(int $id): string
    {
        return Record::query()->findOrFail($id)->version();
    }

    protected function titleOf(RecordModule $module, int $id): mixed
    {
        $titleId = $module->recordFields()->where('code', 'title')->sole()->id;

        return Record::query()->findOrFail($id)->field_values[$titleId] ?? null;
    }
}
