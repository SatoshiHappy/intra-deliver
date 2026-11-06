<?php

use App\Models\Master\User;

return [

    /*
    | users の中身は書いたもので置き換わるので driver も書く(ほかのキーはフレームワークの既定と合わさる)。
    | 配線のファイルのうち config の下だけが永続化のクラス名を書ける(docs の 設計/コード構成/README.md 2 節)。
    */
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

];
