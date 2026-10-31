<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請の経路は行の判定を見ない(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2 の「ワークフローの経路の例外」・行の権限.md 2.4 の「申請の経路は行の判定を
 * 見ない」、ADR 0018 の決定 7): 業務モジュールの read も行の判定も通らない申請の関係者が、未完了の申請の詳細でレコードの中身を見る。持ち主の鍵を渡す更新
 * (ワークフローの再申請の呼び方)は行の判定を見ずに通り、業務データの入れ物の入口の更新(鍵 null)は同じレコードが 404。鍵を渡したのに
 * そのレコードに持ち主がいなければ呼び手の誤り(規則と業務処理/README.md 9.2 の 2 の更新と添付の結果の表)。
 */
final class RecordRowAccessApplicationTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use RegistersRecordHolds;
    use UsesSeededCases;
    use UsesSeededRecords;

    /**
     * 拒む理由を条件ごとに分けて確かめる: 同じレコードを、その業務モジュールの URL で、参照制限を外すと開ける(対照)・付けると 404・
     * 制限が無くても read を外すと 403。どちらも外せない状態でも、申請の詳細では中身が見える。
     */
    public function test_a_participant_sees_the_record_of_an_active_case_without_read_or_relation(): void
    {
        $this->withoutVite();
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $u0002 = User::query()->where('code', 'u0002')->sole();
        $record = Record::query()->findOrFail($case->record_id);
        $code = RecordModule::query()->findOrFail($record->record_module_id)->code;
        $url = "/records/data/{$code}/{$record->id}";
        $restrict = fn (bool $restricted) => Record::query()->whereKey($record->id)
            ->update(['row_access_restricted' => $restricted]);
        $this->assertNotSame($u0002->id, $record->created_by_user_id);
        $this->assertTrue($record->row_access_restricted);
        $this->actingAs($u0002);

        $restrict(false);
        $this->get($url)->assertOk();
        $restrict(true);
        $this->get($url)->assertNotFound();

        $restrict(false);
        $this->deny($code, RecordModuleAction::Read);
        $this->get($url)->assertForbidden();

        $restrict(true);
        $this->get("/workflow/cases/{$case->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.state', 'active')
            ->where('content.record.id', $record->id)
            ->where('content.record.values.budget', 350));
    }

    public function test_an_update_with_the_holder_key_does_not_look_at_the_row_judgement(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $fixture['recordId'];
        $this->registerRecordHolds([$id => 'case-1']);
        $context = $this->contextFor($fixture['people']['stranger']->id);
        $input = new RecordInput(['title' => '再申請']);
        $records = app(UpdateRecord::class);
        $update = fn (?string $holder) => $records->execute($context, 'meetings', $id, $input, null, $holder);

        $this->assertThrows(fn () => $update(null), ModelNotFoundException::class);
        $update('case-1');

        $titleId = $fixture['module']->recordFields()->where('code', 'title')->sole()->id;
        $this->assertSame('再申請', Record::query()->findOrFail($id)->field_values[$titleId] ?? null);
    }

    public function test_an_update_with_a_holder_key_of_a_record_without_the_holder_is_a_callers_mistake(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->registerRecordHolds([$fixture['otherId'] => 'case-1']);
        $context = $this->contextFor($fixture['people']['creator']->id);
        $input = new RecordInput(['title' => '再申請'], rowAccessRestricted: false);
        $update = fn () => app(UpdateRecord::class)
            ->execute($context, 'meetings', $fixture['recordId'], $input, null, 'case-1');

        $this->assertThrows($update, InvalidArgumentException::class, '持ち主に鍵 case-1 が無い');

        $record = Record::query()->findOrFail($fixture['recordId']);
        $titleId = $fixture['module']->recordFields()->where('code', 'title')->sole()->id;
        $this->assertSame(['定例', true], [$record->field_values[$titleId] ?? null, $record->row_access_restricted]);
    }

    /**
     * 業務モジュールの read を見ない差分の口(申請の経路が使う {@see RecordRevisionQuery::diffOnCurrentDefinition()})も、参照者の行を返す
     * (更新履歴.md の 5.2 節)。見本の申請書は参照制限の既定が「する」なので、チェックは付いたまま参照者だけが変わる。
     */
    public function test_the_diff_for_the_application_route_carries_the_viewers_line(): void
    {
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $u0002 = User::query()->where('code', 'u0002')->sole();
        $u0003 = User::query()->where('code', 'u0003')->sole();
        $record = Record::query()->findOrFail((int) $case->record_id);
        $this->assertTrue($record->row_access_restricted);
        $code = RecordModule::query()->findOrFail($record->record_module_id)->code;
        $creator = new AccessContext($record->created_by_user_id, null, null, CarbonImmutable::today(), 'ja');
        $input = new RecordInput(rowAccessRestricted: true, rowAccessViewers: [['type' => 'user', 'id' => $u0003->id]]);
        $holder = WorkflowRecordHolds::holderOf($case->id);
        app(UpdateRecord::class)->execute($creator, $code, $record->id, $input, null, $holder);
        $this->deny($code, RecordModuleAction::Read);
        [$after, $before] = RecordRevision::query()->where('record_id', $record->id)
            ->orderByDesc('revision')->limit(2)->pluck('id')->all();

        $diff = app(RecordRevisionQuery::class)->diffOnCurrentDefinition(
            new Requester($u0002->id, null), $before, $after, CarbonImmutable::today(), 'ja',
        );

        $this->assertNull($diff['rowAccess']['restricted'] ?? null);
        $this->assertSame([[], [$u0003->id]], [
            array_column($diff['rowAccess']['viewers']['before'] ?? [], 'id'),
            array_column($diff['rowAccess']['viewers']['after'] ?? [], 'id'),
        ]);
    }
}
