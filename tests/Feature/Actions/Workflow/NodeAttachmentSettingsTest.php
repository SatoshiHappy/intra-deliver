<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の「添付ファイルを追加できない」prevents_attachment_add の保存(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節。受け方は
 * 申請者本人の承認を防ぐと同じ形: 承認・横配置・縦配置だけが受け、ほかの種類は真を 422(NOT_ALLOWED)、偽と null は受けて偽で書く)。
 * 版 = R1(申請 apply・承認 section_chief と owner・縦配置 council・分岐 budget)と横配置の版(family)。
 */
final class NodeAttachmentSettingsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const NOT_ALLOWED = 'このステップには、この設定はできません';

    private int $r1;

    private int $serial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r1 = $this->addVersion($this->r1());
        $this->serial = $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial), '2026-06-01');
    }

    /**
     * 承認・横配置・縦配置は真も偽も保存できる(真 → 偽 → 真)。
     */
    public function test_the_approving_kinds_save_both_values(): void
    {
        $nodes = [[$this->r1, 'section_chief'], [$this->serial, 'family'], [$this->r1, 'council']];

        foreach ($nodes as [$version, $code]) {
            foreach ([true, false, true] as $value) {
                $this->saveSettings($version, $code, ['preventsAttachmentAdd' => $value]);

                $this->assertSame($value, $this->routeNode($version, $code)->prevents_attachment_add, $code);
            }
        }
    }

    /**
     * 申請・分岐は真を受けず(NOT_ALLOWED。ほかの欄の誤りと一緒に返る)、何も変わらない。偽と null は受ける。
     */
    public function test_the_other_kinds_reject_true_and_accept_false_or_null(): void
    {
        foreach ([[$this->r1, 'apply'], [$this->r1, 'budget_end']] as [$version, $code]) {
            $before = $this->settingsSnapshot();
            $errors = $this->validationErrors(
                fn () => $this->saveSettings($version, $code, ['preventsAttachmentAdd' => true]),
            );

            $this->assertSame(['prevents_attachment_add' => self::NOT_ALLOWED], $errors, $code);
            $this->assertSame($before, $this->settingsSnapshot());

            $this->saveSettings($version, $code, ['preventsAttachmentAdd' => false]);
            $this->saveSettings($version, $code, ['preventsAttachmentAdd' => null]);
            $this->assertFalse($this->routeNode($version, $code)->prevents_attachment_add, $code);
        }
    }

    /**
     * 入力が null(欠けた)のときは、承認でも偽で書く(申請者本人の承認を防ぐと同じ)。
     */
    public function test_a_missing_value_is_written_as_false(): void
    {
        $this->saveSettings($this->r1, 'section_chief', ['preventsAttachmentAdd' => true]);
        $this->saveSettings($this->r1, 'section_chief', ['preventsAttachmentAdd' => null]);

        $this->assertFalse($this->routeNode($this->r1, 'section_chief')->prevents_attachment_add);
    }
}
