<?php

namespace App\Queries\Workflow;

/**
 * 申請の詳細で求められた props と URL のクエリ(入口が要求から作る)。props の当て方を inertia-laravel の PropsResolver にそろえる
 * (求めた props だけを組むため = docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2.2 節)。
 */
final readonly class CaseShowSelection
{
    /**
     * @param  bool  $partial  この画面の部分の再読み込みか
     * @param  list<string>|null  $only  部分の再読み込みで求めた props(null = 指定なし)
     * @param  list<string>|null  $except  部分の再読み込みで除いた props(null = 指定なし)
     */
    public function __construct(
        public bool $partial = false,
        public ?array $only = null,
        public ?array $except = null,
        public mixed $panel = null,
        public mixed $before = null,
        public mixed $tab = null,
        public mixed $related = null,
        public mixed $relatedPage = null,
    ) {}

    /**
     * その props を応答に入れるか(全体の応答では最初の応答に入らない props = $optional だけを除く)。
     */
    public function wants(string $key, bool $optional = false): bool
    {
        if (! $this->partial) {
            return ! $optional;
        }

        $isAsked = fn (string $path) => $path === $key || str_starts_with($path, "{$key}.");
        $asked = $this->only === null || array_filter($this->only, $isAsked) !== [];

        return $asked && ! in_array($key, $this->except ?? [], true);
    }

    /**
     * どれかの props を応答に入れるか。
     *
     * @param  list<string>  $keys
     */
    public function wantsAny(array $keys): bool
    {
        return array_filter($keys, fn (string $key) => $this->wants($key)) !== [];
    }
}
