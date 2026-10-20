<?php

namespace Tests\Architecture\Engine\PhpStan;

use Tests\Architecture\Engine\Paths;

/**
 * 解析するファイルの、アプリのルートからの相対パス(汎用。ルートは phpstan の設定から受ける)。
 *
 * ファイルの役割(配線のファイルか、クラスを置くディレクトリのファイルか)は、名前空間でなくパスで決まるので、
 * パスで判定する規則が使う。ルートを複数受けるのは、自己証明の fixture 用の設定が fixture のディレクトリをルートに差し替えるため
 * (fixture のディレクトリをアプリのルートに見立てる)。どのルートの下にも無いファイルは判定しない。
 */
final readonly class CodeRoots
{
    /** @var list<string> */
    private array $roots;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点)。この外のファイルが配線のファイル
     */
    public function __construct(array $roots, private array $classDirectories)
    {
        $normalized = array_map(Paths::normalize(...), $roots);
        // 入れ子のルートは深い方を先に見る
        usort($normalized, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $this->roots = $normalized;
    }

    /**
     * ファイル $file(絶対パス)の、それを含むルートからの相対パス。どのルートの下にも無ければ null。
     */
    public function relative(string $file): ?string
    {
        $path = Paths::normalize($file);

        foreach ($this->roots as $root) {
            if (str_starts_with($path, $root.'/')) {
                return substr($path, strlen($root) + 1);
            }
        }

        return null;
    }

    /**
     * ファイル $file が、ルートの下のディレクトリ $directories のどれかの下にあるか。
     *
     * @param  list<string>  $directories
     */
    public function isUnder(string $file, array $directories): bool
    {
        $relative = $this->relative($file);

        if ($relative === null) {
            return false;
        }

        foreach ($directories as $directory) {
            if (str_starts_with($relative, Paths::normalize($directory).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * ファイル $file が配線のファイル(ルートの下で、クラスを置くディレクトリの外にある)か。
     */
    public function isWiring(string $file): bool
    {
        return $this->relative($file) !== null && ! $this->isUnder($file, $this->classDirectories);
    }
}
