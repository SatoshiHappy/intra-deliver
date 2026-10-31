<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use Carbon\CarbonImmutable;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 行の判定の変更がその場で効く(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の「絞り方と効く時」、メニューと初期データ.md 8 節の
 * 「変更がその場で効く」): 自分を関係者の項目・参照者から外す・関係者でも参照者でもない人がチェックを付けると、移り先が一覧になり詳細が 404。
 * 所属の期間が終わった日から見えない。業務モジュールの参照制限の既定は判定に入らない。今日を 2026-10-11 に固定する。
 */
final class RecordRowAccessChangesTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use RequestsRowAccessRecords;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_removing_oneself_moves_to_the_list_and_the_detail_is_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $fixture['recordId'];
        $this->actingAs($fixture['people']['attendee']);

        $values = ['attendee' => $fixture['people']['stranger']->id];

        $this->put(self::URL."/{$id}", ['values' => $values, 'version' => $this->versionOf($id)])
            ->assertRedirect(self::URL)
            ->assertSessionHasNoErrors();

        $this->get(self::URL."/{$id}")->assertNotFound();
        $this->actingAs($fixture['people']['stranger'])->get(self::URL."/{$id}")->assertOk();
    }

    public function test_removing_oneself_from_the_viewers_moves_to_the_list_and_the_detail_is_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $fixture['recordId'];
        $this->actingAs($fixture['people']['viewer']);

        $this->put(self::URL."/{$id}", [
            'values' => ['title' => '定例'],
            'version' => $this->versionOf($id),
            'row_access_restricted' => true,
            'row_access_viewers' => [['type' => 'department', 'id' => $fixture['departments']['viewing']->id]],
        ])->assertRedirect(self::URL)->assertSessionHasNoErrors();

        $this->get(self::URL."/{$id}")->assertNotFound();
        $this->actingAs($fixture['people']['viewerMember'])->get(self::URL."/{$id}")->assertOk();
    }

    public function test_restricting_a_record_by_a_person_neither_related_nor_a_viewer_hides_it_from_the_person(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);
        $this->actingAs($fixture['people']['watcher']);
        $this->get(self::URL."/{$openId}")->assertOk();

        $this->put(self::URL."/{$openId}", [
            'values' => ['title' => '公開の会議'],
            'version' => $this->versionOf($openId),
            'row_access_restricted' => true,
        ])->assertRedirect(self::URL)->assertSessionHasNoErrors();

        $this->get(self::URL."/{$openId}")->assertNotFound();
        $this->actingAs($fixture['people']['stranger'])->get(self::URL."/{$openId}")->assertOk();
    }

    public function test_the_record_is_hidden_from_the_day_the_membership_ends(): void
    {
        $fixture = $this->rowAccessFixture(memberTerm: ['2020-01-01', '2026-10-12']);
        $this->actingAs($fixture['people']['mainMember']);

        $this->get(self::URL."/{$fixture['recordId']}")->assertOk();

        CarbonImmutable::setTestNow('2026-10-12 03:00:00 UTC');
        $this->get(self::URL."/{$fixture['recordId']}")->assertNotFound();
    }

    /**
     * 業務モジュールの参照制限の既定は判定に入らない(変えても今あるレコードの見え方は変わらず、変えた後の登録の初期値だけが変わる)。
     */
    public function test_changing_the_default_does_not_change_the_existing_records(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);
        $fixture['module']->update(['row_access_restricted_by_default' => false]);
        $this->actingAs($fixture['people']['watcher']);

        $this->assertTotal(self::URL, 1);
        $this->get(self::URL."/{$fixture['recordId']}")->assertNotFound();

        $fixture['module']->update(['row_access_restricted_by_default' => true]);
        $this->assertTotal(self::URL, 1);
        $this->get(self::URL."/{$openId}")->assertOk();
    }
}
