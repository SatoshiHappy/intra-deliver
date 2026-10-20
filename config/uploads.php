<?php

/*
 * モジュール Upload の上限(配備の設定。正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節・7 節)。
 * 既定は公開デモの値(1 ファイル 95MB・1 回の合計 95MB・合計 10GB)。配備ごとに環境変数で変え、PHP の upload_max_filesize・post_max_size も
 * 同じ変更で合わせる。値は正の整数(バイト)で、そうでなければ読む所が作りの誤りとして止める(上限なしに倒さない)。
 */

return [
    'max_file_bytes' => env('UPLOAD_MAX_FILE_BYTES', 99_614_720),
    'max_request_bytes' => env('UPLOAD_MAX_REQUEST_BYTES', 99_614_720),
    'max_total_bytes' => env('UPLOAD_MAX_TOTAL_BYTES', 10_737_418_240),
];
