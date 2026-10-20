<?php

// web の文書 = notes.md と ../notes.md(同じファイルのディレクトリか web の根から)
// docs の根から = 設計/検査.md 6 節(見出しの `## 6.`)・`設計/検査.md` 2.1(`## 2.1` の形)・設計/検査.md 3.1.2(3 段の節)
// 引用 = 設計/検査.md の「検査の作り方」
// ディレクトリ = 設計/画面/ 1 節
// 同じファイルの前の参照からの相対 = 一覧.md(設計/画面/ の下。末尾だけなら 仕様/ にもあり決まらない)
// 参照でない語 = https://example.com/a.md・@scope/b.md・~/c.md・/tmp/d.md・README.markdown・a.md5
// 台帳⑧ R1 の pattern に載った語 = 出力.md、変数の展開 = $OUT/a.md、ディレクトリと番号の間に空白が無い = HTTP/1.1
// 「の」を挟んだ節 = 設計/検査.md の 6 節、節の後ろの引用 = 設計/検査.md 6 節「検査の作り方」
// 「の」つきの引用 = 設計/検査.md 2.1 の「検査の作り方」・設計/検査.md 6 節の「検査の作り方」
// 前の参照(設計/画面/README.md)の後ろの名前だけの参照 = README.md 1 節(web と docs の根の README.md でなく、前の参照の辺り)
// docs の文脈で決まらない web の文書 = .ai/rules/general.md・AGENTS.md
// docs の根の直下 = 設計/画面/README.md の後ろの ../docs/README.md の「文書の木」(近い方の 設計/画面/README.md に無い引用)
// ../docs/ からの docs の根からのパス = ../docs/設計/検査.md 6 節
