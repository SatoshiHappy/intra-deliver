import { describe, expect, it } from 'vite-plus/test';
import { splitNameTail } from './nameTail';

describe('splitNameTail(名前の最後の 1 文字だけを「…」と離さない)', () => {
    it('最後の 1 文字とそれより前に分ける', () => {
        expect(splitNameTail('第一営業部')).toEqual({
            head: '第一営業',
            tail: '部',
        });
    });

    it('1 文字の名前は前の部分が空、空の名前は両方が空', () => {
        expect(splitNameTail('部')).toEqual({ head: '', tail: '部' });
        expect(splitNameTail('')).toEqual({ head: '', tail: '' });
    });

    it('2 つのコードの字(𠮷)も 1 文字として最後に残す', () => {
        expect(splitNameTail('𠮷野家')).toEqual({ head: '𠮷野', tail: '家' });
        expect(splitNameTail('野家𠮷')).toEqual({ head: '野家', tail: '𠮷' });
    });

    it('異体字セレクタ・結合文字で終わる名前は、その字を前の字と離さない', () => {
        expect(splitNameTail('葛\u{E0100}')).toEqual({
            head: '',
            tail: '葛\u{E0100}',
        });
        expect(splitNameTail('営業か\u3099')).toEqual({
            head: '営業',
            tail: 'か\u3099',
        });
    });
});
