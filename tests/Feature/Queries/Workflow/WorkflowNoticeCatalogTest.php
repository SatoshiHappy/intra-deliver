<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowNoticeType;
use App\Queries\Notice\Api\NoticeTargets;
use App\Queries\Workflow\WorkflowNoticeTargets;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの通知の種類の列挙と、画面の文の雛形・移り先の登録の突合(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節と 5 節。列挙が正本):
 * 種類の値ごとに雛形 resources/js/lib/Notice/noticeText.ts に case があり、雛形の switch に default があり、使う対象の種類(案件・代理)に
 * 起動のタグで登録した実装が答える。
 */
final class WorkflowNoticeCatalogTest extends TransactionalTestCase
{
    private const TEMPLATE = 'resources/js/lib/Notice/noticeText.ts';

    public function test_every_notice_type_has_a_template_case_and_the_switch_has_a_default(): void
    {
        $template = (string) file_get_contents(base_path(self::TEMPLATE));

        foreach (WorkflowNoticeType::cases() as $type) {
            $this->assertStringContainsString("case '{$type->value}':", $template, $type->value);
        }

        $this->assertStringContainsString('default:', $template);
    }

    public function test_the_case_and_proxy_target_types_are_answered_by_the_tagged_implementation(): void
    {
        $answering = [];

        foreach ($this->app->tagged(NoticeTargets::TAG) as $implementation) {
            foreach ($implementation->targetTypes() as $targetType) {
                $answering[$targetType][] = $implementation::class;
            }
        }

        $this->assertSame([WorkflowNoticeTargets::class], $answering[WorkflowNoticeTargets::CASE_TARGET_TYPE] ?? []);
        $this->assertSame([WorkflowNoticeTargets::class], $answering[WorkflowNoticeTargets::PROXY_TARGET_TYPE] ?? []);
    }
}
