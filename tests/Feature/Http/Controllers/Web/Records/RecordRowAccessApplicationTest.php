<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請の経路は行の判定を見ない(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2 の「ワークフローの経路の例外」・行の権限.md 2.4 の「申請の経路は行の判定を
 * 見ない」、ADR 0018 の決定 7): 業務モジュールの read も行の判定も通らない申請の関係者が、未完了の申請の詳細でレコードの中身を見る。持ち主の鍵を渡す更新
 * (ワークフローの再申請の呼び方)は行の判定を見ずに通り、業務データの入れ物の入口の更新(鍵 null)は同じレコードが 404。
 */
final class RecordRowAccessApplicationTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use RegistersRecordHolds;
    use UsesSeededCases;
    use UsesSeededRecords;

    public function test_a_participant_sees_the_record_of_an_active_case_without_read_or_relation(): void
    {
        $this->withoutVite();
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $u0002 = User::query()->where('code', 'u0002')->sole();
        $record = Record::query()->findOrFail($case->record_id);
        $this->assertNotSame($u0002->id, $record->created_by_user_id);
        $project = RecordModule::query()->where('code', 'project')->sole();
        $project->update(['row_access_scope' => RowAccessScope::Related]);
        $this->actingAs($u0002);

        $this->get("/records/data/project/{$record->id}")->assertNotFound();

        $this->deny('project', RecordModuleAction::Read);
        $this->get("/records/data/project/{$record->id}")->assertForbidden();
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
}
