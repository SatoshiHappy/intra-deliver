<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の画面の参照制限の props(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2・2.3、
 * 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節・5 節、設計/ワークフロー/業務データとの接続/公開の口.md の 7 節の editValues の行):
 * 登録 = rowAccess(業務モジュールの既定と空)、編集・詳細 = record.rowAccess(今の値。参照者は相手の種類と参照の値)、複製 = copySource.rowAccess
 * (元のレコードの値。業務モジュールの既定と違っても)。editValues の rowAccessRestricted = そのレコードのチェック。今日を 2026-10-11 に固定する。
 *
 * @phpstan-import-type RowAccessFixture from BuildsRowAccessRecords
 */
final class RecordRowAccessPropsTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    private const URL = '/records/data/meetings';

    /** 画面の参照者の 1 つの値のキー(型「ユーザーと組織」の値と同じ形)。 */
    private const SHOWN_KEYS = ['type', 'id', 'code', 'label', 'affiliation'];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_the_create_page_carries_the_default_and_no_viewers(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->actingAs($fixture['people']['creator']);

        $this->get(self::URL.'/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rowAccess', ['restricted' => true, 'viewers' => []]));

        $fixture['module']->update(['row_access_restricted_by_default' => false]);
        $this->get(self::URL.'/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rowAccess', ['restricted' => false, 'viewers' => []]));
    }

    public function test_the_detail_and_edit_pages_carry_the_current_check_and_viewers(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->actingAs($fixture['people']['creator']);
        $id = $fixture['recordId'];
        $expected = $this->viewersOf($fixture);

        foreach (["/{$id}", "/{$id}/edit"] as $path) {
            $this->get(self::URL.$path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('record.rowAccess.restricted', true)
                ->where('record.rowAccess.viewers', fn ($viewers) => $this->partiesOf($viewers) === $expected)
                ->where('record.rowAccess.viewers.1', fn ($one) => $this->keysOf($one) === self::SHOWN_KEYS));
        }

        $openId = $this->openMeeting($fixture);
        $this->get(self::URL."/{$openId}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.rowAccess', ['restricted' => false, 'viewers' => []]));
    }

    public function test_the_copy_carries_the_values_of_the_source_and_not_the_default(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);
        $fixture['module']->update(['row_access_restricted_by_default' => false]);
        $this->actingAs($fixture['people']['creator']);
        $expected = $this->viewersOf($fixture);

        $copy = self::URL."/create?copy={$fixture['recordId']}";
        $this->get($copy)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rowAccess', ['restricted' => false, 'viewers' => []])
            ->where('copySource.rowAccess.restricted', true)
            ->where('copySource.rowAccess.viewers', fn ($viewers) => $this->partiesOf($viewers) === $expected));

        $fixture['module']->update(['row_access_restricted_by_default' => true]);
        $this->get(self::URL."/create?copy={$openId}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('copySource.rowAccess', ['restricted' => false, 'viewers' => []]));
    }

    public function test_the_edit_values_carry_the_check_of_the_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);
        $forms = app(RecordFormQuery::class);
        $requester = new Requester($fixture['people']['creator']->id, null);
        $date = CarbonImmutable::parse('2026-10-11');

        $restricted = $forms->editValues($requester, 'meetings', $fixture['recordId'], $date, 'ja');
        $this->assertTrue($restricted['rowAccessRestricted']);
        $this->assertFalse($forms->editValues($requester, 'meetings', $openId, $date, 'ja')['rowAccessRestricted']);
    }

    /**
     * fixture の参照者(並びの順。相手の種類と id)。
     *
     * @param  RowAccessFixture  $fixture
     * @return list<array{string, int}>
     */
    private function viewersOf(array $fixture): array
    {
        return [['user', $fixture['people']['viewer']->id], ['department', $fixture['departments']['viewing']->id]];
    }

    /**
     * @return list<array-key>|null
     */
    private function keysOf(mixed $value): ?array
    {
        return $value instanceof Collection ? array_keys($value->all()) : null;
    }

    /**
     * 画面の参照者の値から相手の種類と id を取り出す。
     *
     * @return list<array{string, int}>
     */
    private function partiesOf(mixed $viewers): array
    {
        $items = is_iterable($viewers) ? collect($viewers)->all() : [];

        $parties = array_map(fn (mixed $one) => [(string) data_get($one, 'type'), (int) data_get($one, 'id')], $items);

        return array_values($parties);
    }
}
