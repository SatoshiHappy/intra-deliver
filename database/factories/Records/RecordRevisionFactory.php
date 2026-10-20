<?php

namespace Database\Factories\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use stdClass;

/**
 * 既定はゲストが業務の画面で登録した版 1(契機なし。写しは値の無いレコードの形)。
 *
 * @extends Factory<RecordRevision>
 */
class RecordRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'record_id' => fn (array $attributes) => Record::factory()
                ->state(['record_module_id' => $attributes['record_module_id']]),
            'revision' => 1,
            'action' => RecordRevisionAction::Create,
            // 写像の位置は JSON のオブジェクトで書く(添付の一覧だけ配列 =
            // 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1 節)
            'snapshot' => [
                'values' => new stdClass, 'references' => new stdClass, 'details' => new stdClass, 'attachments' => [],
            ],
            'labels' => ['fields' => new stdClass, 'options' => new stdClass],
            'user_id' => null,
            'principal_user_id' => null,
            'origin_operation' => null,
            'origin_node_name' => null,
        ];
    }

    /**
     * 更新の版にする(版の番号は既定の 1 のまま = 前の版の無いレコードの最初の版)。
     */
    public function update(): static
    {
        return $this->state(fn (): array => ['action' => RecordRevisionAction::Update]);
    }

    /**
     * 操作した人のいる版にする。
     */
    public function byUser(): static
    {
        return $this->state(fn (): array => ['user_id' => User::factory()]);
    }

    /**
     * 申請の処理の契機(処理の種別とステップ名)を持つ版にする。
     */
    public function fromOperation(string $operation = '承認', ?string $nodeName = '課長'): static
    {
        return $this->state(fn (): array => ['origin_operation' => $operation, 'origin_node_name' => $nodeName]);
    }

    /**
     * 代理で処理した版にする(実行者と別の代理元を持つ。契機が要る)。
     */
    public function byProxy(): static
    {
        return $this->fromOperation()->byUser()->state(fn (): array => ['principal_user_id' => User::factory()]);
    }
}
