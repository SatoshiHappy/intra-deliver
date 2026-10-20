<?php

// 参照の実在の照合の fixture の台帳⑧(R1 の pattern の値と完全に一致する語は参照の語から外す)
return [
    'exclusions' => [
        'R1' => [
            ['pattern' => '出力.md', 'reason' => '道具が書く出力のファイル名'],
        ],
    ],
];
