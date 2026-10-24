<?php

namespace App\Actions\Reset;

use App\Queries\Reset\InitialDataSeeders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 初期データを並び({@see InitialDataSeeders})のとおりに入れる(docs の 設計/公開の配備/毎日の初期化.md 7 節)。seeder は DB 定義の層で
 * ほかの層から名指せないので、db:seed のコマンドに名前で渡す(docs の 設計/コード構成/README.md 2 節・4 節の「止まらない経路」)。
 */
class SeedInitialData
{
    public function __construct(private InitialDataSeeders $seeders) {}

    /**
     * @throws RuntimeException db:seed が終了コード 0 で終わらなかったとき
     */
    public function execute(): void
    {
        DB::transaction(function (): void {
            foreach ($this->seeders->seedArguments() as $arguments) {
                $code = Artisan::call('db:seed', $arguments);

                if ($code !== 0) {
                    $class = is_string($arguments['--class'] ?? null) ? $arguments['--class'] : '既定の seeder';
                    $output = trim(Artisan::output());
                    throw new RuntimeException("初期データの db:seed({$class})が終了コード {$code} で終わった: {$output}");
                }
            }
        });
    }
}
