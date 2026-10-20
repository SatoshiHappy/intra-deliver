<?php

namespace App\Enums\Master;

/**
 * 性別(user_terms.sex)。値は本家のインポート形式の性別の値(0:男性 1:女性 2:その他 9:回答しない)。
 */
enum Sex: string
{
    case Male = '0';
    case Female = '1';
    case Other = '2';
    case NotAnswered = '9';
}
