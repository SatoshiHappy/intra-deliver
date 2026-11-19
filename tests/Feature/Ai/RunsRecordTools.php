<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallWrapper;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Queries\Records\Api\RecordAttachmentQuery;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordListQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Tests\Feature\Ai\RecordReads\LoggedActivityQuery;
use Tests\Feature\Ai\RecordReads\LoggedAttachmentQuery;
use Tests\Feature\Ai\RecordReads\LoggedDisplayQuery;
use Tests\Feature\Ai\RecordReads\LoggedFormQuery;
use Tests\Feature\Ai\RecordReads\LoggedListQuery;
use Tests\Feature\Ai\RecordReads\LoggedViewAccess;
use Tests\Feature\Ai\RecordReads\RecordReadLog;

/**
 * 本物の探す・読む(起動がタグで登録した道具)を、道具を呼ぶ包みに通すテストの準備: 依頼者と業務モジュール({@see ArrangesToolPermissions})に、
 * 道具が呼ぶ Records の読む口の記録({@see RecordReadLog})と、会話の保存の偽物と、実行の始めの 1 段目を足す。
 */
trait RunsRecordTools
{
    use ArrangesToolPermissions;

    private RecordReadLog $reads;

    private AiRun $run;

    private GrantedTools $granted;

    private FakeConversationStore $store;

    private AiRunStep $step;

    /** 道具が呼ぶ読む口を、呼び出しを記録する口に差し替える(道具を作る前に呼ぶ) */
    private function logRecordReads(): void
    {
        $this->reads = new RecordReadLog;
        $logged = [
            RecordViewAccess::class => LoggedViewAccess::class,
            RecordListQuery::class => LoggedListQuery::class,
            RecordDisplayQuery::class => LoggedDisplayQuery::class,
            RecordActivityQuery::class => LoggedActivityQuery::class,
            RecordAttachmentQuery::class => LoggedAttachmentQuery::class,
            RecordFormQuery::class => LoggedFormQuery::class,
        ];

        foreach ($logged as $api => $class) {
            $instance = app($class);
            $instance->log = $this->reads;
            $this->app->instance($api, $instance);
        }
    }

    /**
     * 本物の道具(既定 = 読む 2 つ)を渡す実行を作り、1 段目を済ませる。
     *
     * @param  list<string>  $knowledge
     * @param  list<string>  $tools
     */
    private function startRecordToolRun(array $knowledge, array $tools = ['ai_read_record', 'ai_search_records']): void
    {
        $this->run = $this->runFor($tools, $knowledge);
        $this->granted = app(ToolGrants::class)->grant(
            $this->run->agentDefinition()->sole(),
            app(ToolSubjects::class)->for($this->run),
        );

        if (! isset($this->store)) {
            FakeConversationStore::createTables();
            $this->store = new FakeConversationStore;
        }

        $this->store->startConversation($this->run->id);
    }

    /**
     * 道具の呼び出し 1 つを、新しいステップで包みに通し、会話の保存に足した道具の結果の中身を返す。
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments, string $callId = 'c1'): array
    {
        $this->step = AiRunStep::factory()->create(['ai_run_id' => $this->run->id]);
        $step = new ToolStep($this->run, $this->step, $this->granted, $this->store);
        $call = new ModelToolCall($callId, $name, $arguments);
        app(ToolCallWrapper::class)->run($step, [$call], fn (): ?AiRunStopReason => null);
        $items = $this->store->read($this->run->id) ?? [];

        return $items === [] ? [] : $items[array_key_last($items)]->content;
    }

    /**
     * 道具の結果の本文を JSON として読む。
     *
     * @param  array<string, mixed>  $content
     * @return array<array-key, mixed>
     */
    private static function body(array $content): array
    {
        $body = $content['body'] ?? null;

        return is_string($body) ? (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** 呼び出しの id の道具の行 */
    private function toolRow(string $callId = 'c1'): AiToolCall
    {
        return AiToolCall::query()->where('ai_run_id', $this->run->id)->where('call_id', $callId)->sole();
    }

    /** 道具の行の全文 */
    private function fullText(string $callId = 'c1'): ?string
    {
        $text = AiToolCallText::query()->whereKey($this->toolRow($callId)->id)->value('result_text');

        return is_string($text) ? $text : null;
    }
}
