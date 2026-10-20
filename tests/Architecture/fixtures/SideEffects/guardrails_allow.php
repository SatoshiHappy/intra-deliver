<?php

/*
 * 副作用の規則の自己証明の fixture の台帳②。HTTP・AI の呼び出し・キューへの投入をモジュール Alpha の業務処理に限る(層の中のモジュールで書いた場所の例)。
 */

return [
    'capabilities' => [
        'dbWrite' => ['App\\Actions'],
        'queue' => ['App\\Actions\\Alpha'],
        'notification' => ['App\\Actions'],
        'fileStorage' => ['App\\Actions'],
        'ai' => ['App\\Actions\\Alpha'],
        'http' => ['App\\Actions\\Alpha'],
        'process' => ['App\\Actions'],
    ],
];
