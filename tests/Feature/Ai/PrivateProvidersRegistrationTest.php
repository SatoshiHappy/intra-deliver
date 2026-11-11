<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Providers\AppServiceProvider;
use App\Support\PrivateProviders;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\TempDirectory;
use Tests\TestCase;

/**
 * 手元だけのリポジトリのプロバイダの登録(docs の 設計/AI/設定とプロンプト/README.md 11.4 の 2 つの版・ADR 0039 の決定 8): AppServiceProvider が、
 * 一覧({@see PrivateProviders::PATH})が在るときだけ既定の結びの後に登録するので、移した版の結びが勝ち、無ければ登録しない(一時の根で組む)。
 * アプリ全体を起こしても同じ。bootstrap/providers.php は Laravel のコマンドの書き直し(addProviderToBootstrapFile)に耐え、手元の名前が入らない。
 */
final class PrivateProvidersRegistrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TempDirectory::create(sys_get_temp_dir(), 'private-providers');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);
        Container::setInstance($this->app);
        parent::tearDown();
    }

    public function test_without_the_list_the_default_version_is_bound_and_nothing_is_registered(): void
    {
        $app = $this->registered();

        $this->assertInstanceOf(DefaultInstructionBuilder::class, $app->make(InstructionBuilder::class));
        $this->assertSame($app->make(DefaultInstructionBuilder::class), $app->make(InstructionBuilder::class));
        $this->assertNull($app->getProvider(PrivateInstructionProvider::class));
    }

    public function test_the_listed_provider_rebinds_the_port_after_the_default(): void
    {
        $this->writeList($this->root);
        $app = $this->registered();

        $this->assertInstanceOf(ProblemInstructionBuilder::class, $app->make(InstructionBuilder::class));
        $provider = $app->getProvider(PrivateInstructionProvider::class);
        $this->assertInstanceOf(PrivateInstructionProvider::class, $provider);
    }

    /** アプリ全体(bootstrap/app.php とコンソールの起動)を、根だけを一時の根に替えて別のプロセスで起こす */
    public function test_the_whole_application_resolves_the_listed_version(): void
    {
        $shadow = $this->root.'/shadow';
        mkdir($shadow);

        foreach (array_diff(scandir(base_path()) ?: [], ['.', '..', '.git', 'ai-private']) as $entry) {
            symlink(base_path($entry), $shadow.'/'.$entry);
        }

        $this->writeList($shadow);
        $script = $this->root.'/resolve.php';
        file_put_contents($script, implode("\n", [
            '<?php',
            'require $argv[1]."/vendor/autoload.php";',
            '$app = require $argv[1]."/bootstrap/app.php";',
            '$app->setBasePath($argv[2]);',
            '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();',
            'echo get_class($app->make(App\Actions\Ai\Instructions\InstructionBuilder::class));',
        ])."\n");

        try {
            $result = Process::run([PHP_BINARY, $script, base_path(), $shadow], $this->root);
        } finally {
            foreach (scandir($shadow) ?: [] as $entry) {
                if (is_link($shadow.'/'.$entry)) {
                    unlink($shadow.'/'.$entry);
                }
            }
        }

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(ProblemInstructionBuilder::class, $result->stdout);
    }

    public function test_rewriting_the_bootstrap_file_keeps_the_listed_names_out(): void
    {
        $copy = $this->root.'/providers.php';
        copy(base_path('bootstrap/providers.php'), $copy);
        $before = require $copy;

        ServiceProvider::addProviderToBootstrapFile(AppServiceProvider::class, $copy);

        $this->assertSame($before, require $copy);
        $real = (string) file_get_contents(base_path('bootstrap/providers.php'));
        $this->assertStringNotContainsString(PrivateProviders::class, $real);
        $this->assertStringNotContainsString(PrivateProviders::class, (string) file_get_contents($copy));
    }

    /** 根 $root の一時の容器に AppServiceProvider だけを登録する */
    private function registered(): Application
    {
        $app = new Application($this->root);
        (new AppServiceProvider($app))->register();

        return $app;
    }

    /** 根 $root に手元のプロバイダの一覧(テストのプロバイダ 1 つ)を置く */
    private function writeList(string $root): void
    {
        mkdir(dirname($root.'/'.PrivateProviders::PATH), 0777, true);
        $list = "<?php\n\nreturn [".PrivateInstructionProvider::class."::class];\n";
        file_put_contents($root.'/'.PrivateProviders::PATH, $list);
    }
}
