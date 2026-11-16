<?php

namespace Tests\Feature\Ai;

use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\Role;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTermTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;

/**
 * 探す・読むのテストのデータ: 依頼者のロールにだけ閲覧を許す業務モジュール m_tasks(名前「タスク」。表示の項目 title・選択 status・整数 amount・
 * 一覧に出さず条件に使えない note・ユーザー owner・依頼者の読めない m_refs を指す ref・明細 lines(品目 item))と、レコードを書く人(writer。
 * 登録と更新を許したロール)。{@see ArrangesToolPermissions} と一緒に使う。基準日は {@see self::TODAY}。
 */
trait BuildsToolRecords
{
    private RecordModule $tasks;

    private RecordModule $refs;

    /** @var array<string, RecordField> */
    private array $taskFields = [];

    private User $writer;

    private function arrangeTasks(): void
    {
        $this->tasks = $this->moduleFor('m_tasks', [RecordModuleAction::Read]);
        $this->tasks->update(['name' => 'タスク']);
        $this->refs = $this->recordModule('m_refs', []);
        $listed = ['is_listed' => true, 'is_searchable' => true];
        $tasks = $this->tasks;
        $this->taskFields = [
            'title' => $this->field($tasks, 'title', RecordFieldType::String, ['name' => '件名', 'is_title' => true]),
            'status' => $this->choiceField($tasks, 'status', ['open', 'done'], ['name' => '状態', ...$listed]),
            'amount' => $this->field($tasks, 'amount', RecordFieldType::Integer, ['name' => '金額', ...$listed]),
            'note' => $this->field($tasks, 'note', RecordFieldType::String, ['name' => 'メモ', 'is_multiline' => true]),
            'owner' => $this->field($tasks, 'owner', RecordFieldType::User, ['name' => '担当', 'is_listed' => true]),
            'ref' => $this->referenceField($tasks, 'ref', $this->refs, ['name' => '参照', 'is_listed' => true]),
            'lines' => $this->detailsField($tasks, 'lines', ['name' => '明細']),
        ];
        $this->field($this->childOf($this->taskFields['lines']), 'item', RecordFieldType::String, ['name' => '品目']);
        $this->writer = $this->user();
        $role = Role::factory()->create();
        $this->grant($this->writer, $role);
        $writes = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];
        $this->permit('m_tasks', $writes, new SubjectConditionInput(roleId: $role->id));
        $this->permit('m_refs', $writes, new SubjectConditionInput(roleId: $role->id));
    }

    /**
     * writer が業務処理で書く m_tasks のレコード。
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $items  明細の行の品目
     * @param  list<array{type: string, id: int}>|null  $viewers  見せる人
     */
    private function task(array $values, array $items = [], ?bool $restricted = null, ?array $viewers = null): int
    {
        $rows = array_map(fn (string $item) => new RecordDetailRowInput(values: ['item' => $item]), $items);
        $input = new RecordInput(
            $values,
            ['lines' => $rows],
            rowAccessRestricted: $restricted,
            rowAccessViewers: $viewers,
        );

        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'm_tasks', $input);
    }

    /** writer が業務処理で書く、依頼者の読めない m_refs のレコード */
    private function refRecord(): int
    {
        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'm_refs', new RecordInput([]));
    }

    /**
     * writer が業務処理で m_tasks のレコードを直す(更新履歴の版が 1 つ増える)。
     *
     * @param  array<string, mixed>  $values
     */
    private function updateTask(int $recordId, array $values): void
    {
        $version = Record::query()->findOrFail($recordId)->version();
        $input = new RecordInput($values);
        app(UpdateRecord::class)->execute($this->contextFor($this->writer->id), 'm_tasks', $recordId, $input, $version);
    }

    /**
     * 業務処理を通さずに作る m_tasks のレコード(値は項目のコード → 保存の形の値。長さの上限を通らない値を置ける)。
     *
     * @param  array<string, mixed>  $values
     */
    private function storedTask(array $values, bool $restricted = false): int
    {
        $stored = [];

        foreach ($values as $code => $value) {
            $stored[(string) $this->taskFields[$code]->id] = $value;
        }

        return Record::factory()->create([
            'record_module_id' => $this->tasks->id,
            'field_values' => $stored,
            'row_access_restricted' => $restricted,
        ])->id;
    }

    /**
     * 期間ごとに名前(ja)の違うユーザー(期間 = [開始日, 終了日, 名前] の並び)。
     *
     * @param  list<array{string, string|null, string}>  $terms
     */
    private function userNamedOver(array $terms): User
    {
        $user = $this->user(array_map(fn (array $term): array => [$term[0], $term[1]], $terms));
        $ja = Locale::query()->where('code', 'ja')->sole()->id;

        foreach ($user->userTerms()->orderBy('start_date')->get() as $index => $term) {
            $name = $terms[$index][2];
            UserTermTranslation::factory()->create(['user_term_id' => $term->id, 'locale_id' => $ja, 'name' => $name]);
        }

        return $user;
    }
}
