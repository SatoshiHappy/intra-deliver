<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCaseEarly;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowConfirmationProgress;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Notice\NoticeList;
use App\Queries\Workflow\CaseConfirmations;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\OperatesHolds;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 確認の述語と未確認・確認済のタブの絞り込み(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節・2.1 節・6 節)。
 * ルートの見本 R1(予算 100)の課長に確認する人 u0006 を付けた版。u0006 はフローの参照者(経理部)でも処理する人でもない(確認対象者だけの人)。
 * 閲覧できる・できないは、閲覧の判定と 2 段目の詳細のルート(GET が 200 か 404)の両方で確かめる。
 */
final class CaseVisibilityConfirmTest extends TransactionalTestCase
{
    use OperatesHolds;
    use ReadsCaseNotices;
    use RegistersProxies;

    /** 合議の 3 人と責任者 */
    private const DESIGNATION = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];

    private const CONFIRMER = 'u0006';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1($this->confirmGroup(self::CONFIRMER)));
    }

    /**
     * 開く前は見えない。開いた後は未確認のタブに出る。確認すると確認済のタブへ移る。差戻しで閉じた後も見え、確認済に残る。
     */
    public function test_the_target_sees_the_case_once_it_opens_and_after_it_closes(): void
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->assertDetail($case, self::CONFIRMER, false);

        $this->approveChief($case);
        $this->assertDetail($case, self::CONFIRMER, true);
        $this->assertSame([[$case->id], []], $this->tabs(self::CONFIRMER, $case));

        $this->confirmAs($case, self::CONFIRMER);
        $this->assertSame([[], [$case->id]], $this->tabs(self::CONFIRMER, $case));

        $this->sendBackOn($case, 'council.1', 'u0005', 'section_chief');
        $this->assertDetail($case, self::CONFIRMER, true);
        $this->assertSame([[], [$case->id]], $this->tabs(self::CONFIRMER, $case));
    }

    /**
     * 確認しないまま、差戻しで閉じた・案件が完了した案件は、どちらのタブにも出ないが閲覧はできる。確認してから完了した案件は確認済に出る。
     */
    public function test_an_unconfirmed_case_leaves_both_tabs_when_closed_or_completed_but_stays_visible(): void
    {
        $closed = $this->openedCase();
        $this->sendBackOn($closed, 'council.1', 'u0005', 'section_chief');
        $completed = $this->openedCase();
        $this->operateOn(ApproveCaseEarly::class, $completed, 'council.1', 'u0005');
        $confirmedThenCompleted = $this->openedCase();
        $this->confirmAs($confirmedThenCompleted, self::CONFIRMER);
        $this->operateOn(ApproveCaseEarly::class, $confirmedThenCompleted, 'council.1', 'u0005');

        $this->assertSame([[], []], $this->tabs(self::CONFIRMER, $closed));
        $this->assertSame([[], []], $this->tabs(self::CONFIRMER, $completed));
        $this->assertSame([[], [$confirmedThenCompleted->id]], $this->tabs(self::CONFIRMER, $confirmedThenCompleted));
        $this->assertDetail($closed, self::CONFIRMER, true);
        $this->assertDetail($completed, self::CONFIRMER, true);
    }

    /**
     * 未確認のタブに出る案件と、確認の状態の 1 か所が言う「自分が未確認(未完了の案件)」の案件が一致する。
     */
    public function test_the_unconfirmed_tab_matches_the_confirmation_states(): void
    {
        $cases = [$this->openedCase(), $this->openedCase(), $this->openedCase(), $this->openedCase()];
        $this->confirmAs($cases[1], self::CONFIRMER);
        $this->sendBackOn($cases[2], 'council.1', 'u0005', 'section_chief');
        $this->operateOn(ApproveCaseEarly::class, $cases[3], 'council.1', 'u0005');
        $ids = array_map(fn (WorkflowCase $case) => $case->id, $cases);
        $userId = $this->seededUser(self::CONFIRMER)->id;
        $byStates = array_values(array_filter($ids, function (int $id) use ($userId) {
            $case = WorkflowCase::query()->findOrFail($id);
            $states = app(CaseConfirmations::class)->statesOf($case);
            $mine = array_map(fn (array $byUser) => $byUser[$userId] ?? null, $states);
            $isActive = $case->state === WorkflowCaseState::Active;

            return $isActive && in_array(WorkflowConfirmationProgress::Unconfirmed, $mine, true);
        }));

        $requester = $this->requester(self::CONFIRMER);
        $query = WorkflowCase::query()->whereIn('id', $ids);
        $tab = $this->visibility()->unconfirmed($query, $requester, $this->operationDay());

        $this->assertSame([$ids[0]], $byStates);
        $this->assertSame($byStates, $tab->orderBy('id')->pluck('id')->all());
    }

    /**
     * 確認対象者でない人(処理した課長)と、確認対象者の代理先(代理で確認しない)のタブには出ない。
     */
    public function test_the_tabs_are_only_for_the_target_itself(): void
    {
        $this->addProxy($this->seededUser(self::CONFIRMER), $this->seededUser('u0016'));
        $case = $this->openedCase();
        $confirmed = $this->openedCase();
        $this->confirmAs($confirmed, self::CONFIRMER);

        foreach (['u0002', 'u0016'] as $code) {
            $this->assertSame([[], []], $this->tabs($code, $case), $code);
            $this->assertSame([[], []], $this->tabs($code, $confirmed), $code);
        }
    }

    /**
     * 確認対象者だけの人が、確認の業務処理で確認でき(404 にならない)、確認依頼の通知の移り先が詳細の URL を持つ(対象の表示名なし)。
     */
    public function test_a_confirmation_only_target_can_confirm_and_the_request_links_to_the_detail(): void
    {
        $case = $this->openedCase();
        $nodeId = $this->labelIds($case)['section_chief'];

        $this->assertSame([$nodeId], $this->confirmAs($case, self::CONFIRMER));

        $rows = app(NoticeList::class)->latest($this->contextOf($this->seededUser(self::CONFIRMER)));
        $type = WorkflowNoticeType::ConfirmRequest->value;
        $requests = array_values(array_filter($rows, fn (array $row) => $row['type'] === $type));
        $this->assertCount(1, $requests);
        $this->assertSame([route('workflow.cases.show', ['case' => $case->id]), null, null], [
            $requests[0]['url'], $requests[0]['targetLabel'], $requests[0]['unavailableText'],
        ]);
    }

    /**
     * 確認が開いた案件(課長が承認した後。確認対象者 u0006)。
     */
    private function openedCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveChief($case);

        return $case;
    }

    private function approveChief(WorkflowCase $case): void
    {
        $this->approve($case, 'section_chief', 'u0002', self::DESIGNATION);
    }

    /**
     * その人の [未確認のタブ, 確認済のタブ] に出る、渡した案件の id。
     *
     * @return array{list<int>, list<int>}
     */
    private function tabs(string $userCode, WorkflowCase $case): array
    {
        $requester = $this->requester($userCode);
        $ids = fn (string $tab) => array_values(array_map('intval', $this->visibility()
            ->{$tab}(WorkflowCase::query()->whereKey($case->id), $requester, $this->operationDay())
            ->pluck('id')->all()));

        return [$ids('unconfirmed'), $ids('confirmed')];
    }

    /**
     * 閲覧の判定と詳細の GET(200 か 404)が、期待の見え方と一致する。
     */
    private function assertDetail(WorkflowCase $case, string $userCode, bool $visible): void
    {
        $isVisible = $this->visibility()->isVisible($this->requester($userCode), $this->operationDay(), $case->id);
        $this->assertSame($visible, $isVisible, $userCode);
        $this->actingAs($this->seededUser($userCode))->get(route('workflow.cases.show', ['case' => $case->id]))
            ->assertStatus($visible ? 200 : 404);
    }

    private function requester(string $userCode): Requester
    {
        return new Requester($this->seededUser($userCode)->id, null);
    }

    private function visibility(): CaseVisibility
    {
        return app(CaseVisibility::class);
    }
}
