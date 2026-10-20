<?php

namespace Tests\Feature\Ai;

use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Queries\Ai\RunVisibility;
use App\Queries\Authz\Api\Requester;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * AI の実行の閲覧が、対象のレコードの閲覧の判定(read と行の判定)に従う(docs の 設計/AI/公開の口.md 3 節の閲覧、
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節)。依頼者はいつも見られ、対象のレコードが消えた実行は依頼者だけ。
 */
final class RunVisibilityRowAccessTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    public function test_others_need_the_row_access_to_the_target_record(): void
    {
        $fixture = $this->rowAccessFixture();
        $requester = $fixture['people']['stranger'];
        $run = $this->aiRun($requester, $fixture['recordId']);

        $this->assertTrue($this->canView($requester, $run));
        $this->assertTrue($this->canView($fixture['people']['attendee'], $run));
        $this->assertTrue($this->canView($fixture['people']['assignee'], $run));
        $this->assertFalse($this->canView($fixture['people']['watcher'], $run));
        $this->assertFalse($this->canView(null, $run));
    }

    public function test_a_run_whose_record_was_deleted_is_visible_only_to_the_requester(): void
    {
        $fixture = $this->rowAccessFixture();
        $requester = $fixture['people']['stranger'];
        $run = AiRun::factory()->create([
            'requester_user_id' => $requester->id,
            'record_id' => null,
            'original_record_id' => $fixture['recordId'],
            'record_module_code' => 'meetings',
        ]);

        $this->assertTrue($this->canView($requester, $run));
        $this->assertFalse($this->canView($fixture['people']['creator'], $run));
    }

    private function aiRun(User $requester, int $recordId): AiRun
    {
        return AiRun::factory()->create([
            'requester_user_id' => $requester->id,
            'record_id' => $recordId,
            'original_record_id' => $recordId,
            'record_module_code' => 'meetings',
        ]);
    }

    private function canView(?User $user, AiRun $run): bool
    {
        $requester = new Requester($user?->id, null);

        return app(RunVisibility::class)->canView($requester, $run, CarbonImmutable::parse(self::TODAY));
    }
}
