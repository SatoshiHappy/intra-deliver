<?php

namespace App\Queries\Authz;

/**
 * 認可設定の画面の格子の行 1 つ = リソースグループ(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1。{@see OverviewTree} が作る)。
 */
final readonly class OverviewTreeNode
{
    /**
     * @param  int|null  $parentId  木の親の行のリソースグループ(先頭 = null)
     * @param  int  $depth  先頭 = 0
     * @param  string|null  $name  ロケールの表示名(行が無ければ null)
     * @param  bool  $holdsResource  格子のタイプのリソースを持つ(判定する行)
     */
    public function __construct(
        public int $resourceGroupId,
        public ?int $parentId,
        public int $depth,
        public string $code,
        public ?string $name,
        public bool $holdsResource,
    ) {}
}
