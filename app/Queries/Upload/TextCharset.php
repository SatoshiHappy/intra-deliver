<?php

namespace App\Queries\Upload;

use RuntimeException;

/**
 * テキスト・Markdown・CSV のプレビューの文字コード(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 4 節の
 * 「テキストのプレビュー」)。中身の全体を決まった大きさずつ読んで判定し、全体を一度に記憶に読まない。
 * 値は WHATWG Encoding のラベル(UTF-8 か Shift_JIS)。
 */
class TextCharset
{
    /** 判定で一度に読む大きさ(1MiB) */
    public const CHUNK_BYTES = 1_048_576;

    public const UTF8 = 'UTF-8';

    public const SHIFT_JIS = 'Shift_JIS';

    /** UTF-8 の BOM(0xEF は CP932 の 1 バイト目に無く、BOM で始まる中身の判定は必ず UTF-8 になるので、中身を読まない) */
    private const BOM = "\xEF\xBB\xBF";

    /** 区切りの境で切れうる文字の、切れた側の最大の長さ(UTF-8 は 4 バイトの文字の 3 バイト、CP932 は 2 バイトの文字の 1 バイト) */
    private const UTF8_CARRY = 3;

    private const CP932_CARRY = 1;

    /**
     * 中身から判定したラベル(判定の順はクラスの注釈の docs の節)。
     *
     * @param  resource  $stream  先頭へ巻き戻せるストリーム(閉じるのは呼び手)
     */
    public function labelOf($stream): string
    {
        rewind($stream);

        if ($this->read($stream, strlen(self::BOM)) === self::BOM) {
            return self::UTF8;
        }

        if ($this->readsAs($stream, 'UTF-8', self::UTF8_CARRY)) {
            return self::UTF8;
        }

        return $this->readsAs($stream, 'CP932', self::CP932_CARRY) ? self::SHIFT_JIS : self::UTF8;
    }

    /**
     * 先頭から全体を区切りごとに読んで、その文字コードとして正しいか。
     *
     * @param  resource  $stream
     */
    private function readsAs($stream, string $encoding, int $maxCarry): bool
    {
        rewind($stream);
        $carry = '';

        while (! feof($stream)) {
            $carry = $this->unfinished($carry.$this->read($stream, self::CHUNK_BYTES), $encoding, $maxCarry);

            if ($carry === null) {
                return false;
            }
        }

        // 最後に文字の途中で終わっていれば正しくない
        return $carry === '';
    }

    /**
     * 末尾の切れた文字を除けば正しいときの、その末尾(次の区切りへ持ち越す)。除いても正しくなければ null。
     * 文字の境は左から決まるので、末尾から数バイトを除いて正しければ、除いた分が次の区切りと合わせて読む部分になる。
     */
    private function unfinished(string $data, string $encoding, int $maxCarry): ?string
    {
        $length = strlen($data);

        for ($carry = 0; $carry <= min($maxCarry, $length); $carry++) {
            if (mb_check_encoding(substr($data, 0, $length - $carry), $encoding)) {
                return substr($data, $length - $carry);
            }
        }

        return null;
    }

    /**
     * @param  resource  $stream
     * @param  positive-int  $bytes
     *
     * @throws RuntimeException 読めないとき
     */
    private function read($stream, int $bytes): string
    {
        $chunk = fread($stream, $bytes);

        return $chunk === false ? throw new RuntimeException('テキストの実体を読めない') : $chunk;
    }
}
