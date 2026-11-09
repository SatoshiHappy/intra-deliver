<?php

namespace Tests\Feature\Ai;

use App\Models\Ai\AiRunStep;
use Illuminate\Support\Facades\DB;

/**
 * ステップと道具の行の表のテストが使う行の作り方。
 */
trait BuildsConversationSteps
{
    /**
     * 処理中の回の答えのステップ(実行を渡さなければ、新しい会話の型の実行)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function step(array $attributes = []): AiRunStep
    {
        return AiRunStep::factory()->create($attributes);
    }

    /**
     * 行を直に書き換える(検査制約を確かめるため、列挙のキャストを通さない値を置く)。
     *
     * @param  array<string, mixed>  $values
     */
    private function updateRow(string $table, int $id, array $values): void
    {
        DB::table($table)->where('id', $id)->update($values);
    }
}
