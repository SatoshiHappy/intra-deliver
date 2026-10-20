<?php

namespace App\Queries\Authz\Api;

/**
 * 守るルートの口(依存の逆転 = docs の 設計/コード構成/README.md 3 節、守りの正本 = 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節)。
 * Authz より後ろのモジュールが自分の読み取りの層にこれを実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。
 * 認可の編集(セルの切替・列の削除)は、書いた後に、返したルートのそれぞれを指定の判定を求める者が実行できるかを評価し、外れれば巻き戻す。
 *
 * Authz の公開の口(保証の正本 = docs の 設計/ワークフロー/申請の周辺/既存の設計への反映.md の公開の口の行)。実装は鍵を取らず、例外を投げない
 * (投げた例外は守りの評価ごと 500 になる)。
 */
interface GuardedRoutes
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'authz.guarded-routes';

    /**
     * 認可の編集の後も、指定の判定を求める者が execute を許されていなければならないルート。
     *
     * @return list<GuardedRoute>
     */
    public function routes(): array;
}
