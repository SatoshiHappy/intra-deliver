<?php

namespace Tests\Unit\Support;

use App\Providers\AppServiceProvider;
use App\Support\PrivateProviders;
use Illuminate\Cache\CacheServiceProvider;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 手元だけのリポジトリのプロバイダの一覧の読み方(docs の 設計/AI/設定とプロンプト/README.md 11.4 の 2 つの版): 無いファイルは空、プロバイダのクラスの
 * 名前の一覧を返すファイルはその一覧、読めない・一覧でない・クラスでない・プロバイダでない名前は作りの誤り(一時のファイルで確かめる)。
 */
final class PrivateProvidersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/private-providers-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_a_missing_file_lists_nothing(): void
    {
        $this->assertSame([], PrivateProviders::listedIn($this->directory.'/providers.php'));
    }

    public function test_a_file_returning_provider_class_names_lists_them_in_order(): void
    {
        $listed = CacheServiceProvider::class.'::class, '.AppServiceProvider::class.'::class';
        $path = $this->write("<?php\n\nreturn [{$listed}];\n");

        $this->assertSame([CacheServiceProvider::class, AppServiceProvider::class], PrivateProviders::listedIn($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedFiles(): array
    {
        return [
            '一覧でない(文字列)' => ["<?php\n\nreturn 'x';\n", 'がクラスの名前の一覧を返さない'],
            '一覧でない(鍵つきの配列)' => ["<?php\n\nreturn ['a' => ".AppServiceProvider::class."::class];\n", 'がクラスの名前の一覧を返さない'],
            '文字列でない値' => ["<?php\n\nreturn [1];\n", 'に、プロバイダのクラスの名前でない値がある: 1'],
            '在らないクラス' => ["<?php\n\nreturn ['MissingProvider'];\n", "に、プロバイダのクラスの名前でない値がある: 'MissingProvider'"],
            'プロバイダでないクラス' => ["<?php\n\nreturn [".stdClass::class."::class];\n", "に、プロバイダのクラスの名前でない値がある: 'stdClass'"],
            '読めない(構文の誤り)' => ["<?php\n\nreturn [\n", 'を読めない: '],
        ];
    }

    #[DataProvider('malformedFiles')]
    public function test_a_malformed_file_is_a_construction_error(string $content, string $message): void
    {
        $path = $this->write($content);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("手元のプロバイダの一覧 {$path} {$message}");

        PrivateProviders::listedIn($path);
    }

    private function write(string $content): string
    {
        $path = $this->directory.'/providers.php';
        file_put_contents($path, $content);

        return $path;
    }
}
