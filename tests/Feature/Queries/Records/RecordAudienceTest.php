<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\CreateRecordModule;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Models\Authz\AuthzResource;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordAudience;
use App\Queries\Records\Api\RecordViewAccess;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 関係する人のうち閲覧できる人(docs の 設計/業務データの入れ物/認可とメニュー/README.md の 2.4 節の「通知」): 候補は行の判定の「関係する人」と
 * 同じ定義で、d に有効で read のある人だけ。重ねず昇順。レコードが無い・明細の行は空。
 *
 * @phpstan-import-type RowAccessFixture from BuildsRowAccessRecords
 */
final class RecordAudienceTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    public function test_the_related_people_are_the_viewers(): void
    {
        $fixture = $this->rowAccessFixture();

        $this->assertSame($this->idsOf($fixture, self::RELATED), $this->viewers($fixture['recordId']));
        $this->assertSame([$fixture['people']['stranger']->id], $this->viewers($fixture['otherId']));
    }

    public function test_reading_all_does_not_make_an_unrelated_person_a_viewer(): void
    {
        $fixture = $this->rowAccessFixture([...CreateRecordModule::EVERYONE_ACTIONS, RecordModuleAction::ReadAll]);

        $this->assertSame($this->idsOf($fixture, self::RELATED), $this->viewers($fixture['recordId']));
    }

    public function test_a_member_of_an_unflagged_department_field_is_not_a_viewer(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->field($fixture['module'], 'host', RecordFieldType::Department);
        $people = $fixture['people'];
        $host = ['host' => $fixture['departments']['section']->id];
        $recordId = $this->createMeeting($people['stranger'], '別の部署', $host, []);

        $this->assertSame([$people['stranger']->id], $this->viewers($recordId));
    }

    public function test_a_member_outside_the_membership_term_is_not_a_viewer(): void
    {
        $fixture = $this->rowAccessFixture(memberTerm: ['2020-01-01', '2026-10-12']);
        $mainMember = $fixture['people']['mainMember']->id;

        $this->assertContains($mainMember, $this->viewers($fixture['recordId']));
        $this->assertNotContains($mainMember, $this->viewers($fixture['recordId'], '2026-10-12'));
    }

    public function test_a_user_not_effective_on_the_date_is_not_a_viewer_even_when_guests_can_read(): void
    {
        $fixture = $this->rowAccessFixture();
        $people = $fixture['people'];
        $retired = $this->user([['2020-01-01', '2026-10-12']]);
        $disabled = $this->user([['2020-01-01', '2026-10-12'], ['2026-10-12', null, true]]);
        $recordId = $this->createMeeting($retired, '退職', ['attendees' => [$disabled->id, $people['member']->id]], []);
        $fixture['module']->update(['row_access_scope' => RowAccessScope::All]);

        $related = $this->sorted([$retired->id, $disabled->id, $people['member']->id]);
        $this->assertSame($related, $this->viewers($recordId));
        $this->assertSame([$people['member']->id], $this->viewers($recordId, '2026-10-12'));
        $this->assertTrue($this->canView(null, $recordId, '2026-10-12'), '見える範囲が全員で、ゲストに read がある');
    }

    public function test_a_related_person_without_read_is_not_a_viewer(): void
    {
        $fixture = $this->rowAccessFixture([RecordModuleAction::Create]);
        $people = $fixture['people'];
        $this->permitRead($people['assignee']);
        $this->permitRead($people['creator']);
        $this->permitRead($people['stranger']);

        $permitted = $this->sorted([$people['creator']->id, $people['assignee']->id]);
        $this->assertSame($permitted, $this->viewers($fixture['recordId']));
    }

    public function test_a_person_reached_by_several_ways_appears_once(): void
    {
        $fixture = $this->rowAccessFixture();
        ['mainMember' => $mainMember, 'member' => $member] = $fixture['people'];
        $recordId = $this->createMeeting($mainMember, '重なり', [
            'attendee' => $mainMember->id,
            'attendees' => [$member->id, $mainMember->id],
            'section' => $fixture['departments']['section']->id,
        ], [['assignee' => $member->id]]);

        $this->assertSame($this->sorted([$mainMember->id, $member->id]), $this->viewers($recordId));
    }

    public function test_a_record_without_a_creator_has_only_the_field_values(): void
    {
        $fixture = $this->rowAccessFixture();
        $attendee = $fixture['people']['attendee'];
        $input = new RecordInput(['title' => 'ゲストの会議', 'attendee' => $attendee->id]);
        $recordId = app(CreateRecord::class)->execute($this->contextFor(), 'meetings', $input);

        $this->assertNull(Record::query()->findOrFail($recordId)->created_by_user_id);
        $this->assertSame([$attendee->id], $this->viewers($recordId));
    }

    public function test_a_missing_record_and_a_details_row_have_no_viewers(): void
    {
        $fixture = $this->rowAccessFixture();
        $row = Record::query()->where('parent_record_id', $fixture['recordId'])->sole();

        $this->assertSame([], $this->viewers($fixture['otherId'] + 1000));
        $this->assertSame([], $this->viewers($row->id));
    }

    public function test_the_viewers_match_the_view_access_of_people_with_read_and_without_reading_all(): void
    {
        $fixture = $this->rowAccessFixture();
        $viewers = $this->viewers($fixture['recordId']);

        foreach ($fixture['people'] as $key => $person) {
            $canView = $this->canView($person->id, $fixture['recordId']);
            $this->assertSame($canView, in_array($person->id, $viewers, true), $key);
        }
    }

    /**
     * @return list<int>
     */
    private function viewers(int $recordId, string $date = self::TODAY): array
    {
        return app(RecordAudience::class)->relatedViewers($recordId, CarbonImmutable::parse($date));
    }

    private function canView(?int $userId, int $recordId, string $date = self::TODAY): bool
    {
        $access = app(RecordViewAccess::class);

        return $access->canView(new Requester($userId, null), 'meetings', $recordId, CarbonImmutable::parse($date));
    }

    /**
     * 業務モジュール meetings の read を 1 人に許す。
     */
    private function permitRead(User $user): void
    {
        $uri = RecordModuleAction::uri('meetings');
        $resourceGroupId = AuthzResource::query()->where('uri', $uri)->sole()->authz_resource_group_id;
        $condition = new SubjectConditionInput(userId: $user->id);
        $subjectGroup = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, []);
        $type = RecordModuleAction::RESOURCE_TYPE;
        $action = RecordModuleAction::Read->value;

        app(CreatePolicy::class)->execute($resourceGroupId, $subjectGroup->id, $type, $action, PolicyEffect::Permit);
    }

    /**
     * @param  RowAccessFixture  $fixture
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function idsOf(array $fixture, array $keys): array
    {
        return $this->sorted(array_map(fn (string $key) => $fixture['people'][$key]->id, $keys));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
