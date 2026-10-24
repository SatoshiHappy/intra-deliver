<?php

namespace App\Console\Commands\Reset;

use App\Actions\Reset\SeedInitialData as SeedInitialDataAction;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * 空の DB に、毎日の初期化と同じ並びで初期データだけを入れるコマンド(docs の 設計/公開の配備/毎日の初期化.md 7 節、配備と戻し方.md 6.2 の 7)。
 * 配備のスクリプトが流すので、production でも確認の問いを出さない。
 */
final class SeedInitialData extends Command
{
    protected $signature = 'reset:seed';

    protected $description = '空の DB に初期データだけを入れる(毎日の初期化と同じ並び)';

    public function handle(SeedInitialDataAction $seed): int
    {
        try {
            $seed->execute();
        } catch (RuntimeException $error) {
            report($error);
            $this->error('初期データを入れられませんでした(入れた分は巻き戻しました): '.$error->getMessage());

            return self::FAILURE;
        }

        $this->info('初期データを入れました');

        return self::SUCCESS;
    }
}
