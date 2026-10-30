<?php

namespace Tests\Feature\Meeting;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\DepartmentSet;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingFields;
use App\Queries\Meeting\MeetingFieldsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\TransactionalTestCase;

/**
 * 材料にする会議の欄を読む 1 か所(docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3): 依頼者の閲覧の判定(read と行の判定)を通らなければ 404。
 */
final class MeetingFieldsQueryTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    public function test_reads_the_fields_for_a_related_requester(): void
    {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator, values: ['title' => '定例', 'agenda' => '議題']);

        $fields = $this->read($creator->id, $recordId);

        $this->assertSame(
            ['定例', '議題', null, [], []],
            [$fields->title, $fields->agenda, $fields->notes, $fields->userNames, $fields->departmentNames],
        );
    }

    public function test_the_related_parties_are_split_into_users_and_departments_in_the_value_order(): void
    {
        $creator = $this->user();
        [$sato, $suzuki] = [$this->namedUser('佐藤 花子'), $this->namedUser('鈴木 一郎')];
        [$development, $sales] = [$this->namedDepartment('開発部'), $this->namedDepartment('営業部')];
        $parties = [
            ['type' => 'department', 'id' => $sales->id],
            ['type' => 'user', 'id' => $suzuki->id],
            ['type' => 'department', 'id' => $development->id],
            ['type' => 'user', 'id' => $sato->id],
        ];
        $recordId = $this->pastedMeeting($creator, values: ['related_parties' => $parties]);

        $fields = $this->read($creator->id, $recordId);

        $this->assertSame([['鈴木 一郎', '佐藤 花子'], ['営業部', '開発部']], [$fields->userNames, $fields->departmentNames]);
    }

    public function test_a_member_of_a_related_department_reads_the_meeting_and_a_member_of_its_child_does_not(): void
    {
        $set = DepartmentSet::factory()->create();
        $section = $this->namedDepartment('開発部', $set);
        $child = $this->namedDepartment('開発一課', $set);
        $this->include($section, $child);
        [$member, $childMember] = [$this->user(), $this->user()];
        $this->join($member, $section, isMain: true);
        $this->join($childMember, $child, isMain: true);
        $party = ['type' => 'department', 'id' => $section->id];
        $recordId = $this->pastedMeeting($this->user(), values: ['title' => '定例', 'related_parties' => [$party]]);

        $this->assertSame('定例', $this->read($member->id, $recordId)->title);
        $this->expectException(ModelNotFoundException::class);
        $this->read($childMember->id, $recordId);
    }

    public function test_a_requester_who_does_not_pass_the_row_access_is_not_found(): void
    {
        $recordId = $this->pastedMeeting($this->user());

        $this->expectException(ModelNotFoundException::class);

        $this->read($this->user()->id, $recordId);
    }

    public function test_a_requester_without_read_is_not_found(): void
    {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator);
        $this->denyMeeting(RecordModuleAction::Read);

        $this->expectException(ModelNotFoundException::class);

        $this->read($creator->id, $recordId);
    }

    private function read(int $userId, int $recordId): MeetingFields
    {
        $today = CarbonImmutable::today('Asia/Tokyo');

        return app(MeetingFieldsQuery::class)->read(new Requester($userId, null), $recordId, $today, 'ja');
    }
}
