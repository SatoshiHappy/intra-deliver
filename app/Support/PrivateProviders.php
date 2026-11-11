<?php

namespace App\Support;

use Illuminate\Support\ServiceProvider;
use LogicException;
use Throwable;

/**
 * 手元だけのリポジトリ(アプリのルートの ai-private。追跡しない)が登録するプロバイダの一覧を読む(docs の 設計/AI/設定とプロンプト/README.md 11.4 の
 * 2 つの版・ADR 0039 の決定 8)。登録は AppServiceProvider が既定の結びの後に行う。
 */
final class PrivateProviders
{
    /** 一覧のファイル(アプリのルート起点) */
    public const PATH = 'ai-private/providers.php';

    /**
     * ファイル $path が返すプロバイダのクラスの名前の一覧。ファイルが無ければ空(手元のリポジトリが無い配備・公開の写しと CI・docker の image)。
     *
     * @return list<class-string<ServiceProvider>>
     *
     * @throws LogicException ファイルが読めない・一覧を返さない・一覧にプロバイダのクラスの名前でない値があるとき(作りの誤り)
     */
    public static function listedIn(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $listed = require $path;
        } catch (Throwable $exception) {
            throw new LogicException("手元のプロバイダの一覧 {$path} を読めない: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_array($listed) || ! array_is_list($listed)) {
            throw new LogicException("手元のプロバイダの一覧 {$path} がクラスの名前の一覧を返さない");
        }

        return array_map(fn (mixed $name): string => self::providerClass($path, $name), $listed);
    }

    /**
     * @return class-string<ServiceProvider>
     *
     * @throws LogicException
     */
    private static function providerClass(string $path, mixed $name): string
    {
        if (! is_string($name) || ! is_subclass_of($name, ServiceProvider::class)) {
            throw new LogicException("手元のプロバイダの一覧 {$path} に、プロバイダのクラスの名前でない値がある: ".var_export($name, true));
        }

        return $name;
    }
}
