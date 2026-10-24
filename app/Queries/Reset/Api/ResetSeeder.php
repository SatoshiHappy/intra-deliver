<?php

namespace App\Queries\Reset\Api;

/**
 * 毎日の初期化と初期データだけを入れるコマンドで流す seeder の名前の口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。
 * 呼ぶ時と保証の正本 = 設計/公開の配備/毎日の初期化.md 7 節の表)。初期データを足すモジュールが読み取りの層に実装し、起動(App\Providers)が
 * タグ {@see self::TAG} で登録する。Reset は、名前を付けない db:seed(既定の seeder)の後ろに、返した名前を登録の順に db:seed --class で流す。
 * seeder は DB 定義の層でほかの層から名指せないので、クラスでなく db:seed の引数の名前で渡す(コード構成 2 節)。
 */
interface ResetSeeder
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'reset.seeders';

    /** db:seed の --class に渡す名前(名前空間を省くと Database\Seeders の下を指す) */
    public function seederName(): string;
}
