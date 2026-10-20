<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * git の版からファイルを読む(汎用)。git が失敗したときは例外にする(読めないことを「ファイルが無い」にしない)。
 */
final class GitRevision
{
    /**
     * 作業木 $root の HEAD と枝 $branch の分かれ目の版(`git merge-base HEAD <枝>`)。枝の上で直に作業しているときは HEAD。
     *
     * @throws UnexpectedValueException
     */
    public static function mergeBase(string $root, string $branch): string
    {
        return trim(self::git($root, ['merge-base', 'HEAD', $branch]));
    }

    /**
     * 版 $revision のファイル $file(根 $root からの相対パス)の中身。その版にファイルが無ければ null。
     *
     * @throws UnexpectedValueException
     */
    public static function fileAt(string $root, string $revision, string $file): ?string
    {
        if (trim(self::git($root, ['ls-tree', '--name-only', $revision, '--', $file])) === '') {
            return null;
        }

        return self::git($root, ['show', $revision.':./'.$file]);
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws UnexpectedValueException
     */
    private static function git(string $root, array $arguments): string
    {
        $result = Process::run(['git', ...$arguments], $root);

        if ($result->exitCode !== 0) {
            throw new UnexpectedValueException("git が失敗した\n".$result->describe());
        }

        return $result->stdout;
    }
}
