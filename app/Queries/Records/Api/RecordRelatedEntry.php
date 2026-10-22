<?php

namespace App\Queries\Records\Api;

/**
 * 関連レコードの入口 1 つ({@see RecordRelatedQuery::entries()} が返す値。docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の
 * 1 節・3 節・4.1 節・7 節)。
 */
final readonly class RecordRelatedEntry
{
    /**
     * @param  string  $key  入口のキー(<参照元の業務モジュール>.<項目>。明細の項目は <業務モジュール>.<明細の項目>.<項目>)
     * @param  string  $name  入口の名前
     * @param  int  $count  見える参照元の件数(行の判定を通るもの。明細の行は親で 1 件)
     * @param  bool  $canCreate  「新規」を出せるか(4.1 節)
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $count,
        public bool $canCreate,
    ) {}

    /**
     * 画面に渡す形(詳細の画面/README.md の 6.1 節の RecordRelatedEntry)。
     *
     * @return array{key: string, name: string, count: int, canCreate: bool}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'count' => $this->count, 'canCreate' => $this->canCreate];
    }
}
