<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\PartyCandidates;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\Department;
use App\Models\Master\User;
use Carbon\CarbonImmutable;

/**
 * 探すの条件のユーザー・組織の相手のテストの準備: m_tasks に条件に使える相手の項目(charge・members・section・party)と使えない項目
 * (unsearched_section・unsearched_members)を足し、総務部に主所属の「探索 一郎」と部分一致の「探索 一郎二」を置き、名前の引き当ての回数を
 * 数える。{@see BuildsToolRecords}・{@see RunsRecordTools} と一緒に使う。
 */
trait ArrangesSearchParties
{
    private Department $general;

    private User $ichiro;

    private CountingPartyCandidates $candidates;

    private function arrangeSearchParties(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $searchable = ['is_listed' => true, 'is_searchable' => true];
        $fields = [
            'charge' => [RecordFieldType::User, ['name' => '担当者']],
            'members' => [RecordFieldType::User, ['name' => '参加者', 'is_multiple' => true]],
            'section' => [RecordFieldType::Department, ['name' => '部署']],
            'party' => [RecordFieldType::UserOrDepartment, ['name' => '相手']],
        ];

        foreach ($fields as $code => [$type, $attributes]) {
            $this->taskFields[$code] = $this->field($this->tasks, $code, $type, [...$attributes, ...$searchable]);
        }

        $this->field($this->tasks, 'unsearched_section', RecordFieldType::Department, ['name' => '使えない部署']);
        $unsearched = ['name' => '使えない参加者', 'is_multiple' => true];
        $this->field($this->tasks, 'unsearched_members', RecordFieldType::User, $unsearched);

        $this->general = Department::query()->where('code', 'general-affairs')->sole();
        $this->ichiro = $this->userNamedOver([['2020-01-01', null, '探索 一郎']]);
        $this->join($this->ichiro, $this->general, isMain: true);
        $this->userNamedOver([['2020-01-01', null, '探索 一郎二']]);
        $this->candidates = app(CountingPartyCandidates::class);
        $this->app->instance(PartyCandidates::class, $this->candidates);
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks']);
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function search(array $conditions, string $callId = 'c1'): array
    {
        return $this->callTool('ai_search_records', ['module' => 'm_tasks', 'conditions' => $conditions], $callId);
    }

    /**
     * 道具の検証の誤り(B1)の文。
     *
     * @param  array<string, mixed>  $content
     */
    private function validationError(array $content, string $callId): string
    {
        $this->assertSame(AiInstructionTemplate::ToolError->value, $content['template']);
        $this->assertSame(AiToolCallDecision::ValidationError, $this->toolRow($callId)->decision);

        return is_array($content['values'] ?? null) && is_string($content['values']['error'] ?? null)
            ? $content['values']['error']
            : '';
    }
}
