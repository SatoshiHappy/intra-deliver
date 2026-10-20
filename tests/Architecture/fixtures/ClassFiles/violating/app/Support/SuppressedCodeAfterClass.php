<?php

namespace App\Support;

/**
 * 抑制の注釈を付けてもファイルの形の規則は消せない(nonIgnorable)
 */
final class SuppressedCodeAfterClass
{
}

// @phpstan-ignore appFile.codeOutsideClass
const SUPPRESSED_LIMIT = 10;
