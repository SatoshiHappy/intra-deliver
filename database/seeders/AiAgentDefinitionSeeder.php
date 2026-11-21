<?php

namespace Database\Seeders;

use App\Actions\Ai\Api\AgentDefinitionCandidates;
use App\Actions\Ai\Api\AgentDefinitionInput;
use App\Actions\Ai\Api\SaveAgentDefinition;
use Illuminate\Database\Seeder;

/**
 * 既定のエージェントの定義 1 件(値と理由 = docs の 設計/AI/設定とプロンプト/README.md 11.1 の初期データ)。定義の口を通して入れる
 * (保存の時の確かめを通す)。ナレッジの候補に入る業務モジュールを作る seeder の後に呼ぶ。
 */
class AiAgentDefinitionSeeder extends Seeder
{
    public const NAME = '業務アシスタント';

    public const INSTRUCTIONS = 'あなたは、この業務システムの利用者の業務を助ける対話型のエージェントである。'
        .'システムにあるデータはツールで探して読み、その内容に基づいて、利用者が業務で使う言葉で答える。';

    public function __construct(
        private SaveAgentDefinition $save,
        private AgentDefinitionCandidates $candidates,
    ) {}

    public function run(): void
    {
        $tools = array_map(static fn ($tool): string => $tool->name, $this->candidates->tools());
        $knowledge = array_column($this->candidates->knowledge(), 'code');

        $this->save->create(new AgentDefinitionInput(self::NAME, self::INSTRUCTIONS, $tools, $knowledge));
    }
}
