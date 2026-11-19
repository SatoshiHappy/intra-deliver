<?php

namespace App\Enums\Ai;

/**
 * 道具の引数のうち、ユーザー・組織の相手を名前か候補の id で書ける物の形(docs の 設計/AI/道具と権限/README.md 10.1 の相手を含む引数・
 * 10.2 のユーザー・組織の名前)。③ の共通の部品が、名前の物を引いて、この形の id に直してから道具のクラスに渡す。入れ物の入力の形は、
 * 登録・更新の入力の 3 つの部分(項目の値・明細の行・参照制限)ごとに分ける(③ がどの引数をどう読むかを、引数の鍵の名前に頼らずに知るため)。
 */
enum AiToolPartyForm: string
{
    /** 一覧の条件の形(項目のコード → 条件の値。相手の id は <id>・user:<id>・department:<id>) */
    case Condition = 'condition';

    /** 入れ物の入力の形の項目の値(項目のコード → 書く値。相手の id は <id>・{"type": …, "id": <id>}) */
    case Input = 'input';

    /** 入れ物の入力の形の明細の行(明細の項目のコード → 行 {id, values} の列。行の値は Input と同じ形) */
    case InputDetails = 'input_details';

    /** 入れ物の入力の形の参照制限(restricted・viewers。見せる人は {"type": …, "id": <id>} の列) */
    case InputRowAccess = 'input_row_access';
}
