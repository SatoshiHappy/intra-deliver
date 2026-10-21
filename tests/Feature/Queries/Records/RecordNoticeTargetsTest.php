<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\NoticeTargetCatalog;
use App\Queries\Records\Api\RecordViewAccess;
use App\Queries\Records\RecordNoticeTargets;
use InvalidArgumentException;
use Tests\Support\BuildsRecordComments;
use Tests\Support\TransactionalTestCase;

/**
 * レコードを指す通知の移り先(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節、9 節の「通知」の移り先): 業務モジュールの
 * read があればコメントの欄を開いた詳細の URL と表示名「<業務モジュール> / <表示の項目の値か #id>」、無ければ移り先なしと
 * 「このレコードは閲覧できません。」。閲覧の判定({@see RecordViewAccess})と同じ規則で、行の判定も通す。申請の経路は後で足す。
 */
final class RecordNoticeTargetsTest extends TransactionalTestCase
{
    use BuildsRecordComments;

    public function test_a_viewer_gets_the_detail_with_the_comments_pane_open_and_the_module_and_title(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];

        $links = $this->resolve($fixture['people']['attendee']->id, [$recordId]);

        $url = route('records.data.show', ['module' => 'meetings', 'record' => $recordId, 'panel' => 'comments']);
        $this->assertEquals([$recordId => new NoticeTargetLink($url, 'meetings / 定例')], $links);
        $this->assertStringEndsWith("/records/data/meetings/{$recordId}?panel=comments", $links[$recordId]->url);
    }

    public function test_the_record_type_goes_to_the_detail_without_a_query_and_has_the_same_label_and_rule(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $attendee = $fixture['people']['attendee']->id;

        $links = $this->resolve($attendee, [$recordId], RecordNoticeTargets::TARGET_TYPE);

        $url = route('records.data.show', ['module' => 'meetings', 'record' => $recordId]);
        $this->assertEquals([$recordId => new NoticeTargetLink($url, 'meetings / 定例')], $links);
        $this->assertStringNotContainsString('?', $links[$recordId]->url);
        $watcher = $fixture['people']['watcher']->id;
        $this->assertSame([], $this->resolve($watcher, [$recordId], RecordNoticeTargets::TARGET_TYPE));
        $this->assertSame(
            ['record', 'record-comment'],
            app(RecordNoticeTargets::class)->targetTypes(),
        );
    }

    public function test_the_label_falls_back_to_the_id_without_a_title(): void
    {
        $module = $this->recordModule('notes', [RecordModuleAction::Read]);
        $recordId = Record::factory()->create(['record_module_id' => $module->id])->id;

        $this->assertSame("notes / #{$recordId}", $this->resolve(null, [$recordId])[$recordId]->label);
    }

    public function test_no_link_without_read_outside_the_row_access_or_for_missing_and_details_ids(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'watcher' => $watcher] = $fixture['people'];
        $rowId = Record::query()->whereNotNull('parent_record_id')->orderBy('id')->firstOrFail()->id;
        $updateOnly = $this->recordModule('update_only', [RecordModuleAction::Update]);
        $hidden = Record::factory()->create(['record_module_id' => $updateOnly->id])->id;

        $this->assertSame([], $this->resolve($watcher->id, [$fixture['recordId']]));
        $this->assertSame([], $this->resolve($creator->id, [$hidden, 999999999, $rowId]));
        $viewable = $this->resolve($creator->id, [$hidden, $fixture['recordId'], $fixture['otherId']]);
        $this->assertSame([$fixture['recordId']], array_keys($viewable));
        foreach (['record', 'record-comment'] as $type) {
            $text = app(RecordNoticeTargets::class)->unavailableText($type);
            $this->assertSame('このレコードは閲覧できません。', $text);
        }
    }

    public function test_resolves_records_of_several_modules_with_the_same_rule_as_the_view_access(): void
    {
        $fixture = $this->rowAccessFixture();
        $notes = $this->recordModule('notes', [RecordModuleAction::Read]);
        $noteId = Record::factory()->create(['record_module_id' => $notes->id])->id;
        $ids = [$fixture['recordId'], $fixture['otherId'], $noteId];

        foreach ([...self::RELATED, ...self::UNRELATED] as $key) {
            $userId = $fixture['people'][$key]->id;
            $expected = array_values(array_filter($ids, fn (int $id) => $this->canView($userId, $id)));

            $this->assertEqualsCanonicalizing($expected, array_keys($this->resolve($userId, $ids)), $key);
        }
    }

    public function test_the_notice_catalog_answers_the_record_target_type_and_the_unavailable_text(): void
    {
        $fixture = $this->rowAccessFixture();
        $context = $this->contextFor($fixture['people']['watcher']->id);

        $ids = [$fixture['recordId']];

        $resolved = app(NoticeTargetCatalog::class)->resolve(['record' => $ids, 'record-comment' => $ids], $context);

        foreach (['record', 'record-comment'] as $type) {
            $this->assertSame([], $resolved[$type]['links']);
            $this->assertSame('このレコードは閲覧できません。', $resolved[$type]['unavailableText']);
        }
    }

    public function test_another_target_type_is_a_callers_mistake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RecordNoticeTargets::class)->resolve('workflow-case', [1], $this->contextFor());
    }

    /**
     * @param  list<int>  $recordIds
     * @return array<int, NoticeTargetLink>
     */
    private function resolve(?int $userId, array $recordIds, string $type = 'record-comment'): array
    {
        return app(RecordNoticeTargets::class)->resolve($type, $recordIds, $this->contextFor($userId));
    }

    private function canView(int $userId, int $recordId): bool
    {
        $record = Record::query()->findOrFail($recordId);
        $code = RecordModule::query()->findOrFail($record->record_module_id)->code;
        $date = $this->contextFor()->date;

        return app(RecordViewAccess::class)->canView(new Requester($userId, null), $code, $recordId, $date);
    }
}
