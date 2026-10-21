<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Master\User;
use App\Queries\Records\Api\RecordShowPanelQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の欄の行の頭に出る AI の契機(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節・9 節の「AI の書き込み」):
 * 「AI(議事録の作成)」「AI(議事録の再作成)」がそのまま操作として出て、ステップ名は無く、操作した人は起動した利用者・代理元は無い。
 * 値が変わらない再作成でも版が増え、人の編集の行には契機が出ない。
 */
final class RecordRevisionAiOriginTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 03:00:00 UTC');
        $meetings = $this->recordModule('meetings');
        $this->field($meetings, 'title');
        $this->field($meetings, 'minutes', attributes: ['is_multiline' => true]);
    }

    public function test_the_two_words_of_ai_are_the_operation_of_the_head_with_the_requester_as_the_actor(): void
    {
        [$requester, $editor] = [$this->user(), $this->user()];
        $recordId = app(CreateRecord::class)
            ->execute($this->contextFor($requester->id), 'meetings', new RecordInput(['title' => '定例']));
        $minutes = new RecordInput(['minutes' => '決定事項は 3 つ。']);

        $this->write($recordId, $requester, $minutes, new RecordRevisionOrigin('AI(議事録の作成)'));
        $this->write($recordId, $requester, $minutes, new RecordRevisionOrigin('AI(議事録の再作成)'));
        $this->write($recordId, $editor, new RecordInput(['minutes' => '追記']), null);

        $rows = $this->rows($recordId);

        $this->assertSame([
            [4, $editor->id, null, null],
            [3, $requester->id, null, ['operation' => 'AI(議事録の再作成)', 'nodeName' => null]],
            [2, $requester->id, null, ['operation' => 'AI(議事録の作成)', 'nodeName' => null]],
            [1, $requester->id, null, null],
        ], array_map(fn (array $row) => [
            $row['revision'], $row['actor']['id'] ?? null, $row['principal']['id'] ?? null, $row['origin'],
        ], $rows));
    }

    public function test_a_rewrite_with_the_same_text_still_makes_a_revision_with_the_head_only(): void
    {
        $requester = $this->user();
        $recordId = app(CreateRecord::class)
            ->execute($this->contextFor($requester->id), 'meetings', new RecordInput(['minutes' => '同じ']));

        $origin = new RecordRevisionOrigin('AI(議事録の再作成)');
        $this->write($recordId, $requester, new RecordInput(['minutes' => '同じ']), $origin);

        [$latest] = $this->rows($recordId);
        $this->assertSame([2, 'update', null], [$latest['revision'], $latest['action'], $latest['changes']]);
        $this->assertSame('AI(議事録の再作成)', $latest['origin']['operation'] ?? null);
    }

    private function write(int $recordId, User $user, RecordInput $input, ?RecordRevisionOrigin $origin): void
    {
        $context = $this->contextFor($user->id);
        app(UpdateRecord::class)->execute($context, 'meetings', $recordId, $input, null, origin: $origin);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $recordId): array
    {
        $panel = app(RecordShowPanelQuery::class);
        $content = $panel->content($this->contextFor(), 'meetings', $recordId, 'revisions', null);

        return $content['rows'] ?? [];
    }
}
