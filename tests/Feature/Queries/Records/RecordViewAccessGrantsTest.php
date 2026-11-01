<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use App\Queries\Records\RecordAccessGrantCatalog;
use App\Queries\Records\RecordMentionViewers;
use App\Queries\Records\RecordNoticeTargets;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * レコードの閲覧の判定の申請の経路(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節・1.2 節、検査 = 5 節の
 * 「RecordViewAccess」「行の権限」): read が無くても・行の判定を通らなくても、未完了の申請の関係者は真、完了の後は偽。実装が無いとき(ワークフローを
 * 外したテスト)は read と行の判定だけで決まる。呼び手の意味の変化(コメントの宛先の候補と通知の移り先も同じ経路で決まる = 同じ文書の 1.1 節の
 * 「使う所」)。見本の WF-000005(未完了)と WF-000001(完了)の課長 u0002(申請書のレコードの参照制限で行の判定を通らない)、申請者 u0001(作成者)。
 */
final class RecordViewAccessGrantsTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;

    public function test_a_participant_views_the_record_while_the_case_is_active_only(): void
    {
        [$active, $completed] = [$this->caseOf(5), $this->caseOf(1)];
        $u0002 = $this->user('u0002');

        $this->assertSame([true, false], [$this->canView($u0002, $active), $this->canView($u0002, $completed)]);
        $this->deny($this->moduleCodeOf($active), RecordModuleAction::Read);
        $this->assertSame([true, false], [$this->canView($u0002, $active), $this->canView($u0002, $completed)]);
        $this->assertFalse($this->canView($this->user('u0007'), $active), '関わらない人');
    }

    public function test_without_an_implementation_only_the_read_and_the_row_judgement_decide(): void
    {
        $access = app()->make(RecordViewAccess::class, ['grants' => new RecordAccessGrantCatalog([])]);
        $active = $this->caseOf(5);
        $date = CarbonImmutable::today();
        $code = $this->moduleCodeOf($active);

        $canView = fn (string $user) => $access
            ->canView(new Requester($this->user($user)->id, null), $code, (int) $active->record_id, $date);

        $this->assertSame([false, true], [$canView('u0002'), $canView('u0001')]);
    }

    /**
     * 申請の経路は、そのレコードがその業務モジュールにあるときだけ(ほかの業務モジュールの URL では偽)。
     */
    public function test_the_application_route_needs_the_record_in_the_module_of_the_url(): void
    {
        $active = $this->caseOf(5);
        $other = RecordModule::query()->whereNull('parent_record_module_id')
            ->where('code', '<>', $this->moduleCodeOf($active))->orderBy('id')->firstOrFail();
        $requester = new Requester($this->user('u0002')->id, null);

        $canView = app(RecordViewAccess::class)
            ->canView($requester, $other->code, (int) $active->record_id, CarbonImmutable::today());

        $this->assertFalse($canView);
    }

    /**
     * コメントの宛先の候補: 未完了の申請の関係者は候補に入り、完了の後は入らない。
     */
    public function test_the_mention_viewers_follow_the_application_route(): void
    {
        [$active, $completed] = [$this->caseOf(5), $this->caseOf(1)];
        $u0002 = $this->user('u0002')->id;
        $among = fn (WorkflowCase $case) => app(RecordMentionViewers::class)
            ->among($this->moduleCodeOf($case), (int) $case->record_id, [$u0002], CarbonImmutable::today());

        $this->assertSame([[$u0002], []], [$among($active), $among($completed)]);
    }

    /**
     * 通知の移り先: read と行の判定を通る人はレコードの詳細、申請の経路だけの人は未完了の申請の詳細(コメントの欄を開いた形)、完了の後は移り先なし。
     */
    public function test_the_notice_target_of_a_participant_is_the_active_case(): void
    {
        [$active, $completed] = [$this->caseOf(5), $this->caseOf(1)];
        $ids = [(int) $active->record_id, (int) $completed->record_id];
        $resolve = fn (string $code) => app(RecordNoticeTargets::class)->resolve(
            RecordNoticeTargets::TARGET_TYPE_COMMENT,
            $ids,
            new AccessContext($this->user($code)->id, null, null, CarbonImmutable::today(), 'ja'),
        );
        $approver = $resolve('u0002');
        $creator = $resolve('u0001');
        $record = Record::query()->findOrFail($active->record_id);

        $this->assertSame([(int) $active->record_id], array_keys($approver));
        $this->assertSame(
            route('workflow.cases.show', ['case' => $active->id]).'?panel=comments',
            $approver[$record->id]->url ?? null,
        );
        $detail = ['module' => $this->moduleCodeOf($active), 'record' => $record->id, 'panel' => 'comments'];
        $this->assertSame(route('records.data.show', $detail), $creator[$active->record_id]->url ?? null);
    }

    private function caseOf(int $number): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->caseNumbered($number));
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    private function moduleCodeOf(WorkflowCase $case): string
    {
        return RecordModule::query()->findOrFail(Record::query()->findOrFail($case->record_id)->record_module_id)->code;
    }

    private function canView(User $user, WorkflowCase $case): bool
    {
        return app(RecordViewAccess::class)->canView(
            new Requester($user->id, null),
            $this->moduleCodeOf($case),
            (int) $case->record_id,
            CarbonImmutable::today(),
        );
    }
}
