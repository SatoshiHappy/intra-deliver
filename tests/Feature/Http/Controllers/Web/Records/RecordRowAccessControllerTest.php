<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\CreateRecordModule;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限の HTTP の入口(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2・行の権限.md 2.4 のデシジョン表 R1〜R7、ADR 0026、
 * メニューと初期データ.md 8 節の「行の権限」): 参照制限のあるレコードは関係者(R4・R5)・参照者(R6)・全件の参照の人(R2)だけが一覧(件数を含む)・
 * 詳細・編集の画面・候補・更新・削除を扱え、関係者でも参照者でもない人(R7)・ゲストと d に有効なユーザーの無い人(R3)には 404 で何も書かれない。
 * 参照制限の無いレコード(R1)は同じ業務モジュールでゲストにも見える。read の 403 が先、壊れた入力でも 404 が 422 より先、選んだ組織に依らない、
 * 変更がその場で効く、業務モジュールの既定は判定に入らない。ルートの認可を外さず、今日を 2026-10-11 に固定する。
 *
 * @phpstan-import-type RowAccessFixture from BuildsRowAccessRecords
 */
final class RecordRowAccessControllerTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use RequestsRowAccessRecords;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_related_people_and_viewers_list_open_edit_find_and_update_the_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $fixture['recordId'];

        foreach ([...self::RELATED, ...self::VIEWERS] as $key) {
            $this->actingAs($fixture['people'][$key]);

            $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->where('records.data.0.id', $id));
            $this->get(self::URL."/{$id}")->assertOk();
            $this->get(self::URL."/{$id}/edit")->assertOk();
            $this->assertSame([$id], $this->candidateIds(), $key);
            $this->update($id, "{$key} が更新")->assertRedirect(self::URL."/{$id}");
            $this->assertSame("{$key} が更新", $this->titleOf($fixture['module'], $id));
        }
    }

    public function test_a_related_person_and_a_viewer_delete_the_records(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->actingAs($fixture['people']['concurrentMember']);

        $this->delete(self::URL."/{$fixture['recordId']}")->assertRedirect(self::URL);

        $this->assertFalse(Record::query()->whereKey($fixture['recordId'])->exists());

        $viewed = $this->createMeeting($fixture['people']['stranger'], '別の定例', [], [], viewers: [
            ['type' => 'department', 'id' => $fixture['departments']['viewing']->id],
        ]);
        $this->actingAs($fixture['people']['viewerConcurrent']);

        $this->delete(self::URL."/{$viewed}")->assertRedirect(self::URL);

        $this->assertFalse(Record::query()->whereKey($viewed)->exists());
    }

    public function test_unrelated_people_do_not_find_the_record_and_cannot_write_it(): void
    {
        $fixture = $this->rowAccessFixture();

        foreach (self::UNRELATED as $key) {
            $this->actingAs($fixture['people'][$key]);
            $this->assertHidden($fixture, $key === 'stranger' ? 1 : 0, $key);
        }
    }

    public function test_a_guest_finds_only_the_record_without_the_restriction_in_the_same_module(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);

        $this->assertHidden($fixture, 1, 'guest');
        $this->get(self::URL."/{$fixture['otherId']}")->assertNotFound();
        $this->get(self::URL."/{$openId}")->assertOk();
        $this->assertSame([$openId], $this->candidateIds());
        $this->update($openId, 'ゲストが更新')->assertRedirect(self::URL."/{$openId}");
    }

    /**
     * R3: d に有効なユーザーが無い人(退職・入社前)は、作成者・関係者の印の項目・参照者に入っていても、参照制限のあるレコードを見られない
     * (参照制限の無いレコードは見られる)。
     */
    public function test_people_without_a_user_effective_on_the_date_find_only_the_unrestricted_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $openId = $this->openMeeting($fixture);
        $retired = $this->user([['2020-01-01', '2026-10-01']]);
        $future = $this->user([['2026-11-01', null]]);
        $this->addViewerRows($fixture['recordId'], [$retired, $future]);
        $created = Record::factory()->create([
            'record_module_id' => $fixture['module']->id,
            'created_by_user_id' => $retired->id,
        ]);
        RecordReference::query()->create([
            'record_module_id' => $fixture['module']->id,
            'record_id' => $created->id,
            'record_field_id' => $fixture['module']->recordFields()->where('code', 'attendee')->sole()->id,
            'user_id' => $future->id,
        ]);
        $this->assertTrue($created->row_access_restricted);

        foreach ([$retired, $future] as $person) {
            $this->actingAs($person);
            $this->assertTotal(self::URL, 1);
            $this->get(self::URL."/{$fixture['recordId']}")->assertNotFound();
            $this->get(self::URL."/{$created->id}")->assertNotFound();
            $this->get(self::URL."/{$openId}")->assertOk();
        }
    }

    public function test_without_read_the_forbidden_comes_before_the_row_judgement(): void
    {
        $fixture = $this->rowAccessFixture([RecordModuleAction::Create, RecordModuleAction::Update]);
        $this->actingAs($fixture['people']['stranger']);

        $this->get(self::URL)->assertForbidden();
        $this->get(self::URL."/{$fixture['recordId']}")->assertForbidden();
        $this->getJson(self::URL.'/candidates')->assertForbidden();
    }

    public function test_a_broken_input_is_not_found_before_it_is_rejected(): void
    {
        $fixture = $this->rowAccessFixture();
        $url = self::URL."/{$fixture['recordId']}";
        $this->actingAs($fixture['people']['watcher']);

        $this->put($url, ['values' => 'broken', 'details' => 'broken'])->assertNotFound();

        $this->actingAs($fixture['people']['attendee']);
        $this->put($url, ['values' => 'broken'])->assertSessionHasErrors('values');
    }

    public function test_the_selected_department_does_not_change_the_result(): void
    {
        $fixture = $this->rowAccessFixture();
        $url = self::URL."/{$fixture['recordId']}";
        $key = AccessContext::CURRENT_DEPARTMENT_SESSION_KEY;
        [$people, $departments] = [$fixture['people'], $fixture['departments']];

        $this->actingAs($people['concurrentMember'])->withSession([$key => $departments['other']->id]);
        $this->get($url)->assertOk();

        $this->actingAs($people['viewerConcurrent'])->withSession([$key => $departments['other']->id]);
        $this->get($url)->assertOk();

        $this->actingAs($people['childOnly'])->withSession([$key => $departments['child']->id]);
        $this->get($url)->assertNotFound();
    }

    /**
     * 全件の参照はセット record-modules の先頭の許可(全部を許すロール)なので、セットの下にある初期データの業務モジュール project で確かめる。
     * 初期データの project のレコードは、u0004 が作った 3 件と、ワークフローの初期データの申請者が作った 11 件(案件の運用の見本の 2 件を含む)。
     */
    public function test_the_system_administrator_reads_every_record(): void
    {
        $project = RecordModule::query()->where('code', 'project')->sole();
        RowAccessRestriction::restrict([$project->id]);
        $u0004 = User::query()->where('code', 'u0004')->sole();
        $applied = Record::query()->where('record_module_id', $project->id)
            ->where('created_by_user_id', '<>', $u0004->id)
            ->firstOrFail();
        $this->actingAs($u0004);

        $this->assertTotal('/records/data/project', 14);
        $this->get("/records/data/project/{$applied->id}")->assertOk();

        AuthzPolicy::query()->where('action', RecordModuleAction::ReadAll->value)->delete();
        $this->assertTotal('/records/data/project', 3);
        $this->get("/records/data/project/{$applied->id}")->assertNotFound();
    }

    public function test_reading_all_without_read_is_forbidden(): void
    {
        $fixture = $this->rowAccessFixture([RecordModuleAction::ReadAll, RecordModuleAction::Create]);
        $this->actingAs($fixture['people']['stranger']);

        $this->get(self::URL)->assertForbidden();
        $this->get(self::URL."/{$fixture['recordId']}")->assertForbidden();
    }

    public function test_reading_all_reads_the_restricted_records_of_people_neither_related_nor_viewers(): void
    {
        $fixture = $this->rowAccessFixture([...CreateRecordModule::EVERYONE_ACTIONS, RecordModuleAction::ReadAll]);
        $this->openMeeting($fixture);
        $this->actingAs($fixture['people']['watcher']);

        $this->assertTotal(self::URL, 3);
        $this->get(self::URL."/{$fixture['recordId']}")->assertOk();
        $this->assertContains($fixture['recordId'], $this->candidateIds());
    }
}
