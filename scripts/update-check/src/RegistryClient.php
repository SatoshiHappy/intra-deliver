<?php

namespace UpdateCheck;

/**
 * レジストリのタグの一覧と、タグが今指す digest(マニフェスト一覧の digest)を読む。
 * 標準の Distribution API(GET /v2/<path>/tags/list・HEAD /v2/<path>/manifests/<tag>)で、書き込みはしない。
 */
final readonly class RegistryClient
{
    private const MAX_PAGES = 30;

    private const MANIFEST_TYPES = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    public function __construct(private HttpClient $http) {}

    /**
     * @return list<string>
     *
     * @throws UnconfirmedException
     */
    public function tags(Registry $registry, string $path): array
    {
        $headers = $this->bearer($registry, $path);
        $url = $registry->api().'/v2/'.$path.'/tags/list?n=10000';
        $tags = [];

        for ($page = 0; $url !== ''; $page++) {
            if ($page >= self::MAX_PAGES) {
                throw new UnconfirmedException("{$path} のタグが多すぎる(".self::MAX_PAGES.' ページで読み切れない)');
            }

            $response = $this->http->request('GET', $url, $headers)->ensureSuccess("{$path} のタグ一覧");
            $list = Json::table(Json::parse($response->body, "{$path} のタグ一覧"), 'tags', "{$path} のタグ一覧");

            $tags = [...$tags, ...$this->strings($list, $path)];

            $url = $this->nextUrl($registry, $response);
        }

        return $tags;
    }

    /**
     * @param  array<array-key, mixed>  $list
     * @return list<string>
     *
     * @throws UnconfirmedException 文字列でない要素がある
     */
    private function strings(array $list, string $path): array
    {
        $strings = [];

        foreach ($list as $item) {
            if (! is_string($item)) {
                throw new UnconfirmedException("{$path} のタグ一覧に文字列でない要素がある");
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @throws UnconfirmedException
     */
    public function digest(Registry $registry, string $path, string $tag): string
    {
        $headers = $this->bearer($registry, $path) + ['Accept' => self::MANIFEST_TYPES];
        $response = $this->http
            ->request('HEAD', $registry->api().'/v2/'.$path.'/manifests/'.$tag, $headers)
            ->ensureSuccess("{$path}:{$tag} のマニフェスト");
        $digest = $response->headers['docker-content-digest'] ?? '';

        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new UnconfirmedException("{$path}:{$tag} の digest を読めない");
        }

        return $digest;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(Registry $registry, string $path): array
    {
        $response = $this->http->request('GET', $registry->tokenUrl($path))->ensureSuccess("{$path} の匿名トークン");
        $token = Json::string(Json::parse($response->body, "{$path} の匿名トークン"), 'token', "{$path} の匿名トークン");

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * 次のページの URL(Link 見出しの rel="next")。無ければ空文字。
     */
    private function nextUrl(Registry $registry, HttpResponse $response): string
    {
        if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $response->headers['link'] ?? '', $match) !== 1) {
            return '';
        }

        $next = str_starts_with($match[1], '/') ? $registry->api().$match[1] : $match[1];

        // 匿名のトークンを付けるので、同じレジストリの外へは追わない
        return str_starts_with($next, $registry->api().'/') ? $next : '';
    }
}
