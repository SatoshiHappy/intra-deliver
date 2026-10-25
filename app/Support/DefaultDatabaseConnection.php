<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * 接続の設定が既定の DB 接続を指すかの比べ方の 1 か所(キューの確かめ {@see DefaultDatabaseQueue} と毎日の初期化の置き場の確かめが使う)。
 * 空の値(null・空文字 = 環境変数が未設定か空)は既定の接続とみなす(Laravel の DatabaseManager::connection は空の名前を既定の接続に読み替える)。
 */
final class DefaultDatabaseConnection
{
    public static function is(mixed $connection): bool
    {
        return in_array($connection, [null, ''], true) || $connection === Config::get('database.default');
    }
}
