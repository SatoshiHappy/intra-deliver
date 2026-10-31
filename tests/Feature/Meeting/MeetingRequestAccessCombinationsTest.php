<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Meeting\CreateMeeting;
use App\Actions\Meeting\RegenerateMinutes;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use App\Queries\Records\Api\RecordFormQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の作成・再作成の入口の、全部の操作を持つ作成者以外の依頼者の組み合わせと、成功の後の移り先の GET
 * (docs の 設計/業務ツール/会議/処理.md の 3.11。成功は POST の続きで移り先が 200 になるまで見る)。
 */
final class MeetingRequestAccessCombinationsTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * その日に有効でない利用者は入口のミドルウェアがゲストにするので、作成も、作成者のままの再作成も、ゲストとして断られる。
     *
     * @param  'create'|'regenerate'  $operation
     */
    #[DataProvider('operations')]
    public function test_a_user_who_is_not_effective_on_the_day_is_forbidden_as_a_guest(string $operation): void
    {
        $ineffective = $this->user([['2020-01-01', '2020-12-31']]);
        $recordId = $this->completedMeeting($this->user());
        Record::query()->whereKey($recordId)->update(['created_by_user_id' => $ineffective->id]);
        $counts = $this->counts();

        $response = $operation === 'create'
            ? $this->actingAs($ineffective)->postJson('/meetings', ['text' => '発言'])
            : $this->actingAs($ineffective)->postJson("/meetings/{$recordId}/regenerate", []);

        $response->assertForbidden()->assertJsonPath('message', RunRequestMessages::GUEST);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * @return array<string, array{'create'|'regenerate'}>
     */
    public static function operations(): array
    {
        return ['作成' => ['create'], '再作成' => ['regenerate']];
    }

    /**
     * 操作の権限が欠け、かつ行の判定も通らない人の再作成は、会議の有無より先に 403(404 にしない)。
     *
     * @param  'http'|'direct'  $entrance
     */
    #[DataProvider('missingActionsAndEntrances')]
    public function test_regenerating_without_an_action_and_without_the_row_access_is_forbidden_before_not_found(
        string $entrance,
        RecordModuleAction $missing,
    ): void {
        $recordId = $this->completedMeeting($this->user());
        $stranger = $this->user();
        $this->denyMeeting($missing);
        $counts = $this->counts();

        if ($entrance === 'http') {
            $this->actingAs($stranger)->postJson("/meetings/{$recordId}/regenerate", [])
                ->assertForbidden()->assertJsonPath('message', RecordFormQuery::NOT_ALLOWED_MESSAGE);
        } else {
            try {
                app(RegenerateMinutes::class)->execute($this->contextOf($stranger), $recordId, null);
                $this->fail('再作成が断られなかった');
            } catch (AuthorizationException $exception) {
                $this->assertSame(RecordFormQuery::NOT_ALLOWED_MESSAGE, $exception->getMessage());
            }
        }

        $this->assertSame($counts, $this->counts());
    }

    /**
     * @return array<string, array{'http'|'direct', RecordModuleAction}>
     */
    public static function missingActionsAndEntrances(): array
    {
        $cases = [];

        foreach (['HTTP の入口' => 'http', '業務処理を直接呼ぶ' => 'direct'] as $label => $entrance) {
            foreach ([RecordModuleAction::Read, RecordModuleAction::Update] as $action) {
                $cases["{$label}・{$action->value}"] = [$entrance, $action];
            }
        }

        return $cases;
    }

    /**
     * 全部の操作を持つ作成者の作成(貼り付け・音声)は成功し、移り先の会議の一覧が開く。
     *
     * @param  'paste'|'audio'  $source
     */
    #[DataProvider('createSources')]
    public function test_a_creator_with_every_action_creates_and_the_list_opens(string $source): void
    {
        $creator = $this->user();
        $input = $source === 'paste' ? ['text' => '発言'] : ['file' => $this->audioFile('定例.mp3')];
        $meetings = $this->meetingCount();

        $this->postMeeting($input, $creator)->assertStatus(303)->assertRedirect('/records/data/meeting')
            ->assertSessionHasNoErrors();
        $this->get('/records/data/meeting')->assertOk();

        $this->assertSame($meetings + 1, $this->meetingCount());
    }

    /**
     * @return array<string, array{'paste'|'audio'}>
     */
    public static function createSources(): array
    {
        return ['貼り付け' => ['paste'], '音声' => ['audio']];
    }

    /** 全部の操作を持つ作成者の再作成は成功し、移り先の詳細が開く */
    public function test_a_creator_with_every_action_regenerates_and_opens_the_detail(): void
    {
        $creator = $this->user();

        $this->assertRegeneratedAndDetailOpens($creator, $this->completedMeeting($creator));
    }

    /**
     * 再作成は `create` を要さない(作成と再作成の権限の違い)。`read`・`update` を持つ作成者は成功し、移り先の詳細が開く。
     */
    public function test_a_creator_without_create_regenerates_and_opens_the_detail(): void
    {
        $creator = $this->user();
        $recordId = $this->completedMeeting($creator);
        $this->denyMeeting(RecordModuleAction::Create);

        $this->assertRegeneratedAndDetailOpens($creator, $recordId);
    }

    /**
     * 作成者でない関係者(項目「関係者」の値のユーザー / 値の組織に所属する人)の再作成は成功し、移り先の詳細が開く。
     *
     * @param  'user'|'department'  $kind
     */
    #[DataProvider('relatedKinds')]
    public function test_a_related_person_who_is_not_the_creator_regenerates_and_opens_the_detail(string $kind): void
    {
        $creator = $this->user();
        $related = $this->user();
        $party = $kind === 'user' ? $related : $this->departmentWith($related);
        $parties = [['type' => $kind, 'id' => $party->id]];
        $recordId = $this->completedMeeting($creator, [Meeting::RelatedParties->value => $parties]);

        $this->assertRegeneratedAndDetailOpens($related, $recordId);
        $this->assertSame($related->id, $this->requesterOfLastRun($recordId));
    }

    /**
     * @return array<string, array{'user'|'department'}>
     */
    public static function relatedKinds(): array
    {
        return ['項目の値のユーザー' => ['user'], '項目の値の組織に所属する人' => ['department']];
    }

    /**
     * 全件の参照で見ている関係しない人(システム管理者)の再作成は成功し、移り先の詳細が開く。完了の通知は会議を閲覧できる
     * 関係者(通知と削除予定日.md の 6.2)だけに届き、その人には届かない。起こした人は依頼者。
     */
    public function test_an_unrelated_person_who_reads_all_regenerates_and_receives_no_notice(): void
    {
        $creator = $this->user();
        $administrator = User::query()->where('code', 'u0004')->sole();
        $recordId = $this->completedMeeting($creator);
        $created = $this->meetingNotices($recordId);

        $this->assertRegeneratedAndDetailOpens($administrator, $recordId);
        $this->completeMinutes($this->lastRunIdOf($recordId));

        $regenerated = MeetingNoticeType::MinutesRegenerated->value;
        $this->assertSame(
            [...$created, [$regenerated, $creator->id, $administrator->id, ['title' => 'AI の件名']]],
            $this->meetingNotices($recordId),
        );
    }

    /**
     * 再作成を送り、303 で詳細へ戻り、続けて詳細の GET が 200 になる(再作成の対応が 1 件増え、依頼者の再作成の契機で書かれる)。
     */
    private function assertRegeneratedAndDetailOpens(User $requester, int $recordId): void
    {
        $detail = "/records/data/meeting/{$recordId}";
        $runs = MeetingRun::query()->count();

        $this->regenerateAs($requester, $recordId)->assertStatus(303)->assertRedirect($detail)
            ->assertSessionHasNoErrors();
        $this->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page->has('sections'));

        $this->assertSame($runs + 1, MeetingRun::query()->count());
        $this->assertSame(MeetingRunOrigin::Regenerate, $this->lastRunOf($recordId)->origin);
        $this->assertSame($requester->id, $this->requesterOfLastRun($recordId));
    }

    /**
     * @return TestResponse<Response>
     */
    private function regenerateAs(User $user, int $recordId): TestResponse
    {
        return $this->actingAs($user)->from("/records/data/meeting/{$recordId}")
            ->post("/meetings/{$recordId}/regenerate", []);
    }

    private function requesterOfLastRun(int $recordId): int
    {
        return (int) AiRun::query()->findOrFail($this->lastRunIdOf($recordId))->requester_user_id;
    }

    /** 組織(今日に有効)に主所属で入れたユーザーの、その組織 */
    private function departmentWith(User $member): Department
    {
        $department = $this->department(DepartmentSet::factory()->create());
        $this->join($member, $department, isMain: true);

        return $department;
    }

    /**
     * 議事録まで終わった貼り付けの会議(処理中でないので再作成を押せる)。
     *
     * @param  array<string, mixed>  $values
     */
    private function completedMeeting(User $creator, array $values = []): int
    {
        $recordId = app(CreateMeeting::class)->execute($this->contextOf($creator), null, "最初の発言\n次の発言", $values);
        $this->completeMinutes($this->lastRunIdOf($recordId));

        return $recordId;
    }
}
