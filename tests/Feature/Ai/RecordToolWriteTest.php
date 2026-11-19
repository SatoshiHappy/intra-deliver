<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Actions\Ai\Api\RecordMarker;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Actions\Ai\Tools\RecordToolWrites;
use App\Actions\Records\Api\RecordStaleRefusal;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 書く道具の書く(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の「実行」の後の書き方と更新履歴・10.1 の結果): 書く値の引数を
 * 判定する登録・更新に渡し、版の契機は「AI(AI エージェント)」で操作した人は依頼者。直すは呼ばれた時の版を渡し、版が違えば入れ物の断りが
 * そのまま投げられる(表 2 の行に直すのは ③ の書く時の部品)。結果は書く先の印と書けたことで、表示の名前は書く値の表示の項目の値。
 */
final class RecordToolWriteTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->permit('m_tasks', [RecordModuleAction::Create, RecordModuleAction::Update]);
        $this->startRecordToolRun(['m_tasks'], ['ai_create_record', 'ai_update_record']);
    }

    public function test_a_create_writes_the_values_with_the_agent_origin(): void
    {
        $granted = $this->tool('ai_create_record');
        $tool = $granted->tool;
        $this->assertInstanceOf(AgentCreateTool::class, $tool);
        $written = ['module' => 'm_tasks', 'values' => ['title' => '新しい件'], 'row_access' => ['restricted' => false]];

        $result = $tool->write($written, $this->subject(), $granted->scope, new RecordConfirmation([]));

        $id = $result->records[0]['id'] ?? null;
        $this->assertIsInt($id);
        $this->assertTrue(Record::query()->whereKey($id)->exists());
        $this->assertEquals(['record' => new RecordMarker('m_tasks', $id), 'written' => true], $result->data);
        $this->assertSame([['module' => 'm_tasks', 'id' => $id, 'name' => '新しい件']], $result->records);
        $revision = RecordRevision::query()->where('record_id', $id)->sole();
        $this->assertSame(RecordToolWrites::OPERATION, $revision->origin_operation);
        $this->assertSame($this->requester->id, $revision->user_id);
    }

    public function test_an_update_writes_with_the_version_and_names_the_target_without_a_title(): void
    {
        $id = $this->task(['title' => '旧', 'amount' => 1]);
        $granted = $this->tool('ai_update_record');
        $tool = $granted->tool;
        $this->assertInstanceOf(AgentUpdateTool::class, $tool);
        $version = Record::query()->findOrFail($id)->version();
        $confirmation = new RecordConfirmation(['target' => ['name' => '旧']]);
        $written = ['module' => 'm_tasks', 'id' => $id, 'values' => ['amount' => 2]];
        // 版は更新の日時なので、同じ時刻の更新では変わらない
        $this->travel(1)->minutes();

        $result = $tool->write($written, $this->subject(), $granted->scope, $confirmation, $version);

        $this->assertSame([['module' => 'm_tasks', 'id' => $id, 'name' => '旧']], $result->records);
        $latest = RecordRevision::query()->where('record_id', $id)->latest('id')->firstOrFail();
        $origin = [$latest->origin_operation, $latest->user_id];
        $this->assertSame([RecordToolWrites::OPERATION, $this->requester->id], $origin);
        $this->assertThrows(
            fn () => $tool->write($written, $this->subject(), $granted->scope, $confirmation, $version),
            RecordStaleRefusal::class,
        );
    }

    public function test_written_arguments_without_a_module_are_a_build_error(): void
    {
        $granted = $this->tool('ai_create_record');
        $tool = $granted->tool;
        $this->assertInstanceOf(AgentCreateTool::class, $tool);

        $this->assertThrows(
            fn () => $tool->write(['values' => []], $this->subject(), $granted->scope, new RecordConfirmation([])),
            LogicException::class,
        );
    }

    private function tool(string $name): GrantedTool
    {
        $granted = $this->granted->find($name);
        $this->assertNotNull($granted);

        return $granted;
    }

    private function subject(): AgentToolSubject
    {
        return app(ToolSubjects::class)->for($this->run);
    }
}
