<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Env;
use LogicException;

/**
 * 環境変数を置いてからアプリを起動するテストのクラスが使う共通の仕組み。使うクラスは environmentOverrides() で、起動の間だけ置く変数を渡す。
 *
 * 置くのは env() が読む 3 か所($_SERVER・$_ENV・putenv)。作り終えたら(失敗しても)控えた元の値をそのまま戻す(無かったものは消す)。
 *
 * Env のリポジトリは作り直す。Laravel はプロセスに 1 つの Dotenv のリポジトリ(Illuminate\Support\Env)を持ち、その ImmutableWriter は
 * 「既にある変数を上書きしない」が、自分が .env から読んだ変数は記録(loaded)して次の読み込みで上書きする。だから同じプロセスで先に
 * アプリを起動したテストがあると、.env にある変数(例: APP_DEBUG)は記録に入り、ここで置いた値が .env の値に戻される(単体では通り、
 * 通常の順の全体で落ちる)。リポジトリを捨てて新しく作らせると記録が空なので、置いた値が全部「外から定義」になり、.env が上書きしない。
 * phpunit.xml の force(最初の .env の読み込みより前に置く)には頼らない。
 *
 * リポジトリを捨てるだけの公開の口は無い。Env::enablePutenv() は putenv を既定どおり有効にしたままリポジトリを捨てる(disablePutenv() は
 * putenv を切り、Env::extend() はアダプタを足す副作用があるので使わない)。戻した後にもう一度捨てるのは、次のテストのクラスが新しい
 * リポジトリを得るため。戻した値は、外から定義された値(phpunit.xml の <env>・OS の環境変数)か .env が入れたのと同じ値なので、
 * 次の読み込みの結果は変わらない。
 *
 * 依存する vendor の箇所: Env::enablePutenv・Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables(Env::getRepository で .env を読む)・
 * Dotenv\Repository\Adapter\ImmutableWriter::isExternallyDefined。vendor を更新したときはこの 3 つの挙動を確かめる。
 *
 * WithCachedConfig・WithCachedRoutes を使うクラスは止める(テストのクラスをまたいで共有される設定・ルートのキャッシュに置いた値が残るため)。
 */
trait BootsWithEnvironmentOverrides
{
    /**
     * 起動の間だけ置く環境変数(名前 => 値)。
     *
     * @return array<string, string>
     */
    abstract protected function environmentOverrides(): array;

    /**
     * @return Application
     *
     * @throws LogicException 設定・ルートのキャッシュの trait を使っているとき
     */
    public function createApplication()
    {
        $traits = class_uses_recursive(static::class);

        if (isset($traits[WithCachedConfig::class]) || isset($traits[WithCachedRoutes::class])) {
            throw new LogicException('環境変数を置いて起動するテストは、テストをまたぐ設定・ルートのキャッシュ(WithCachedConfig・WithCachedRoutes)を使わない');
        }

        $overrides = $this->environmentOverrides();
        $saved = $this->saveEnvironmentVariables(array_keys($overrides));

        $this->putEnvironmentVariables($overrides);
        Env::enablePutenv();

        try {
            return parent::createApplication();
        } finally {
            $this->restoreEnvironmentVariables($saved);
            Env::enablePutenv();
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>
     */
    private function saveEnvironmentVariables(array $names): array
    {
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = [
                'hadServer' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'hadEnv' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'putenv' => getenv($name),
            ];
        }

        return $saved;
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function putEnvironmentVariables(array $overrides): void
    {
        foreach ($overrides as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    /**
     * @param  array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>  $saved
     */
    private function restoreEnvironmentVariables(array $saved): void
    {
        foreach ($saved as $name => $previous) {
            if ($previous['hadServer']) {
                $_SERVER[$name] = $previous['server'];
            } else {
                unset($_SERVER[$name]);
            }

            if ($previous['hadEnv']) {
                $_ENV[$name] = $previous['env'];
            } else {
                unset($_ENV[$name]);
            }

            putenv($previous['putenv'] === false ? $name : "{$name}={$previous['putenv']}");
        }
    }
}
