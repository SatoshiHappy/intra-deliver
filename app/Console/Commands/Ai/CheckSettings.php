<?php

namespace App\Console\Commands\Ai;

use App\Actions\Ai\PromptKinds;
use Illuminate\Console\Command;

/**
 * 起動の時に確かめるもの(docs の 設計/AI/データと失敗と検査.md 8 節)を、本番の設定(環境変数を含む)で確かめるコマンド。
 * 判定の定義は {@see PromptKinds::problems()} の 1 か所(文章と文字起こしの両方の型を見る)で、ここは一覧を出して終了コードにするだけ。
 */
final class CheckSettings extends Command
{
    protected $signature = 'ai:check-settings';

    protected $description = 'AI の種類・単価・予算の区分・ジョブの時間の設定の食い違いを確かめる(食い違いがあれば終了コード 1)';

    public function handle(PromptKinds $kinds): int
    {
        $problems = $kinds->problems();

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('AI の設定に食い違いは無い');

        return self::SUCCESS;
    }
}
