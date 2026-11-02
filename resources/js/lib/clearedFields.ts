import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import type { ClearedField } from '@/types';

/**
 * 写す時に今は使えない値を空にした欄の知らせ(値を写す 3 つの仕組みで同じ形 = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の
 * 「空にした項目の知らせ」。文は同じ文書の 8 節)。理由は空にした欄のそばに書き、見出しの下には件数だけを出す。
 */

/** 空にした仕組み(理由の文を分ける) */
export type ClearedReason = 'copy' | 'applicationTarget' | 'record';

/** 空にした欄の理由の文 */
export const CLEARED_REASON_TEXTS: Record<ClearedReason, string> = {
    copy: '元の申請の値は、今は使えないため空にしました。',
    applicationTarget: '申請対象の今の値は、今は使えないため空にしました。',
    record: '元のレコードの値は、今は使えないため空にしました。',
};

/** 案件からの複製で引き継ぐ参照制限の参照者を外したときの 1 行(申請の画面に参照制限の欄が無いので、件数に数えない) */
export const VIEWERS_CLEARED_NOTICE =
    '引き継ぐ参照制限の参照者のうち、今は使えない人・組織を外しました。';

/**
 * 空にした欄の印 1 つ。baseline = 空にした直後の欄の値(JSON)。欄に値を入れたら印を消すため、今の値と比べる。
 * 明細のセルは行の key で引く(行を並べ替えても同じ行を指す)。
 */
export type ClearedMark = {
    kind: 'field' | 'cell';
    field: string;
    detail: string | null;
    rowKey: string | null;
    reason: ClearedReason;
    baseline: string;
};

/** 欄の印(親の欄 = コード → 理由、明細のセル = 明細のコード → 行の key → 列のコード → 理由) */
export type FieldMarks = {
    fields: Record<string, ClearedReason>;
    cells: Record<string, Record<string, Record<string, ClearedReason>>>;
};

function draftOf(
    data: RecordFormData,
    mark: Pick<ClearedMark, 'field' | 'detail' | 'rowKey'>,
): RecordFieldDraft {
    if (mark.detail === null) {
        return data.values[mark.field] ?? null;
    }

    const row = (data.details[mark.detail] ?? []).find(
        (candidate) => candidate.key === mark.rowKey,
    );

    return row?.values[mark.field] ?? null;
}

/**
 * サーバーが返した空にした欄に、空にした直後の値を添えた印(参照者 = viewers は欄の印にしない。画面ごとに出し方が違う)。
 * data = 空にした値を当てた直後のフォームの値。
 */
export function clearedMarksOf(
    cleared: ClearedField[],
    reason: ClearedReason,
    data: RecordFormData,
): ClearedMark[] {
    return cleared.flatMap((entry): ClearedMark[] => {
        if (entry.kind === 'viewers' || entry.field === null) {
            return [];
        }

        const detail = entry.kind === 'cell' ? entry.detail : null;
        const rowKey =
            detail === null
                ? null
                : (data.details[detail]?.[entry.row ?? -1]?.key ?? null);
        const position = { field: entry.field, detail, rowKey };

        if (detail !== null && rowKey === null) {
            return [];
        }

        return [
            {
                kind: entry.kind,
                ...position,
                reason,
                baseline: JSON.stringify(draftOf(data, position)),
            },
        ];
    });
}

function keyOf(mark: ClearedMark): string {
    return [mark.detail ?? '', mark.rowKey ?? '', mark.field].join('\u0000');
}

/**
 * 今出す印(後に渡した仕組みの印が同じ欄の前の印に勝つ = 後から写した値の理由。欄の値が空にした直後から変わった欄は出さない)。
 */
export function shownMarksOf(
    groups: ClearedMark[][],
    data: RecordFormData,
): ClearedMark[] {
    const merged = new Map<string, ClearedMark>();

    for (const mark of groups.flat()) {
        merged.set(keyOf(mark), mark);
    }

    return [...merged.values()].filter(
        (mark) => JSON.stringify(draftOf(data, mark)) === mark.baseline,
    );
}

/** 欄とセルへ渡す形 */
export function fieldMarksOf(marks: ClearedMark[]): FieldMarks {
    const result: FieldMarks = { fields: {}, cells: {} };

    for (const mark of marks) {
        if (mark.detail === null || mark.rowKey === null) {
            result.fields[mark.field] = mark.reason;
        } else {
            const rows = (result.cells[mark.detail] ??= {});
            (rows[mark.rowKey] ??= {})[mark.field] = mark.reason;
        }
    }

    return result;
}

/** 見出しの下の件数の注意(印が無ければ null = 出さない) */
export function clearedNoticeOf(count: number): string | null {
    return count === 0
        ? null
        : `今は使えない値を空にした項目が ${count} 件あります。⚠ の付いた欄を入力してください。`;
}

/** 参照制限の参照者を外したか */
export function hasClearedViewers(cleared: ClearedField[]): boolean {
    return cleared.some((entry) => entry.kind === 'viewers');
}

/**
 * 業務データの画面の複製の、参照制限の欄の印(参照者を外していて、参照者の欄が開いた時のまま = 入力していない)。
 */
export function viewersMarkedOf(
    cleared: ClearedField[],
    initialViewers: unknown[],
    currentViewers: unknown[],
): boolean {
    return (
        hasClearedViewers(cleared) &&
        JSON.stringify(currentViewers) === JSON.stringify(initialViewers)
    );
}
