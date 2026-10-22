<?php

namespace App\Queries\Authz\Api;

use App\Queries\Authz\ResourceTypeCatalog;

/**
 * あるモジュールが定義する、認可のリソースタイプとそのアクションの一覧(依存の逆転 = docs の 設計/コード構成/README.md 3 節、
 * 設計/メニュー/認可との結び付け.md 2.1)。
 *
 * 一覧はモジュールごとにコードの列挙で定義する(DB の検査制約にしない = 設計/共通マスタ/ロールと認可/リソース.md の authz_resources)。
 * Authz は後ろのモジュールに依存できないので、各モジュールがこれを読み取りの層に実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。
 * タグで束ねた実装の和は {@see ResourceTypeCatalog} の 1 か所で作り、Authz の業務処理はそれで検査する。
 */
interface ResourceTypes
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'authz.resource-types';

    /**
     * リソースタイプの ID → そのタイプのアクションの ID の一覧。
     *
     * @return array<string, list<string>>
     */
    public function actionsByResourceType(): array;
}
