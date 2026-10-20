<?php

namespace UpdateCheck;

/**
 * 調べる項目 1 つ(#1〜#8)。
 */
interface Check
{
    public function run(): Section;
}
