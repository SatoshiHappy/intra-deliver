<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiToolCallText;
use App\Models\Master\DepartmentSet;
use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 本物の直す(docs の 設計/AI/道具と権限/README.md 10.2 の直すの行・設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B6・B7・B19・
 * 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の確認の内容)を包みに通す: 閲覧の判定を先に呼び、通らないレコードは値を読まずに
 * 見つからない(B6。入力の誤りの文を出さない)。通れば編集の初期値の読み取りで今の値と版を読み、確認の内容を作って問う。レコードは変わらない。
 */
final class UpdateRecordToolTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->permit('m_tasks', [RecordModuleAction::Update]);
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks'], ['ai_update_record']);
    }

    /** 直すは確認待ちで、確認の内容に書く先・渡した項目の今の値と書く値・参照制限を持ち、道具の行に編集の初期値の版が残る */
    public function test_an_update_asks_with_the_current_and_next_values(): void
    {
        $id = $this->task(['title' => '旧', 'amount' => 1]);
        $before = Record::query()->findOrFail($id)->toArray();

        $arguments = ['module' => 'm_tasks', 'id' => $id, 'values' => ['title' => '新']];
        $content = $this->callTool('ai_update_record', $arguments);

        $row = $this->toolRow();
        $this->assertSame([], $content);
        $expected = [AiToolCallDecision::Ask, AiToolCallStatus::AwaitingConfirmation];
        $this->assertSame($expected, [$row->decision, $row->status]);
        $this->assertSame(Record::query()->findOrFail($id)->version(), $row->confirmation_version);
        $confirmation = $this->confirmation();
        $target = ['module' => 'm_tasks', 'moduleName' => 'タスク', 'id' => $id, 'name' => '旧'];
        $this->assertEquals($target, $confirmation['target']);
        $this->assertEquals(['title' => ['name' => '件名', 'current' => '旧', 'next' => '新']], $confirmation['fields']);
        $unchanged = ['restricted' => false, 'viewers' => []];
        $this->assertEquals(['current' => $unchanged, 'next' => $unchanged], $confirmation['rowAccess']);
        $this->assertFalse($confirmation['audienceChanges']['rowAccess']);
        // 書く値の引数(参照制限の 2 つのキーを今の値で埋めた物)を、書く時のために残す
        $written = AiToolCallText::query()->whereKey($row->id)->sole()->written_arguments;
        $this->assertEquals([...$arguments, 'row_access' => $unchanged], $written);
        $this->assertSame($before, Record::query()->findOrFail($id)->toArray());
        $this->assertSame([
            'RecordViewAccess::canView',
            'RecordFormQuery::editValues',
            'RecordFormQuery::draftValues',
        ], $this->reads->calls);
    }

    /** 参照制限のあるレコードでは、確認の内容の今の値に、編集の初期値の読み取りのチェックと参照者(保存の並びの順。組織を含む)が入る */
    public function test_the_current_row_access_of_a_restricted_record_is_read(): void
    {
        $department = $this->department(DepartmentSet::query()->where('is_default', true)->firstOrFail());
        $viewers = [
            ['type' => 'user', 'id' => $this->writer->id],
            ['type' => 'department', 'id' => $department->id],
            ['type' => 'user', 'id' => $this->requester->id],
        ];
        $id = $this->task(['title' => '限る'], restricted: true, viewers: $viewers);

        $this->callTool('ai_update_record', ['module' => 'm_tasks', 'id' => $id, 'values' => ['title' => '新']]);

        $current = ['restricted' => true, 'viewers' => $viewers];
        $this->assertEquals(['current' => $current, 'next' => $current], $this->confirmation()['rowAccess']);
    }

    /**
     * 閲覧の判定を通らないレコード(参照制限で見えない・無い id・ほかの業務モジュールの id)は B6 で、値を読まず、入力の誤り(W3)の文も
     * 出さない。
     */
    public function test_a_record_the_requester_cannot_see_is_not_found_without_reading_values(): void
    {
        $hidden = $this->task(['title' => '隠し'], restricted: true);
        $viewers = ['viewers' => [['type' => 'user', 'id' => $this->requester->id]]];

        foreach (['c1' => $hidden, 'c2' => 999999, 'c3' => $this->refRecord()] as $callId => $id) {
            $content = $this->callTool('ai_update_record', [
                'module' => 'm_tasks',
                'id' => $id,
                'row_access' => $viewers,
            ], $callId);

            $row = $this->toolRow($callId);
            $expected = [AiToolCallDecision::Deny, AiToolDenialReason::NotFound->value];
            $this->assertSame($expected, [$row->decision, $row->decision_reason]);
            $this->assertFalse($content['is_error']);
        }

        $this->assertSame(array_fill(0, 3, 'RecordViewAccess::canView'), $this->reads->calls);
    }

    /** 元のチェックが外れのレコードに見せる人だけを渡すと、今の値を読んだ後の B19(道具の検証の誤りと同じ形・失敗の印あり) */
    public function test_viewers_on_an_unrestricted_record_are_an_input_error_after_reading(): void
    {
        $id = $this->task(['title' => '開いた']);

        $content = $this->callTool('ai_update_record', [
            'module' => 'm_tasks',
            'id' => $id,
            'row_access' => ['viewers' => [['type' => 'user', 'id' => $this->requester->id]]],
        ]);

        $row = $this->toolRow();
        $expected = [AiToolCallDecision::Deny, AiToolDenialReason::InvalidInput->value];
        $this->assertSame($expected, [$row->decision, $row->decision_reason]);
        $this->assertSame(['tool_error', true], [$content['template'], $content['is_error']]);
        $this->assertNotContains('RecordFormQuery::draftValues', $this->reads->calls);
    }

    /**
     * 確認の内容(jsonb なので鍵の並びは保たれない。中身を比べる)。
     *
     * @return array<string, mixed>
     */
    private function confirmation(): array
    {
        $confirmation = AiToolCallText::query()->whereKey($this->toolRow()->id)->sole()->confirmation;

        return is_array($confirmation) ? $confirmation : [];
    }
}
