<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use App\Actions\Records\LockedRecordUpdate;
use App\Models\Records\Record;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use LogicException;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 判定しない更新は保存に判定する者を渡さない(docs の 設計/AI/公開の口.md の 3.2 の「この文脈を判定の入力に渡さない」)。
 * 入力の断りを外して他モジュール参照の項目が保存に届いても、参照先の判定に届かず作りの誤りで止まる。
 */
final class LockedRecordUpdateTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    public function test_the_unchecked_update_passes_no_requester_to_the_save(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->mock(LockedRecordUpdate::class, function (MockInterface $mock): void {
            $mock->shouldReceive('throwIfHeld')->once();
            $mock->shouldReceive('write')->once()->withArgs(fn (mixed $requester): bool => $requester === null);
        });

        app(UpdateRecordUnchecked::class)->execute(
            $this->contextFor($fixture['people']['creator']->id),
            'meetings',
            $fixture['recordId'],
            new RecordInput(['title' => '議事録']),
            new RecordRevisionOrigin('AI(議事録の作成)'),
        );
    }

    /**
     * 判定の 2 つのクラスは allows を持ち Mockery の MockInterface と名前が重なるため、模型は LegacyMockInterface だけを持つ。
     */
    public function test_a_reference_reaching_the_save_without_a_requester_is_a_programming_error(): void
    {
        $fixture = $this->rowAccessFixture();
        $projects = $this->recordModule('projects');
        $this->referenceField($fixture['module'], 'project', $projects);
        $project = Record::factory()->create(['record_module_id' => $projects->id]);
        $module = app(RecordDefinitionQuery::class)->load($fixture['module']);
        $record = Record::query()->findOrFail($fixture['recordId']);
        $references = $record->recordReferences()->count();
        $this->mock(RecordModuleGate::class, fn (LegacyMockInterface $mock) => $mock->shouldNotReceive('allows'));
        $this->mock(RecordRowAccess::class, fn (LegacyMockInterface $mock) => $mock->shouldNotReceive('constrain'));

        $this->assertThrows(
            fn () => app(LockedRecordUpdate::class)->write(
                null,
                $this->contextFor($fixture['people']['creator']->id),
                $module,
                $record,
                new RecordInput(['project' => $project->id]),
                new RecordRevisionOrigin('AI(議事録の作成)'),
            ),
            LogicException::class,
            '判定しない更新の保存で、参照先の判定に届いた',
        );
        $this->assertSame($references, $record->recordReferences()->count());
    }
}
