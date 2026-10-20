<?php

namespace App\Enums\Menu;

/**
 * メニューの HTTP メソッド(menus.method。本家の method。docs の 設計/メニュー/表.md 1.4。本家「指定できる値は、get と post です。」)。
 */
enum MenuMethod: string
{
    case Get = 'get';
    case Post = 'post';
}
