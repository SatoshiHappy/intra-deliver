<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\CreateRecordModule;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限の HTTP の入口(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2・2.4、メニューと初期データ.md 8 節の「行の権限」): 関係する人だけが
 * 一覧(件数を含む)・詳細・編集の画面・候補・更新・削除を扱え、関係しない人とゲストには見えないレコードが 404 で何も書かれない。read の 403 が先、
 * 壊れた入力でも 404 が 422 より先、選んだ組織に依らない、変更がその場で効く、全件の参照。ルートの認可を外さず、今日を 2026-10-11 に固定する。
 *
 * @phpstan-import-type RowAccessFixture from BuildsRowAccessRecords
 */
final class RecordRowAccessControllerTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    private const URL = '/records/data/meetings';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_related_people_list_open_edit_find_and_update_the_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $fixture['recordId'];

        foreach (self::RELATED as $key) {
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

    public function test_a_related_person_deletes_the_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->actingAs($fixture['people']['concurrentMember']);

        $this->delete(self::URL."/{$fixture['recordId']}")->assertRedirect(self::URL);

        $this->assertFalse(Record::query()->whereKey($fixture['recordId'])->exists());
    }

    public function test_unrelated_people_do_not_find_the_record_and_cannot_write_it(): void
    {
        $fixture = $this->rowAccessFixture();

        foreach (self::UNRELATED as $key) {
            $this->actingAs($fixture['people'][$key]);
            $this->assertHidden($fixture, $key === 'stranger' ? 1 : 0, $key);
        }
    }

    public function test_a_guest_does_not_find_any_record(): void
    {
        $fixture = $this->rowAccessFixture();

        $this->assertHidden($fixture, 0, 'guest');
        $this->get(self::URL."/{$fixture['otherId']}")->assertNotFound();
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

        $this->actingAs($people['childOnly'])->withSession([$key => $departments['child']->id]);
        $this->get($url)->assertNotFound();
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

    public function test_the_record_is_hidden_from_the_day_the_membership_ends(): void
    {
        $fixture = $this->rowAccessFixture(memberTerm: ['2020-01-01', '2026-10-12']);
        $this->actingAs($fixture['people']['mainMember']);

        $this->get(self::URL."/{$fixture['recordId']}")->assertOk();

        CarbonImmutable::setTestNow('2026-10-12 03:00:00 UTC');
        $this->get(self::URL."/{$fixture['recordId']}")->assertNotFound();
    }

    public function test_back_to_everyone_shows_the_record_to_everyone(): void
    {
        $fixture = $this->rowAccessFixture();
        $fixture['module']->update(['row_access_scope' => RowAccessScope::All]);
        $this->actingAs($fixture['people']['watcher']);

        $this->assertTotal(self::URL, 2);
        $this->get(self::URL."/{$fixture['recordId']}")->assertOk();
    }

    /**
     * 全件の参照はセット record-modules の先頭の許可(全部を許すロール)なので、セットの下にある初期データの業務モジュール project で確かめる。
     * 初期データの project のレコードは、u0004 が作った 3 件と、ワークフローの初期データの申請者が作った 11 件(案件の運用の見本の 2 件を含む)。
     */
    public function test_the_system_administrator_reads_every_record(): void
    {
        $project = RecordModule::query()->where('code', 'project')->sole();
        $project->update(['row_access_scope' => RowAccessScope::Related]);
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

    public function test_reading_all_reads_unrelated_records_and_changes_nothing_when_the_scope_is_everyone(): void
    {
        $fixture = $this->rowAccessFixture([...CreateRecordModule::EVERYONE_ACTIONS, RecordModuleAction::ReadAll]);
        $this->actingAs($fixture['people']['watcher']);

        $this->assertTotal(self::URL, 2);

        $fixture['module']->update(['row_access_scope' => RowAccessScope::All]);
        $this->assertTotal(self::URL, 2);
    }

    /**
     * 関係しない人(またはゲスト)から見て、一覧と候補に出ず件数に入らず、詳細・編集の画面・更新・削除が 404 で何も書かれない。
     *
     * @param  RowAccessFixture  $fixture
     * @param  int  $total  一覧の件数(stranger は自分の作った別のレコードを見る)
     */
    private function assertHidden(array $fixture, int $total, string $who): void
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

    private function assertTotal(string $url, int $total): void
    {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('records.total', $total));
    }

    /**
     * 候補の口が返すレコードの id。
     *
     * @return list<int>
     */
    private function candidateIds(): array
    {
        return array_column((array) $this->getJson(self::URL.'/candidates')->assertOk()->json('records'), 'id');
    }

    /**
     * 表示の項目 title だけを変える更新(開いたときの版を送る)。
     *
     * @return TestResponse<Response>
     */
    private function update(int $id, string $title): TestResponse
    {
        return $this->put(self::URL."/{$id}", ['values' => ['title' => $title], 'version' => $this->versionOf($id)]);
    }

    private function versionOf(int $id): string
    {
        return Record::query()->findOrFail($id)->version();
    }

    private function titleOf(RecordModule $module, int $id): mixed
    {
        $titleId = $module->recordFields()->where('code', 'title')->sole()->id;

        return Record::query()->findOrFail($id)->field_values[$titleId] ?? null;
    }
}
