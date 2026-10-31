<?php

namespace Tests\Support;

use Tests\Architecture\Engine\ProcessResult;

/**
 * scripts/ci/apt-archives のテストが使う共通の道具(AptArchivesScriptTest・AptArchivesCollectScriptTest・
 * AptArchivesAnnotationScriptTest)。DB を使わない。
 * RunsCiScripts の作業木に、偽の apt-cache・dpkg-query・sha256sum・cp を tools に置く。控え($archive)と apt の置き場($aptDirectory)を一時の場所に作る。
 * 使うクラスは Tests\TestCase を継承する。
 */
trait FakesAptTools
{
    use RunsCiScripts {
        setUp as private setUpCiScripts;
    }

    private string $archive;

    private string $aptDirectory;

    protected function setUp(): void
    {
        $this->setUpCiScripts();

        $this->archive = $this->root.'/home/apt-archives';
        $this->aptDirectory = $this->root.'/apt-archives';
        mkdir($this->archive, 0777, true);
        mkdir($this->aptDirectory.'/partial', 0777, true);
        $this->installAptTools();
    }

    /**
     * @param  list<string>  $arguments  動作と引数
     */
    protected function apt(array $arguments, bool $githubActions = false): ProcessResult
    {
        $this->resetCalls();

        return $this->runCi('apt-archives', $arguments, ['GITHUB_ACTIONS' => $githubActions ? 'true' : '']);
    }

    /**
     * 偽の apt-cache・dpkg-query を tools に置く。どちらも呼び出しを calls.log に書く(dpkg-query は書かない)。
     * apt-cache show は tools/index/<引数の : = / を _ にしたもの> の中身を 0 で返し、無ければ 100 で終わる(本物と同じ)。
     */
    protected function installAptTools(int $policyExitCode = 0): void
    {
        $calls = escapeshellarg($this->tools.'/calls.log');
        $index = $this->tools.'/index';
        is_dir($index) || mkdir($index, 0777, true);

        $this->writeTool('apt-cache', implode("\n", [
            '#!/bin/sh',
            "printf '%s\\n' \"apt-cache \$*\" >> {$calls}",
            'case "$1" in',
            "  policy) [ {$policyExitCode} -eq 0 ] || echo 'E: policy broke' >&2; exit {$policyExitCode} ;;",
            '  show)',
            "    file=\"{$index}/\$(printf '%s' \"\$2\" | tr ':=/' '___')\"",
            '    if [ -f "$file.fail" ]; then tail -n +2 "$file.fail" >&2; exit "$(head -n 1 "$file.fail")"; fi',
            '    if [ -f "$file" ]; then cat "$file"; exit 0; fi',
            "    echo 'E: No packages found' >&2; exit 100 ;;",
            'esac',
            'exit 99',
            '',
        ]));
        $this->installed([]);
        $this->installSha256sumAndCp();
    }

    /**
     * 偽の sha256sum・cp を tools に置く(権限に頼らずに失敗を作る。root でも同じ)。どちらも tools を PATH から外して本物に渡す。
     * sha256sum は tools/sha256sum-fail に書いたファイル名を引数に持つときだけ 1 で終わる。
     * cp は tools/cp-corrupt があると、写した先の中身に 1 バイト足す(`cp -- <元> <先のディレクトリ/>` の形の呼び出しを前提にする)。
     */
    private function installSha256sumAndCp(): void
    {
        $fail = escapeshellarg($this->tools.'/sha256sum-fail');
        $corrupt = escapeshellarg($this->tools.'/cp-corrupt');
        $real = 'PATH="${PATH#'.$this->tools.':}"';

        $this->writeTool('sha256sum', implode("\n", [
            '#!/bin/sh',
            "name=\$(cat {$fail} 2>/dev/null)",
            'if [ -n "$name" ]; then',
            '  for argument in "$@"; do',
            '    if [ "${argument##*/}" = "$name" ]; then echo "sha256sum: $name: boom" >&2; exit 1; fi',
            '  done',
            'fi',
            $real,
            'exec sha256sum "$@"',
            '',
        ]));
        $this->writeTool('cp', implode("\n", [
            '#!/bin/sh',
            $real,
            'cp "$@" || exit $?',
            "if [ -f {$corrupt} ]; then printf x >> \"\$3\$(basename \"\$2\")\"; fi",
            '',
        ]));
    }

    protected function failSha256sumFor(string $fileName): void
    {
        file_put_contents($this->tools.'/sha256sum-fail', $fileName);
    }

    protected function corruptCopies(): void
    {
        file_put_contents($this->tools.'/cp-corrupt', '');
    }

    /**
     * apt-cache show <問い合わせ> を、終了コード $exitCode・標準エラー $message で失敗させる。
     */
    protected function failShow(string $query, int $exitCode, string $message): void
    {
        $this->indexRaw($query.'.fail', $exitCode."\n".$message."\n");
    }

    /**
     * 偽の dpkg-query。引数にかかわらず、決めた全件の行(`<名前>:<アーキ>\t<版>\t<状態の略>`)を出して $exitCode で終わる。
     *
     * @param  list<array{string, string, string}>  $rows
     */
    protected function installed(array $rows, int $exitCode = 0): void
    {
        file_put_contents($this->tools.'/dpkg-status', implode('', array_map(
            fn (array $row): string => implode("\t", $row)."\n",
            $rows,
        )));
        $status = escapeshellarg($this->tools.'/dpkg-status');
        $this->writeTool('dpkg-query', "#!/bin/sh\ncat {$status}\nexit {$exitCode}\n");
    }

    /**
     * 名前_版_アーキ.deb(版のエポックの : はファイル名では URL エンコードの小文字)を置き、中身の SHA256 を返す。
     */
    protected function deb(string $directory, string $name, string $version, string $arch, string $body): string
    {
        $escapedVersion = str_replace(':', strtolower(rawurlencode(':')), $version);
        file_put_contents($directory.'/'.$name.'_'.$escapedVersion.'_'.$arch.'.deb', $body);

        return hash('sha256', $body);
    }

    protected function index(string $name, string $arch, string $version, string $sha): void
    {
        $this->indexRaw(
            "{$name}:{$arch}={$version}",
            "Package: {$name}\nArchitecture: {$arch}\nVersion: {$version}\nFilename: pool/{$name}\nSHA256: {$sha}\n",
        );
    }

    /**
     * apt-cache show <問い合わせ> の出力を決める。
     */
    protected function indexRaw(string $query, string $output): void
    {
        file_put_contents($this->tools.'/index/'.strtr($query, [':' => '_', '=' => '_', '/' => '_']), $output);
    }

    /**
     * 想定外の注意の全文(X = 索引の SHA256 と違う .deb の数・Y = 形の外れた項目の数。$tail = 括弧の中)。
     */
    protected function unexpectedWarning(int $mismatch, int $form, string $tail): string
    {
        return "\n::warning title=apt の控え::索引の SHA256 と違う .deb を {$mismatch} 個・形の外れた項目を {$form} 個外した({$tail})";
    }

    /**
     * @return list<string>
     */
    protected function files(string $directory): array
    {
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }
}
