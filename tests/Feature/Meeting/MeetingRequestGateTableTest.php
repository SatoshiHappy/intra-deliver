<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Meeting\CreateMeeting;
use App\Actions\Meeting\RegenerateMinutes;
use App\Enums\Builtin\Meeting;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の作成・再作成の入口の判定(docs の 設計/業務ツール/会議/処理.md の 3.11 の「入口の判定」の表)を、HTTP の入口と業務処理を
 * 直接呼ぶ形の両方で通す。認可の条件は、欠く操作ごとに 1 行ずつに展開する。
 */
final class MeetingRequestGateTableTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const ACCEPTED = 303;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * @return array<string, array{'http'|'direct'}>
     */
    public static function entrances(): array
    {
        return ['HTTP の入口' => ['http'], '業務処理を直接呼ぶ' => ['direct']];
    }

    /**
     * @return array<string, array{'http'|'direct', 'create'|'regenerate'}>
     */
    public static function entrancesAndOperations(): array
    {
        $cases = [];

        foreach (self::entrances() as $label => [$entrance]) {
            $cases["{$label}・作成"] = [$entrance, 'create'];
            $cases["{$label}・再作成"] = [$entrance, 'regenerate'];
        }

        return $cases;
    }

    /**
     * @param  'http'|'direct'  $entrance
     * @param  'create'|'regenerate'  $operation
     */
    #[DataProvider('entrancesAndOperations')]
    public function test_e1_a_guest_is_forbidden(string $entrance, string $operation): void
    {
        $recordId = $this->completedMeeting($this->user());

        $outcome = $operation === 'create'
            ? $this->create($entrance, null)
            : $this->regenerate($entrance, null, $recordId);

        $this->assertSame([403, RunRequestMessages::GUEST], $outcome);
    }

    /**
     * @return array<string, array{'http'|'direct', RecordModuleAction}>
     */
    public static function createActions(): array
    {
        return self::withEntrances([RecordModuleAction::Create, RecordModuleAction::Read, RecordModuleAction::Update]);
    }

    /**
     * @param  'http'|'direct'  $entrance
     */
    #[DataProvider('createActions')]
    public function test_e2_creating_without_one_of_the_actions_is_forbidden(
        string $entrance,
        RecordModuleAction $missing,
    ): void {
        $this->denyMeeting($missing);
        $counts = $this->counts();

        $this->assertSame([403, RecordFormQuery::NOT_ALLOWED_MESSAGE], $this->create($entrance, $this->user()));
        $this->assertSame($counts, $this->counts());
    }

    /**
     * 作成者は作成の後に会議を閲覧できる(作成で閲覧の判定を通らない組み合わせは起きない)。
     *
     * @param  'http'|'direct'  $entrance
     */
    #[DataProvider('entrances')]
    public function test_e3_creating_with_the_actions_is_accepted_and_the_creator_can_view_the_meeting(
        string $entrance,
    ): void {
        $creator = $this->user();

        $this->assertSame([self::ACCEPTED, null], $this->create($entrance, $creator));

        $requester = Requester::fromAccessContext($this->contextOf($creator));
        $today = $this->contextOf($creator)->date;
        $meetingId = $this->latestMeetingId();
        $this->assertTrue(app(RecordViewAccess::class)->canView($requester, Meeting::CODE, $meetingId, $today));
    }

    /**
     * @return array<string, array{'http'|'direct', RecordModuleAction}>
     */
    public static function regenerateActions(): array
    {
        return self::withEntrances([RecordModuleAction::Read, RecordModuleAction::Update]);
    }

    /**
     * @param  'http'|'direct'  $entrance
     */
    #[DataProvider('regenerateActions')]
    public function test_e4_regenerating_without_one_of_the_actions_is_forbidden(
        string $entrance,
        RecordModuleAction $missing,
    ): void {
        $creator = $this->user();
        $recordId = $this->completedMeeting($creator);
        $this->denyMeeting($missing);
        $counts = $this->counts();

        $outcome = $this->regenerate($entrance, $creator, $recordId);
        $this->assertSame([403, RecordFormQuery::NOT_ALLOWED_MESSAGE], $outcome);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * @return array<string, array{'http'|'direct', 'missing'|'other-module'|'hidden'}>
     */
    public static function unviewableMeetings(): array
    {
        return self::withEntrances(['missing', 'other-module', 'hidden']);
    }

    /**
     * 閲覧の判定を通らない(会議が無い・会議の業務モジュールのレコードでない・行の判定を通らない)= 404。
     *
     * @param  'http'|'direct'  $entrance
     * @param  'missing'|'other-module'|'hidden'  $case
     */
    #[DataProvider('unviewableMeetings')]
    public function test_e5_regenerating_a_meeting_the_requester_cannot_view_is_not_found(
        string $entrance,
        string $case,
    ): void {
        $creator = $this->user();
        $recordId = $this->completedMeeting($creator);
        [$requester, $target] = match ($case) {
            'missing' => [$creator, $recordId + 1_000_000],
            'other-module' => [$creator, Record::factory()->create()->id],
            'hidden' => [$this->user(), $recordId],
        };
        $counts = $this->counts();

        $this->assertSame(404, $this->regenerate($entrance, $requester, $target)[0]);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * @param  'http'|'direct'  $entrance
     */
    #[DataProvider('entrances')]
    public function test_e6_regenerating_a_meeting_the_requester_can_view_is_accepted(string $entrance): void
    {
        $creator = $this->user();
        $recordId = $this->completedMeeting($creator);

        $this->assertSame([self::ACCEPTED, null], $this->regenerate($entrance, $creator, $recordId));
    }

    /**
     * 表の外の結果: 会議の業務モジュールが無ければ、業務モジュールの認可の判定が 404 を投げる。
     *
     * @param  'http'|'direct'  $entrance
     * @param  'create'|'regenerate'  $operation
     */
    #[DataProvider('entrancesAndOperations')]
    public function test_without_the_meeting_module_the_request_is_not_found(string $entrance, string $operation): void
    {
        $creator = $this->user();
        $recordId = $this->completedMeeting($creator);
        RecordModule::query()->where('code', Meeting::CODE)->update(['code' => 'meeting_renamed']);

        $outcome = $operation === 'create'
            ? $this->create($entrance, $creator)
            : $this->regenerate($entrance, $creator, $recordId);

        $this->assertSame(404, $outcome[0]);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return array<string, array{'http'|'direct', T}>
     */
    private static function withEntrances(array $values): array
    {
        $cases = [];

        foreach (self::entrances() as $label => [$entrance]) {
            foreach ($values as $value) {
                $name = $value instanceof RecordModuleAction ? $value->value : $value;
                $cases["{$label}・{$name}"] = [$entrance, $value];
            }
        }

        return $cases;
    }

    /**
     * 会議を作る(貼り付け)。
     *
     * @param  'http'|'direct'  $entrance
     * @return array{int, string|null}
     */
    private function create(string $entrance, ?User $user): array
    {
        return $this->outcome(
            $entrance,
            fn () => ($user === null ? $this : $this->actingAs($user))->postJson('/meetings', ['text' => '発言']),
            fn () => app(CreateMeeting::class)->execute($this->contextOf($user), null, '発言', []),
        );
    }

    /**
     * @param  'http'|'direct'  $entrance
     * @return array{int, string|null}
     */
    private function regenerate(string $entrance, ?User $user, int $recordId): array
    {
        return $this->outcome(
            $entrance,
            fn () => ($user === null ? $this : $this->actingAs($user))
                ->postJson("/meetings/{$recordId}/regenerate", []),
            fn () => app(RegenerateMinutes::class)->execute($this->contextOf($user), $recordId, null),
        );
    }

    /**
     * 結果を [状態, 文] にそろえる(受けた・404 の文は比べない = null)。
     *
     * @param  'http'|'direct'  $entrance
     * @return array{int, string|null}
     */
    private function outcome(string $entrance, Closure $http, Closure $direct): array
    {
        if ($entrance === 'http') {
            $response = $http();
            $status = $response->status();

            return [$status, $status === 403 ? $response->json('message') : null];
        }

        try {
            $direct();
        } catch (AuthorizationException $exception) {
            return [403, $exception->getMessage()];
        } catch (ModelNotFoundException) {
            return [404, null];
        }

        return [self::ACCEPTED, null];
    }

    /** 議事録まで終わった貼り付けの会議(処理中でないので再作成を押せる) */
    private function completedMeeting(User $creator): int
    {
        $recordId = $this->pastedMeeting($creator);
        $this->completeMinutes($this->lastRunIdOf($recordId));

        return $recordId;
    }
}
