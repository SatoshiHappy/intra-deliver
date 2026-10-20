<?php

namespace UpdateCheck;

use Throwable;

/**
 * endoflife.date の API(v1)から、製品の系列・サポート期限・系列の最新の版を読む。
 */
final class EolClient
{
    /** @var array<string, list<EolCycle>|Throwable> 製品 → 系列の一覧、または照会の失敗(失敗も覚え、使うときに投げる) */
    private array $cache = [];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * 製品の表を先に取っておく(docker の検査より前に HTTP の照会を済ませ、予算を docker の項目が使い切っても #6・#7 の照会が残るようにする)。
     * 失敗は覚えておき、その製品を使う行で確認できなかったにする。
     *
     * @param  list<string>  $products
     */
    public function prefetch(array $products): void
    {
        foreach ($products as $product) {
            try {
                $this->cycles($product);
            } catch (Throwable) {
                // 失敗は cache に覚えてあり、使うときに投げられて、その製品の行の確認できなかったになる
            }
        }
    }

    /**
     * @return list<EolCycle>
     *
     * @throws UnconfirmedException
     */
    public function cycles(string $product): array
    {
        $cached = $this->cache[$product] ??= $this->fetch($product);

        if ($cached instanceof Throwable) {
            throw $cached;
        }

        return $cached;
    }

    /**
     * @return list<EolCycle>|Throwable
     */
    private function fetch(string $product): array|Throwable
    {
        try {
            $what = "endoflife.date {$product}";
            $response = $this->http->request('GET', 'https://endoflife.date/api/v1/products/'.$product.'/')->ensureSuccess($what);
            $releases = Json::table(Json::table(Json::parse($response->body, $what), 'result', $what), 'releases', $what);

            return array_map(
                static fn (array $release): EolCycle => new EolCycle(Json::string($release, 'name', $what), $release, $what),
                Json::tables($releases, $what),
            );
        } catch (Throwable $exception) {
            // 想定外の例外のトレースはここで 1 回だけ残し、行ごとに投げ直されるのは理由の文だけの例外にする
            return new UnconfirmedException(Unconfirmeds::from("endoflife.date {$product}", $exception)->reason, 0, $exception);
        }
    }

    /**
     * @throws UnconfirmedException 系列が無い
     */
    public function cycle(string $product, string $name): EolCycle
    {
        foreach ($this->cycles($product) as $cycle) {
            if ($cycle->name === $name) {
                return $cycle;
            }
        }

        throw new UnconfirmedException("endoflife.date {$product} に系列 {$name} が無い");
    }
}
