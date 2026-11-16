<?php

namespace App\Enums\Ai;

/**
 * 道具の引数のうち、ユーザー・組織の相手を名前か候補の id で書ける物の形(docs の 設計/AI/道具と権限/README.md 10.1 の形の外の検証・
 * 10.2 のユーザー・組織の名前)。③ の共通の部品が、名前の物を引いて、この形の id に直してから道具のクラスに渡す。
 */
enum AiToolPartyForm: string
{
    /** 一覧の条件の形(項目のコード → 条件の値。相手の id は <id>・user:<id>・department:<id>) */
    case Condition = 'condition';

    /** 入れ物の入力の形(項目のコード → 書く値) */
    case Input = 'input';
}
