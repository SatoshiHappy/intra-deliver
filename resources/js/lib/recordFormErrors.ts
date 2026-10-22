import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import { drawnDetailsFields, valueFields } from '@/lib/recordForm';
import type { RecordModuleDefinition } from '@/types';

/**
 * 業務の登録・編集のフォームの誤りのキー(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の「エラー」)。欄の下に出すキーと、
 * 複数の参照の欄の添字のキー(`<欄のキー>.<添字>`)の扱い。
 */

/** 検証のエラー(キー → 文言) */
type Errors = Record<string, string | undefined>;

/** 複数の参照の欄の添字の誤り(チップの印と、チップを外したときの消し方) */
export type ReferenceItemErrors = {
    /** 誤りのある相手の添字(昇順) */
    invalidIndexes: number[];
    /** チップを外した。添字がずれて印が別の相手に移らないよう、その欄の添字のキーの誤りを消す */
    onRemoved: () => void;
};

/** 業務の欄の値の誤りのキーか(`values.`・`details.` の下)。封筒そのもの(`values`・`details`)とほかは、フォームの上に出す(申請の画面と同じ分け方) */
export function isRecordFieldKey(key: string): boolean {
    return key.startsWith('values.') || key.startsWith('details.');
}

/**
 * 誤りのキーが指す業務の欄の項目のコード(`values.<コード>` と、複数の参照の欄の `values.<コード>.<添字>`。明細の誤り `details.…` は
 * 見出しの外の明細の表にあるので含めない = レコードの共通機能/詳細の画面/見出し.md の 3 節)。重複は 1 つにする。
 */
export function errorFieldCodes(errors: Errors): string[] {
    const codes = Object.keys(errors)
        .filter((key) => errors[key] !== undefined && key.startsWith('values.'))
        .map((key) => key.split('.')[1] ?? '')
        .filter((code) => code !== '');

    return [...new Set(codes)];
}

/**
 * 欄のキーと、欄の値が相手の並びなら添字のキー(複数の参照の欄は添字の誤りも欄の下に出す)。
 */
function keysOf(key: string, draft: RecordFieldDraft | undefined): string[] {
    return [
        key,
        ...(Array.isArray(draft)
            ? draft.map((_, index) => `${key}.${index}`)
            : []),
    ];
}

/** 今の定義と行の数・相手の数で欄の下に出すキー。業務の欄の誤りのうちこれ以外は、RecordFields の先頭に回す */
export function shownRecordKeys(
    module: RecordModuleDefinition,
    data: RecordFormData,
): string[] {
    const keys = valueFields(module).flatMap((field) =>
        keysOf(`values.${field.code}`, data.values[field.code]),
    );

    for (const field of drawnDetailsFields(module)) {
        const columns = valueFields(field.childModule);

        keys.push(`details.${field.code}`);
        (data.details[field.code] ?? []).forEach((row, index) => {
            const rowKey = `details.${field.code}.${index}`;
            keys.push(
                `${rowKey}.id`,
                ...columns.flatMap((column) =>
                    keysOf(
                        `${rowKey}.values.${column.code}`,
                        row.values[column.code],
                    ),
                ),
            );
        });
    }

    return keys;
}

/**
 * 欄のキーの下の、誤りのある添字(`<key>.<添字>` のキー。昇順)。
 */
function itemErrorIndexes(errors: Errors, key: string): number[] {
    const prefix = `${key}.`;

    return Object.keys(errors)
        .filter((errorKey) => errors[errorKey] !== undefined)
        .filter((errorKey) => errorKey.startsWith(prefix))
        .map((errorKey) => errorKey.slice(prefix.length))
        .filter((index) => /^[0-9]+$/.test(index))
        .map(Number)
        .sort((a, b) => a - b);
}

/**
 * 欄の下に出す誤りの文言(欄のキーの誤り、無ければ最初の添字の誤り)。
 */
export function fieldErrorOf(errors: Errors, key: string): string | undefined {
    const [first] = itemErrorIndexes(errors, key);

    return (
        errors[key] ??
        (first === undefined ? undefined : errors[`${key}.${first}`])
    );
}

/**
 * 複数の参照の欄の添字の誤り。clear は prefix で始まるキーの誤りを消す(使い手のフォームが誤りを持つので)。
 */
export function referenceItemErrors(
    errors: Errors,
    key: string,
    clear: (prefix: string) => void,
): ReferenceItemErrors {
    return {
        invalidIndexes: itemErrorIndexes(errors, key),
        onRemoved: () => clear(`${key}.`),
    };
}
