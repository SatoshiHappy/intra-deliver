/** 書記素(利用者が 1 字と数える単位)に分ける */
const GRAPHEMES = new Intl.Segmenter('ja', { granularity: 'grapheme' });

/**
 * 名前を、折り返せる前の部分と、最後の 1 字に分ける(「…」を名前の最後の字と同じ行に残し、名前そのものは折り返せるようにする)。
 * 1 字は書記素の単位(異体字セレクタ・結合文字は前の字と離さない)。空の名前は両方が空。
 */
export function splitNameTail(name: string): { head: string; tail: string } {
    const graphemes = Array.from(GRAPHEMES.segment(name), (one) => one.segment);
    const tail = graphemes.pop() ?? '';

    return { head: graphemes.join(''), tail };
}
