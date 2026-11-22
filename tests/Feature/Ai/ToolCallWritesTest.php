<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Actions\Ai\Tools\RecordToolWrites;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolWriteDenial;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 書く時の部品(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の表 2、設計/AI/実行の記録.md 2.4 の T7)を、本物の作る・直すで
 * 通す: 承認した件を呼び出しの順に 1 件ずつ書き、表 2 の各行の結果と道具の行の状態・書く時の断りの理由・会話の保存の結果になる。1 件が断られても
 * ほかの件は書く。
 */
final class ToolCallWritesTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;
    use RunsRecordWrites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->permit('m_tasks', [RecordModuleAction::Create, RecordModuleAction::Update]);
        $this->permit('m_refs', [RecordModuleAction::Read, RecordModuleAction::Create]);
        $this->startRecordToolRun(['m_tasks', 'm_refs'], ['ai_create_record', 'ai_update_record']);
    }

    /** P8: 作ると直すを書き、行は済み・実行の答え・答えの実行で、版の契機は AI、結果は失敗の印の無い書く先の印 */
    public function test_approved_calls_are_written_in_order(): void
    {
        $id = $this->task(['title' => '旧', 'amount' => 1]);
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '新しい件']]],
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $id, 'values' => ['amount' => 2]]],
        ]);
        $this->travel(1)->minutes();

        $outcome = $this->answer($rows);

        foreach ($rows as $row) {
            $this->assertRow($row, AiToolCallStatus::Done, null);
        }

        $this->assertSame(['c1', 'c2'], array_column($outcome->results, 'callId'));
        $this->assertFalse($this->item($outcome, 'c1')->content['is_error'] ?? true);
        $created = $rows[0]->refresh()->records[0]['id'] ?? null;
        $this->assertIsInt($created);
        $origin = fn (int $recordId): mixed => RecordRevision::query()->where('record_id', $recordId)
            ->latest('id')->value('origin_operation');
        $operation = RecordToolWrites::OPERATION;
        $this->assertSame([$operation, $operation], [$origin($created), $origin($id)]);
    }

    /** つながり: 後の件の前の件を指す印に作った id が入って書かれる */
    public function test_a_reference_to_a_written_create_gets_its_id(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_refs', 'ref' => 'r']],
            ['ai_create_record', [
                'module' => 'm_tasks',
                'ref' => 't',
                'values' => ['title' => '指す', 'ref' => ['ref' => 'r']],
            ]],
        ]);

        $this->answer($rows);

        $refId = $rows[0]->refresh()->records[0]['id'] ?? null;
        $this->assertIsInt($refId);
        $this->assertRow($rows[1], AiToolCallStatus::Done, null);
        $taskId = $rows[1]->refresh()->records[0]['id'] ?? null;
        $this->assertIsInt($taskId);
        $requester = Requester::fromAccessContext(app(ToolSubjects::class)->for($this->run)->accessContext());
        $edit = app(RecordFormQuery::class)->editValues($requester, 'm_tasks', $taskId, now(), 'ja');
        $ref = $edit['values']['ref'] ?? null;
        $this->assertSame($refId, is_array($ref) ? ($ref['id'] ?? null) : null);
    }

    /** P1: 指した前の件が断られる(P2)と、指す件は前の件が書けなかった */
    public function test_a_reference_to_a_refused_create_is_p1(): void
    {
        $chain = $this->ask([
            ['ai_create_record', ['module' => 'm_refs', 'ref' => 'r']],
            ['ai_create_record', ['module' => 'm_tasks', 'ref' => 't', 'values' => ['ref' => ['ref' => 'r']]]],
        ]);
        $this->stop($this->refs);
        $this->answer($chain);

        $this->assertRow($chain[0], AiToolCallStatus::Rejected, AiToolWriteDenial::Unavailable);
        $this->assertRow($chain[1], AiToolCallStatus::Rejected, AiToolWriteDenial::PriorNotWritten);
    }

    /** P2: 答えの実行の始めの定義から道具を外した件と、表示の止めを立てた業務モジュールの件は使えなくなった */
    public function test_a_tool_out_of_the_definition_or_a_stopped_module_is_p2(): void
    {
        $id = $this->task(['title' => '旧']);
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '新']]],
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $id, 'values' => ['title' => '直す']]],
        ]);
        $this->run->agentDefinition()->sole()->update(['tools' => ['ai_update_record']]);

        $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::Rejected, AiToolWriteDenial::Unavailable);
        $this->assertRow($rows[1], AiToolCallStatus::Done, null);
    }

    /** P3: 呼ばれた後に依頼者のロールを外した件は権限が無い(作る・直す) */
    public function test_a_call_after_the_permission_is_removed_is_p3(): void
    {
        $id = $this->task(['title' => '旧']);
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_refs']],
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $id, 'values' => ['title' => '直す']]],
        ]);
        $this->grantedRole->delete();

        $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::Rejected, AiToolWriteDenial::NoPermission);
        $this->assertRow($rows[1], AiToolCallStatus::Rejected, AiToolWriteDenial::NoPermission);
    }

    /** 外の使い回すまとまりで事実を覚えた後でも、書く件は使い回さない区間で判定するので、その後に外したロールが効く(P3) */
    public function test_a_role_revoked_after_an_outside_check_in_the_same_job_takes_effect_in_the_write(): void
    {
        $rows = $this->ask([['ai_create_record', ['module' => 'm_refs']]]);

        app(AuthorizationReuse::class)->within(function () use ($rows): void {
            $subject = app(ToolSubjects::class)->for($this->run);
            $allowed = app(RecordFormQuery::class)
                ->allows($subject->requester(), 'm_refs', RecordModuleAction::Create, $subject->date());
            $this->assertTrue($allowed);
            $this->revokeRole();

            $this->answer($rows);
        });

        $this->assertRow($rows[0], AiToolCallStatus::Rejected, AiToolWriteDenial::NoPermission);
    }

    /** P4・P6: 呼ばれた後に消えたレコードは見つからない(失敗の印なし)、ほかの人が先に直したレコードは P6 */
    public function test_a_deleted_record_is_p4_and_a_record_changed_after_the_card_is_p6(): void
    {
        [$gone, $changed] = [$this->task(['title' => '消す']), $this->task(['title' => '変わる'])];
        $rows = $this->ask([
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $gone, 'values' => ['title' => 'a']]],
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $changed, 'values' => ['title' => 'b']]],
        ]);
        Record::query()->whereKey($gone)->delete();
        $this->travel(1)->minutes();
        $this->updateTask($changed, ['amount' => 9]);

        $outcome = $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::Rejected, AiToolWriteDenial::NotFound);
        $this->assertFalse($this->item($outcome, 'c1')->content['is_error'] ?? true);
        $this->assertRow($rows[1], AiToolCallStatus::Rejected, AiToolWriteDenial::Stale);
        $this->assertTrue($this->item($outcome, 'c2')->content['is_error'] ?? false);
    }

    /** P9: 道具の引数の形の誤りは形の誤りで書かず、ほかの件は書く。登録に無い道具は無い道具の失敗(理由なし) */
    public function test_a_shape_error_is_p9_and_an_unregistered_tool_is_a_tool_error(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '形']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '書く']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '無い']]],
        ]);
        AiToolCallText::query()->whereKey($rows[0]->id)->update(['arguments' => json_encode(['module' => 5])]);
        AiToolCall::query()->whereKey($rows[2]->id)->update(['tool_name' => 'ai_gone']);

        $outcome = $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::ToolError, AiToolWriteDenial::InvalidShape);
        $template = $this->item($outcome, 'c1')->content['template'];
        $this->assertSame(AiInstructionTemplate::ToolInputInvalid->value, $template);
        $this->assertRow($rows[1], AiToolCallStatus::Done, null);
        $this->assertRow($rows[2], AiToolCallStatus::ToolError, null);
        $this->assertSame(AiInstructionTemplate::UnknownTool->value, $this->item($outcome, 'c3')->content['template']);
    }

    /**
     * P7: 確認の後に要る項目にした件は入れ物の検証で断り(項目ごとの文)、P9: 確認の後に定義から項目を外した件は道具の検証の誤り(入力の
     * 誤り。答えの実行の始めの定義で見る)
     */
    public function test_a_validation_failure_is_p7_and_a_field_out_of_the_definition_is_p9(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '要る']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '外す', 'note' => 'メモ']]],
        ]);
        $this->taskFields['amount']->update(['is_required' => true]);
        $this->taskFields['note']->delete();

        $outcome = $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::Rejected, AiToolWriteDenial::Validation);
        $error = (string) ($this->item($outcome, 'c1')->content['values']['error'] ?? '');
        $this->assertStringContainsString('`values.amount`', $error);
        $this->assertRow($rows[1], AiToolCallStatus::ToolError, AiToolWriteDenial::InvalidInput);
        $this->assertSame(0, Record::query()->where('record_module_id', $this->tasks->id)->count());
    }
}
