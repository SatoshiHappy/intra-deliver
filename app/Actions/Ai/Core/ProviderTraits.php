<?php

namespace App\Actions\Ai\Core;

/**
 * 接続先の性質(docs の 設計/AI/会話の中核.md 13.6)。接続先ごとの違いを値に閉じ、ループに接続先の名前の分岐を書かない。値は Ai の設定の
 * 接続先ごとの値に置き、繋ぎがその値から作る(設計/AI/実行と費用/接続先と費用.md 5 節)。持つのは SDK が寄せない分だけで、ほかの接続先は
 * 既定の値(どれも持たない)。接続先の応答の形に結び付いた送り直しの規則(設計/AI/実行と費用/README.md 4 節)は、その値を持つ接続先でだけ効く。
 */
final readonly class ProviderTraits
{
    /**
     * @param  bool  $cachesInstructions  指示と道具の定義をキャッシュの指定で送るか
     * @param  bool  $cachesHistory  履歴に接続先のキャッシュの印を付けるか(付け方 = 設計/AI/README.md の未決)
     * @param  float  $estimateFactor  送る量の見積もりの係数(接続先とモデルの組の値 = 設計/AI/実行と費用/接続先と費用.md 5 節。
     *                                 両方の版で使う = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)
     * @param  string|null  $overloadedMarker  過負荷を誤りの文で見分ける句(続けて過負荷の数え方と、題の送信で送り直さないことも効く)
     * @param  string|null  $contextOverflowPattern  入力と出力の上限の和の超えを読み解く正規表現(入力・上限・読める量の 3 つの数を
     *                                               名前つきの組 input・limit・window で取る。あれば出力の上限を縮めて送り直す)
     * @param  string|null  $retryHeader  送り直しを指す応答の見出しの名前(見ない接続先は null)
     * @param  list<string>  $tooLongPatterns  長すぎるの断りを見分ける正規表現(既定 = 見分け無し = 設計/AI/エージェントの実行/状態の移り方.md
     *                                         9.11 の S21。送った量と上限を名前つきの組 actual・limit で取れれば取る)
     * @param  string|null  $contextManagementModels  思考の文脈の管理を受けるモデルの ID の正規表現(持たない接続先は null = 設計/AI/会話の中核.md 13.2)
     */
    public function __construct(
        public bool $cachesInstructions = false,
        public bool $cachesHistory = false,
        public float $estimateFactor = 1.0,
        public ?string $overloadedMarker = null,
        public ?string $contextOverflowPattern = null,
        public ?string $retryHeader = null,
        public array $tooLongPatterns = [],
        public ?string $contextManagementModels = null,
    ) {}
}
