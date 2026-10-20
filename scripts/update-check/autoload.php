<?php

/*
 * 更新チェックのクラスの読み込み。vendor を要らなくする(runner では composer install をせずに動かす。実行本体は run.php)。
 * UpdateCheck\Foo → src/Foo.php。
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'UpdateCheck\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
