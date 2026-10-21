<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の画面・案件一覧・案件の詳細のページが添付の props を渡す(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節。値の規則は
 * 読み取りのテスト = ApplyAttachmentsTest・CaseAttachEntriesTest)。初期データを入れ、ルートの認可を外さずに開く。
 */
final class WorkflowAttachmentPropsTest extends TransactionalTestCase
{
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAsUser('u0001');
    }

    public function test_the_application_forms_carry_the_attachment(): void
    {
        $form = fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->whereType('attachment.enabled', 'boolean')
            ->where('attachment.limits.maxFiles', 3)
            ->whereType('attachment.limits.tooLarge', 'string')
            ->whereType('attachment.drafts', 'array');

        $this->get('/workflow/flows/project_plan/apply')->assertOk()->assertInertia(fn (Assert $page) => $form($page)
            ->where('attachment.drafts', []));
        $this->get('/workflow/drafts/'.$this->seededDraft().'/edit')->assertOk()->assertInertia($form);
    }

    /**
     * 入口が 1 つでも添付を受けるページは attachmentLimits が値で、1 つも無いページは null(値は読み取りのテストが見る)。
     */
    public function test_the_case_pages_carry_the_limits_exactly_when_an_entry_accepts_files(): void
    {
        $rows = [];
        $this->get('/workflow/cases?tab=processed')->assertOk()->assertInertia(function (Assert $page) use (&$rows) {
            $props = $page->component('Workflow/CaseIndex')->toArray()['props'];
            $rows = $props['cases']['data'];
            $this->assertSame($this->anyEntry($rows) ? 3 : null, $props['attachmentLimits']['maxFiles'] ?? null);
        });
        $this->assertNotEmpty($rows);

        $shown = 0;

        foreach ($rows as $row) {
            $check = function (Assert $page) use (&$shown) {
                $props = $page->component('Workflow/CaseShow')->toArray()['props'];
                $entry = $this->anyEntry([$props]);
                $this->assertSame($entry ? 3 : null, $props['attachmentLimits']['maxFiles'] ?? null);
                $shown += $entry ? 1 : 0;
            };

            $this->get("/workflow/cases/{$row['id']}")->assertOk()->assertInertia($check);
        }

        $this->assertGreaterThan(0, $shown, '添付を受ける入口のある案件が 1 件も無いと、値を見る側が空振りする');
    }

    /**
     * @param  list<array<string, mixed>>  $pages  canAttachOnWithdraw と pullBackNodes を持つ行かページ
     */
    private function anyEntry(array $pages): bool
    {
        foreach ($pages as $page) {
            $nodes = array_column($page['pullBackNodes'] ?? [], 'canAttach');

            if (($page['canAttachOnWithdraw'] ?? false) === true || in_array(true, $nodes, true)) {
                return true;
            }
        }

        return false;
    }
}
