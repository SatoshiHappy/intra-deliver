<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\HttpResponse;
use UpdateCheck\ImageReader;
use UpdateCheck\ImageSpec;
use UpdateCheck\ImageUpdateCheck;
use UpdateCheck\ImageUpdateFinder;
use UpdateCheck\Registry;
use UpdateCheck\RegistryClient;
use UpdateCheck\Section;
use UpdateCheck\Status;
use UpdateCheck\UnconfirmedException;

/**
 * #4: 固定しているイメージ(タグ@digest)の新しいタグと、固定したタグが今指す digest。レジストリは決めた応答に置き換える。
 */
final class ImageUpdateCheckTest extends UpdateCheckTestCase
{
    private const OLD = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const NEW = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const TOKEN = 'GET https://auth.docker.io/token?service=registry.docker.io&scope=repository:cloudflare/cloudflared:pull';

    private const TAGS = 'GET https://registry-1.docker.io/v2/cloudflare/cloudflared/tags/list?n=10000';

    private const MANIFEST = 'HEAD https://registry-1.docker.io/v2/cloudflare/cloudflared/manifests/2026.9.3';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/updcheck-test-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents($this->root.'/compose.yml', 'image: cloudflare/cloudflared:2026.9.3@'.self::OLD."\n");
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->root.'/*') ?: []);
        rmdir($this->root);

        parent::tearDown();
    }

    private function spec(): ImageSpec
    {
        return new ImageSpec('cloudflared', 'cloudflare/cloudflared', Registry::DockerHub, 'cloudflare/cloudflared', ['compose.yml'], null);
    }

    /**
     * @param  array<string, HttpResponse>  $responses
     */
    private function check(array $responses): Section
    {
        $registry = new RegistryClient(new FakeHttpClient($responses));

        return (new ImageUpdateCheck((new ImageUpdateFinder($registry, new ImageReader($this->root)))->findAll([$this->spec()])))->run();
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, HttpResponse>
     */
    private function registry(array $tags, string $digest = self::OLD): array
    {
        return [
            self::TOKEN => FakeHttpClient::json(['token' => 't']),
            self::TAGS => FakeHttpClient::json(['tags' => $tags]),
            self::MANIFEST => new HttpResponse(200, ['docker-content-digest' => $digest], ''),
            'HEAD https://registry-1.docker.io/v2/cloudflare/cloudflared/manifests/2026.10.0' => new HttpResponse(200, ['docker-content-digest' => self::NEW], ''),
            'HEAD https://registry-1.docker.io/v2/cloudflare/cloudflared/manifests/2026.11.0' => new HttpResponse(200, ['docker-content-digest' => self::NEW], ''),
        ];
    }

    public function test_同じ形の新しいタグがあれば更新あり(): void
    {
        $section = $this->check($this->registry(['2026.9.3', '2026.10.0', 'latest']));

        $this->assertSame(Status::Update, $section->rows[0]->status);
        $this->assertSame(['cloudflared', '2026.9.3', '2026.10.0', '同じ'], $section->rows[0]->cells);
    }

    public function test_固定したタグが今指す_digest_が変わっていれば更新あり(): void
    {
        $section = $this->check($this->registry(['2026.9.3'], self::NEW));

        $this->assertSame(Status::Update, $section->rows[0]->status);
        $this->assertSame('変わった: '.self::NEW, $section->rows[0]->cells[3]);
    }

    public function test_新しいタグも_digest_の変化も無ければ表示だけ(): void
    {
        $section = $this->check($this->registry(['2026.9.3', 'latest']));

        $this->assertSame(Status::Info, $section->rows[0]->status);
        $this->assertSame([], $section->unconfirmed);
    }

    public function test_タグの一覧は_link_の次のページを追う(): void
    {
        $responses = $this->registry(['2026.9.3']);
        $responses[self::TAGS] = new HttpResponse(200, ['link' => '</v2/cloudflare/cloudflared/tags/list?last=a&n=10000>; rel="next"'], (string) json_encode(['tags' => ['2026.9.3']]));
        $responses['GET https://registry-1.docker.io/v2/cloudflare/cloudflared/tags/list?last=a&n=10000'] = FakeHttpClient::json(['tags' => ['2026.11.0']]);

        $this->assertSame('2026.11.0', $this->check($responses)->rows[0]->cells[2]);
    }

    public function test_429_は理由を上限にして確認できなかったにする(): void
    {
        $responses = $this->registry(['2026.9.3']);
        $responses[self::TAGS] = new HttpResponse(429, [], '');

        $section = $this->check($responses);

        $this->assertSame([], $section->rows);
        $this->assertStringStartsWith('上限', $section->unconfirmed[0]->reason);
    }

    public function test_タグの一覧に_tags_が無ければ確認できなかった(): void
    {
        $responses = $this->registry([]);
        $responses[self::TAGS] = FakeHttpClient::json(['errors' => []]);

        $this->assertCount(1, $this->check($responses)->unconfirmed);
    }

    public function test_digest_が書かれていなければ確認できなかった(): void
    {
        file_put_contents($this->root.'/compose.yml', "image: cloudflare/cloudflared:2026.9.3\n");

        $this->assertStringContainsString('digest が書かれていない', $this->check($this->registry(['2026.9.3']))->unconfirmed[0]->reason);
    }

    public function test_同じイメージを書く場所で値が違えば確認できなかった(): void
    {
        file_put_contents($this->root.'/other.yml', 'image: cloudflare/cloudflared:2026.9.2@'.self::OLD."\n");
        $spec = new ImageSpec('cloudflared', 'cloudflare/cloudflared', Registry::DockerHub, 'cloudflare/cloudflared', ['compose.yml', 'other.yml'], null);

        $this->expectException(UnconfirmedException::class);
        $this->expectExceptionMessage('書かれた場所で値が違う');

        try {
            (new ImageReader($this->root))->read($spec);
        } finally {
            unlink($this->root.'/other.yml');
        }
    }

    public function test_名前の一部が同じ別のイメージは読まない(): void
    {
        file_put_contents($this->root.'/compose.yml', "image: registry.example/cloudflare/cloudflared:9.9.9\nimage: cloudflare/cloudflared:2026.9.3@".self::OLD."\n");

        $this->assertSame('2026.9.3', (new ImageReader($this->root))->read($this->spec())->tag);
    }

    /**
     * @param  array<string, HttpResponse>  $responses
     */
    private function finder(array $responses): ImageUpdateFinder
    {
        return new ImageUpdateFinder(new RegistryClient(new FakeHttpClient($responses)), new ImageReader($this->root));
    }

    public function test_新しい土台は_同じ形の最新タグの_digest(): void
    {
        $responses = $this->registry(['2026.9.3', '2026.10.0']);
        $update = $this->finder($responses)->find($this->spec());

        $this->assertSame('cloudflare/cloudflared:2026.10.0@'.self::NEW, $update->newBase());
    }

    public function test_新しいタグが無く_digest_だけ変わったら固定したタグの今の_digest_が新しい土台(): void
    {
        $update = $this->finder($this->registry(['2026.9.3'], self::NEW))->find($this->spec());

        $this->assertSame('cloudflare/cloudflared:2026.9.3@'.self::NEW, $update->newBase());
    }

    public function test_新しいタグも_digest_の変化も無ければ新しい土台は無い(): void
    {
        $update = $this->finder($this->registry(['2026.9.3']))->find($this->spec());

        $this->assertNull($update->newBase());
        $this->assertFalse($update->hasUpdate());
    }

    public function test_調べられなかったイメージは理由を持ち新しい土台は無い(): void
    {
        $results = $this->finder([])->findAll([$this->spec()]);

        $this->assertSame([], $results->updates);
        $this->assertArrayHasKey('cloudflared', $results->failures);
        $this->assertNull($results->newBase('cloudflared'));
    }
}
