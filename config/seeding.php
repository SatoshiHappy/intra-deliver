<?php

/*
 * 初期データ(database/seeders)の流す日(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1・1.3)。
 * テストが日付を固定できるように設定に置く。null なら Database\Seeders\SeedingDates が流した時の今日にする。
 */

return [
    'date' => env('SEED_DATE'),
];
