<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\BuildsUploads;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 合議の票(縦配置の子 = 展開の行)で付ける添付(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・9.1 節):
 * 操作するノードは子の行で、版は 1 つ、版を指す履歴の行は票の 1 行だけ。ルート = 申請 → chief(u0002。指定する)→
 * family(縦配置。人数 2 で可決)→ final(u0010)。
 */
final class StepAttachmentCouncilTest extends TransactionalTestCase
{
    use BuildsUploads;
    use OperatesOnRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
    }

    public function test_a_council_vote_with_files_writes_one_revision_for_its_history_row(): void
    {
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009']]);
        $vote = WorkflowCaseNode::query()->with('parent')->findOrFail($this->labelIds($case)['family.1']);
        $u0005 = $this->seededUser('u0005');

        $result = app(ApproveCase::class)->execute(
            $this->contextOf($u0005), $case->id, $this->revisionOf($case), $vote->id, null,
            attachFiles: [$this->uploadedFile('票.pdf')],
        );

        $revisions = RecordRevision::query()->where('record_id', $case->record_id)->orderBy('revision')->get();
        $latest = $revisions->last() ?? $this->fail('版が無い');
        $this->assertSame([2, '承認', $vote->displayName(), $u0005->id], [
            $revisions->count(), $latest->origin_operation, $latest->origin_node_name, $latest->user_id,
        ]);
        $pointing = WorkflowCaseHistory::query()->where('record_revision_id', $latest->id)->pluck('id')->all();
        $this->assertSame([$result->historyId], $pointing);
        $attachedBy = RecordAttachment::query()->where('record_id', $case->record_id)->pluck('user_id')->all();
        $this->assertSame([$u0005->id], $attachedBy);
    }
}
