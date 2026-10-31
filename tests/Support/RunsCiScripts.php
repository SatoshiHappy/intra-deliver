<?php

namespace Tests\Support;

use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\TempDirectory;
use UnexpectedValueException;

/**
 * 一括検査 scripts/ci/all と、段の scripts/ci/front --e2e・scripts/ci/static・scripts/ci/test、E2E の組のサーバー scripts/ci/e2e-server、
 * 写しの Actions の apt の控えの道具 scripts/ci/apt-archives を、偽の段・偽のコマンドで流すテストが使う共通の道具。DB を使わない。
 * 使うクラスは Tests\TestCase を継承する({@see RunsDeployScripts} と同じ形)。
 *
 * 一時の場所(build/fixtures の下)に、6 本のスクリプトを写した作業木(work)と、道具の置き場(tools)を作る。
 * all は自分の 2 つ上へ移って scripts/ci/<段> を相対パスで呼ぶので、写した作業木の段だけが呼ばれ、本物の段・本物の DB・本物の記録には届かない。
 * 段は偽のスクリプトに置き換える({@see installStages()})。tools の偽の date は D の鍵の日付を決める。
 * front --e2e の確かめでは、偽の php・npm も tools に置く({@see installFrontTools()})。work には artisan を置かない。
 * e2e-server の確かめでは、偽の php を tools に置く({@see installE2eServerTools()})。
 * static の確かめでは、偽の phpstan・ast-grep・record を work に置く(StaticScriptTest)。installStages() は写した static・test も偽の段に置き換える。
 * test の確かめでは、偽の record を work に、偽の php を tools に置く(TestScriptTest)。
 * apt-archives の確かめでは、偽の apt-cache・dpkg-query・sha256sum・cp を tools に置く(FakesAptTools)。
 */
trait RunsCiScripts
{
    protected string $root;

    protected string $work;

    protected string $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $root = realpath(TempDirectory::create(base_path('build/fixtures'), 'ci-scripts'));
        $this->assertNotFalse($root, '一時のディレクトリの実パスを決められない');
        $this->root = $root;
        $this->work = $this->root.'/work';
        $this->tools = $this->root.'/tools';
        mkdir($this->work.'/scripts/ci', 0777, true);
        mkdir($this->tools, 0777, true);

        foreach (['all', 'front', 'static', 'test', 'e2e-server', 'apt-archives'] as $script) {
            copy(base_path('scripts/ci/'.$script), $this->work.'/scripts/ci/'.$script);
            chmod($this->work.'/scripts/ci/'.$script, 0755);
        }

        // D の鍵が読むディレクトリ(無いと D が鍵を計算できずに失敗する)
        foreach (['tests/Architecture/Engine', 'tests/Architecture/Policy', 'scripts/update-check/src'] as $directory) {
            mkdir($this->work.'/'.$directory, 0777, true);
            file_put_contents($this->work.'/'.$directory.'/Sample.php', "<?php\n");
        }

        $this->installDate('2026-10-19');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);

        parent::tearDown();
    }

    /**
     * 偽の date(D の鍵の日付を決める。日付の境目で揺れない)。
     */
    protected function installDate(string $date): void
    {
        $this->writeTool('date', sprintf("#!/bin/sh\nprintf '%%s\\n' %s\n", escapeshellarg($date)));
    }

    /**
     * all が呼ぶ段を偽のスクリプトに置き換える。偽の段は「段の名前と引数」を calls.log に 1 行書き、決めた終了コードで終わる。
     * 終了コードは段の名前(format・static・psr4・secrets・deps・front・test・verify)で渡す。front は N(引数なし)の終了コードで、
     * E(引数 --e2e)の終了コードは 'front --e2e' で渡す。渡さない段は 0。
     *
     * @param  array<string, int>  $exitCodes
     */
    protected function installStages(array $exitCodes = []): void
    {
        foreach (['format', 'static', 'psr4', 'secrets', 'deps', 'front', 'test', 'verify'] as $stage) {
            $exit = $exitCodes[$stage] ?? 0;
            $exitForE2e = $exitCodes['front --e2e'] ?? 0;
            $decision = $stage === 'front'
                ? "if [ \"\$1\" = \"--e2e\" ]; then exit {$exitForE2e}; fi\nexit {$exit}\n"
                : "exit {$exit}\n";

            $path = $this->work.'/scripts/ci/'.$stage;
            $record = "printf '%s\\n' \"{$stage}\${1:+ \$*}\" >> ".escapeshellarg($this->callsPath());
            file_put_contents($path, "#!/bin/sh\n{$record}\n{$decision}");
            chmod($path, 0755);
        }
    }

    /**
     * front --e2e の確かめ用の入力と偽の php・npm を置く。
     * work には、決め方のクラス({@see WorktreeTestEnvironment})を本物のまま読む vendor/autoload.php・config/guardrails.php(本物の値)と、
     * 開発用 DB の名前・SERVER_PORT を書いた .env を置く。
     * 偽の php: `-r` だけ本物の php に渡す(決め方のクラスを呼ぶ行を本物のまま通す)。artisan の tinker は決めた接尾辞・接続先を出し、
     * migrate:fresh・db:seed は引数と DB_DATABASE の値を calls.log に書いて成功、それ以外は 99 で終わる。偽の npm は引数と E2E_PORT を書いて成功。
     *
     * @param  string|null  $developmentDatabase  .env の DB_DATABASE(null = 書かない)
     * @param  string|null  $serverPort  .env の SERVER_PORT(null = 書かない)
     */
    protected function installFrontTools(
        ?string $developmentDatabase,
        string $suffix,
        string $connectedDatabase,
        ?string $serverPort = '8190',
    ): void {
        file_put_contents($this->work.'/package-lock.json', "{}\n");
        mkdir($this->work.'/public/build', 0777, true);
        file_put_contents($this->work.'/public/build/manifest.json', "{}\n");
        mkdir($this->work.'/vendor', 0777, true);
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        file_put_contents($this->work.'/vendor/autoload.php', "<?php\nrequire {$autoload};\n");
        mkdir($this->work.'/config', 0777, true);
        copy(base_path('config/guardrails.php'), $this->work.'/config/guardrails.php');
        file_put_contents($this->work.'/.env', implode('', array_filter([
            $developmentDatabase === null ? null : "DB_DATABASE={$developmentDatabase}\n",
            $serverPort === null ? null : "SERVER_PORT={$serverPort}\n",
        ])));

        $calls = escapeshellarg($this->callsPath());
        $this->writeTool('php', implode("\n", [
            '#!/bin/sh',
            'if [ "$1" = "-r" ]; then exec '.escapeshellarg(PHP_BINARY).' "$@"; fi',
            'if [ "$1" = "artisan" ]; then',
            '  case "$2" in',
            '    tinker)',
            '      case "$4" in',
            '        *test_db_suffix*) printf \'%s\' '.escapeshellarg($suffix).'; exit 0 ;;',
            '        *current_database*) printf \'%s\' '.escapeshellarg($connectedDatabase).'; exit 0 ;;',
            '      esac ;;',
            '    migrate:fresh|db:seed)',
            "      printf '%s\\n' \"\$* DB_DATABASE=\$DB_DATABASE\" >> {$calls}; exit 0 ;;",
            '  esac',
            'fi',
            'exit 99',
            '',
        ]));
        $this->writeTool('npm', "#!/bin/sh\nprintf '%s\\n' \"npm \$* E2E_PORT=\$E2E_PORT\" >> {$calls}\nexit 0\n");
    }

    /**
     * e2e-server の確かめ用の偽の php を置く。artisan の tinker は渡された文(改行は空白にして 1 行)と環境変数(DB_DATABASE・
     * E2E_GROUP_DATABASE・E2E_TEMPLATE_DATABASE)を calls.log に書いて $tinkerExitCode で終わる(0 でなければ、本物の tinker のように
     * 誤りの文を stdout に出す)。serve は引数と DB_DATABASE を書いて成功、それ以外は 99 で終わる。
     */
    protected function installE2eServerTools(int $tinkerExitCode = 0): void
    {
        $calls = escapeshellarg($this->callsPath());
        $this->writeTool('php', implode("\n", [
            '#!/bin/sh',
            'if [ "$1" = "artisan" ]; then',
            '  case "$2" in',
            '    tinker)',
            "      printf '%s\\n' \"\$(printf '%s' \"\$*\" | tr '\\n' ' ')"
                .' DB_DATABASE=$DB_DATABASE E2E_GROUP_DATABASE=$E2E_GROUP_DATABASE'
                ." E2E_TEMPLATE_DATABASE=\$E2E_TEMPLATE_DATABASE\" >> {$calls}",
            "      [ {$tinkerExitCode} -eq 0 ] || echo 'source database is being accessed by other users'",
            "      exit {$tinkerExitCode} ;;",
            '    serve)',
            "      printf '%s\\n' \"\$* DB_DATABASE=\$DB_DATABASE\" >> {$calls}; exit 0 ;;",
            '  esac',
            'fi',
            'exit 99',
            '',
        ]));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment  追加・上書きする環境変数(親の環境の値を消したいときは空文字にする)
     */
    protected function runCi(string $script, array $arguments = [], array $environment = []): ProcessResult
    {
        // CI_FULL は空で上書きする(親の環境の CI_FULL=1 を引き継ぐと既定の回を確かめられない)
        return Process::run(
            [$this->work.'/scripts/ci/'.$script, ...$arguments],
            $this->work,
            ['PATH' => $this->tools.':'.getenv('PATH'), 'CI_FULL' => '', ...$environment],
        );
    }

    /**
     * 偽の段・偽のコマンドが書いた呼び出し(1 呼び出し 1 行)。
     *
     * @return list<string>
     */
    protected function calls(): array
    {
        if (! is_file($this->callsPath())) {
            return [];
        }

        $lines = file($this->callsPath(), FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new UnexpectedValueException('記録のファイルを読めない: '.$this->callsPath());
        }

        return $lines;
    }

    protected function resetCalls(): void
    {
        if (is_file($this->callsPath())) {
            unlink($this->callsPath());
        }
    }

    private function callsPath(): string
    {
        return $this->tools.'/calls.log';
    }

    private function writeTool(string $name, string $script): void
    {
        file_put_contents($this->tools.'/'.$name, $script);
        chmod($this->tools.'/'.$name, 0755);
    }
}
