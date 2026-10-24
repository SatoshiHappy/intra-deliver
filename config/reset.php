<?php

/*
 * 公開の配備の毎日の初期化(docs の 設計/公開の配備/毎日の初期化.md)。秒の値は手順 0〜4 の待ちの長さ。
 */
return [

    // 初期化と見張りを動かすか(既定はオフ。true のときだけオン = 5 節)
    'enabled' => env('DAILY_RESET_ENABLED', false),

    // その日の始まり(Asia/Tokyo の時 = 2 節)
    'day_start_hour' => 4,

    // 初期化の鎖錠(DB のセッションの鎖錠)の鍵。共通マスタ 4,100,000,001・業務モジュール 4,200,000,000,000,000〜・AI の費用
    // 4,300,000,000,000,000 と重ならない値
    'lock_key' => 4_400_000_000_000_000,

    // 鎖錠が取れないときに待つ秒(1 秒ごとに試す。毎分の確かめと見張りが一瞬持つのに当たらないため = 2 節の手順 0)
    'lock_wait_seconds' => 10,

    // キューを止めた後、worker が止まりに気づくまで置く秒(queue:work の待ちの間隔 3 秒 + 余白 = 手順 2)
    'worker_notice_seconds' => 10,

    // 処理中のジョブが無くなるのを確かめる間隔(秒。手順 2)
    'job_poll_seconds' => 5,

    // キューの停止の期限に足す余白(秒。手順 1)
    'pause_margin_seconds' => 600,

    // メンテナンスの状態にした後に要求を待つ秒(1 要求の時間の上限 max_execution_time 30 秒 + 余白 = 手順 3)
    'request_wait_seconds' => 45,

    // 消すファイルの置き場(config/filesystems.php の disks の名前。app-storage の全部 = 1 節)
    'disk' => 'app-storage',

    // 予定の回の出力の行き先(コンテナのログ = PID 1 の標準エラー。5 節)
    'schedule_output' => '/proc/1/fd/2',

];
