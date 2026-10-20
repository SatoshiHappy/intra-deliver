<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版の契機(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 2 節・3 節・4.1 節、代理の列の向き =
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節): 契機を渡した書き込みは、差分が空でも版を作り、
 * 契機の語を写す。代理(実行者を持つ契機)の版は操作した人 = 実行者・代理元 = AccessContext の利用者で、作成者と認可は代理元。
 */
final class RecordRevisionOriginTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /**
     * @return array<string, array{Closure(): RecordRevisionOrigin}>
     */
    public static function malformedOrigins(): array
    {
        return [
            '空の処理の種別' => [fn () => new RecordRevisionOrigin('')],
            '101 文字の処理の種別' => [fn () => new RecordRevisionOrigin(str_repeat('申', 101))],
            '空のステップ名' => [fn () => new RecordRevisionOrigin('申請', '')],
            '1001 文字のステップ名' => [fn () => new RecordRevisionOrigin('申請', str_repeat('課', 1001))],
            '0 の実行者' => [fn () => new RecordRevisionOrigin('申請', null, 0)],
        ];
    }

    /**
     * @param  Closure(): RecordRevisionOrigin  $make
     */
    #[DataProvider('malformedOrigins')]
    public function test_a_malformed_origin_is_rejected(Closure $make): void
    {
        $this->expectException(InvalidArgumentException::class);

        $make();
    }

    public function test_the_longest_origin_is_accepted(): void
    {
        $origin = new RecordRevisionOrigin(str_repeat('申', 100), str_repeat('課', 1000), 1);

        $lengths = [mb_strlen($origin->operation), mb_strlen($origin->nodeName ?? '')];
        $this->assertSame([100, 1000, 1], [...$lengths, $origin->executorUserId]);
    }

    public function test_a_write_with_an_origin_always_writes_a_revision_with_the_origin(): void
    {
        $this->field($this->recordModule('projects'), 'title');
        $user = $this->user();
        $recordId = $this->create($user->id, new RecordRevisionOrigin('申請', '申請'));

        $this->update($recordId, $user->id, new RecordRevisionOrigin('再申請', '申請'));

        $this->assertSame(
            [[1, '申請', '申請', $user->id, null], [2, '再申請', '申請', $user->id, null]],
            $this->revisionRows($recordId),
        );
    }

    public function test_a_write_with_an_origin_on_a_record_without_revisions_writes_update_revision_one(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        $this->update($record->id, null, new RecordRevisionOrigin('再申請', '申請'));

        $revision = RecordRevision::query()->where('record_id', $record->id)->sole();
        $this->assertSame(
            [1, RecordRevisionAction::Update, '再申請'],
            [$revision->revision, $revision->action, $revision->origin_operation],
        );
    }

    public function test_an_executor_who_is_the_context_user_is_rejected_before_any_lock_or_write(): void
    {
        $this->field($this->recordModule('projects'), 'title');
        $user = $this->user();
        $recordId = $this->create($user->id);
        $before = Record::query()->count();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $this->assertThrows(
            fn () => $this->update($recordId, $user->id, new RecordRevisionOrigin('再申請', null, $user->id), '変えた'),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => $this->create($user->id, new RecordRevisionOrigin('申請', '申請', $user->id)),
            InvalidArgumentException::class,
        );

        // 鍵も書き込みも出ない(出るのはテストが開いたときの版を読む select だけ)
        $pattern = '/pg_advisory|for no key update|^(insert|update|delete)/i';
        $locksOrWrites = fn (string $sql) => preg_match($pattern, $sql) === 1;
        $this->assertSame([], array_values(array_filter($statements, $locksOrWrites)));
        $this->assertSame($before, Record::query()->count());
        $this->assertSame(1, RecordRevision::query()->where('record_id', $recordId)->count());
        $this->assertSame([], Record::query()->findOrFail($recordId)->field_values);
    }

    public function test_a_proxy_write_records_the_executor_and_the_principal_and_is_authorized_as_the_principal(): void
    {
        $this->field($this->recordModule('projects', [RecordModuleAction::Read]), 'title');
        [$principal, $executor] = [$this->user(), $this->user()];
        $this->permit($principal->id, [RecordModuleAction::Create, RecordModuleAction::Update]);

        $recordId = $this->create($principal->id, new RecordRevisionOrigin('申請', '申請', $executor->id));
        $this->update($recordId, $principal->id, new RecordRevisionOrigin('再申請', '申請', $executor->id));

        $this->assertSame(
            [[1, '申請', '申請', $executor->id, $principal->id], [2, '再申請', '申請', $executor->id, $principal->id]],
            $this->revisionRows($recordId),
        );
        $record = Record::query()->findOrFail($recordId);
        $this->assertSame([$principal->id, $principal->id], [$record->created_by_user_id, $record->updated_by_user_id]);
        $this->assertThrows(
            fn () => $this->create($executor->id, new RecordRevisionOrigin('申請', '申請', $principal->id)),
            AuthorizationException::class,
        );
    }

    private function create(?int $userId, ?RecordRevisionOrigin $origin = null): int
    {
        return app(CreateRecord::class)->execute($this->contextFor($userId), 'projects', new RecordInput([]), $origin);
    }

    private function update(int $recordId, ?int $userId, RecordRevisionOrigin $origin, ?string $title = null): void
    {
        $input = new RecordInput($title === null ? [] : ['title' => $title]);
        $version = Record::query()->findOrFail($recordId)->version();
        $context = $this->contextFor($userId);
        app(UpdateRecord::class)->execute($context, 'projects', $recordId, $input, $version, origin: $origin);
    }

    /**
     * @return list<array{int, string|null, string|null, int|null, int|null}>
     */
    private function revisionRows(int $recordId): array
    {
        return array_values(RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->get()->map(
            fn (RecordRevision $revision) => [
                $revision->revision, $revision->origin_operation, $revision->origin_node_name,
                $revision->user_id, $revision->principal_user_id,
            ],
        )->all());
    }

    /**
     * 業務モジュール projects のアクションを、そのユーザーだけに許す。
     *
     * @param  list<RecordModuleAction>  $actions
     */
    private function permit(int $userId, array $actions): void
    {
        $groupCode = RecordModuleAction::resourceGroupCode('projects');
        $groupId = AuthzResourceGroup::query()->where('code', $groupCode)->soleValue('id');
        $condition = new SubjectConditionInput(userId: $userId);
        $subjectGroupId = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
        $type = RecordModuleAction::RESOURCE_TYPE;

        foreach ($actions as $action) {
            app(CreatePolicy::class)->execute($groupId, $subjectGroupId, $type, $action->value, PolicyEffect::Permit);
        }
    }
}
