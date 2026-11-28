<?php

/*
 * Laravel Boost の一般論のうち、このプロジェクトの決定と食い違う節を外す(一部の行だけ直すものは .ai/guidelines/ の上書き)。
 * Laravel の設定は上の階層のキーごとに既定と合わさるので、ここに書いた 'guidelines' は Boost の既定の 'guidelines' を丸ごと置き換える。
 */
return [

    'guidelines' => [
        // deployments = Laravel Cloud の案内(配備は docs の 設計/公開の配備/README.md)。
        // pint/core = バイナリを直接呼ぶ案内(フォーマットとチェックは .ai/guidelines/intra-deliver.md の composer の script)
        'exclude' => ['deployments', 'pint/core'],
    ],
];
