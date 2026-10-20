<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの閲覧の判定(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節、検査 = 5 節の「RecordViewAccess」
 * 「行の権限」のうち申請の経路を除く分): read と行の判定の組み合わせ。業務モジュール・レコードが無い・ほかの業務モジュールのレコード・
 * 明細の行は偽で、例外にしない。
 */
final class RecordViewAccessTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    public function test_only_the_related_people_can_view_a_record_of_a_module_for_the_related(): void
    {
        $fixture = $this->rowAccessFixture();

        foreach (self::RELATED as $key) {
            $this->assertTrue($this->canView($fixture['people'][$key]->id, 'meetings', $fixture['recordId']), $key);
        }

        foreach (self::UNRELATED as $key) {
            $this->assertFalse($this->canView($fixture['people'][$key]->id, 'meetings', $fixture['recordId']), $key);
        }

        $this->assertFalse($this->canView(null, 'meetings', $fixture['recordId']), 'ゲスト');
    }

    public function test_anyone_with_read_can_view_a_record_of_a_module_for_everyone(): void
    {
        $readable = $this->recordModule('notes', [RecordModuleAction::Read]);
        $unreadable = $this->recordModule('secrets', [RecordModuleAction::Update]);
        $note = Record::factory()->create(['record_module_id' => $readable->id])->id;
        $secret = Record::factory()->create(['record_module_id' => $unreadable->id])->id;

        $this->assertTrue($this->canView(null, 'notes', $note));
        $this->assertTrue($this->canView($this->user()->id, 'notes', $note));
        $this->assertFalse($this->canView($this->user()->id, 'secrets', $secret));
    }

    public function test_a_missing_module_a_missing_record_another_modules_record_and_a_details_row_are_false(): void
    {
        $notes = $this->recordModule('notes', [RecordModuleAction::Read]);
        $this->recordModule('memos', [RecordModuleAction::Read]);
        $rows = $this->detailsField($notes, 'lines');
        $note = Record::factory()->create(['record_module_id' => $notes->id]);
        $row = Record::factory()->create([
            'record_module_id' => $rows->child_record_module_id,
            'parent_record_module_id' => $notes->id,
            'parent_record_id' => $note->id,
        ]);

        $this->assertFalse($this->canView(null, 'missing', $note->id));
        $this->assertFalse($this->canView(null, 'notes', $note->id + 1000));
        $this->assertFalse($this->canView(null, 'memos', $note->id));
        $this->assertFalse($this->canView(null, 'notes', $row->id));
        $this->assertFalse($this->canView(null, 'lines_rows', $row->id));
    }

    private function canView(?int $userId, string $moduleCode, int $recordId): bool
    {
        $requester = new Requester($userId, null);

        $date = CarbonImmutable::parse(self::TODAY);

        return app(RecordViewAccess::class)->canView($requester, $moduleCode, $recordId, $date);
    }
}
