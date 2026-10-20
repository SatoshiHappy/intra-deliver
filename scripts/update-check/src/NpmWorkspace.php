<?php

namespace UpdateCheck;

use Throwable;

/**
 * `npm ci` を流すための一時のディレクトリ。package.json・package-lock.json・.npmrc を写し、作業木の node_modules を触らない
 * (ローカルの実行が作業木の node_modules を消して入れ直さない)。使い終わったら中身ごと消す。
 */
final readonly class NpmWorkspace
{
    private const FILES = ['package.json', 'package-lock.json', '.npmrc'];

    private function __construct(public string $path) {}

    /**
     * @param  string  $parent  一時のディレクトリを作る親(本番は sys_get_temp_dir())
     *
     * @throws UnconfirmedException 作れない・写せない
     */
    public static function create(string $root, string $parent): self
    {
        $path = $parent.'/updcheck-npm-'.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700)) {
            throw new UnconfirmedException('npm の一時のディレクトリを作れない');
        }

        $workspace = new self($path);

        foreach (self::FILES as $file) {
            if (is_file($root.'/'.$file) && ! copy($root.'/'.$file, $path.'/'.$file)) {
                $workspace->remove();

                throw new UnconfirmedException("{$file} を一時のディレクトリに写せない");
            }
        }

        return $workspace;
    }

    /**
     * 中身ごと消す。finally から呼ぶので、元の結果・例外を置き換えないよう投げない。
     * 1 項目ずつ失敗を捕まえて続け、消せなかったパスをすべて error_log() に出す(unlink・rmdir は失敗しても例外でなく false を返すので、戻り値も確かめる)。
     */
    public function remove(): void
    {
        try {
            $this->removeTree($this->path);
        } catch (Throwable $exception) {
            error_log("npm の一時のディレクトリを消せなかった: {$this->path}(".$exception::class.': '.$exception->getMessage().')');
        }
    }

    private function removeTree(string $directory): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            error_log("npm の一時のディレクトリの中身を読めない(消せなかった): {$directory}");
        }

        foreach (array_diff($entries ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeTree($path);
            } elseif (! @unlink($path)) {
                error_log("npm の一時のディレクトリの中身を消せなかった: {$path}");
            }
        }

        if (! @rmdir($directory)) {
            error_log("npm の一時のディレクトリを消せなかった: {$directory}");
        }
    }
}
