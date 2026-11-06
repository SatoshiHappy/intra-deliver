import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import type { ClearedField } from '@/types';

/**
 * 写す時に今は使えない値を空にした欄の知らせ(値を写す 3 つの仕組みで同じ形 = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の
 * 「空にした項目の知らせ」。印が消えるかは同じ節の表 1、出すか・数えるかは表 2。文は同じ文書の 8 節)。
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
 * 空にした欄の印 1 つ。baseline = 空にした直後の値(JSON)、gone = 消えた(表 1。一度消えたら出し直さない)。
 * 明細のセルは行の key で引く(行を並べ替えても同じ行を指す)。参照者(viewers)は業務データの画面の複製だけが持つ。
 */
export type ClearedMark = {
    kind: 'field' | 'cell' | 'viewers';
    field: string | null;
    detail: string | null;
    rowKey: string | null;
    reason: ClearedReason;
    baseline: string;
    gone: boolean;
};

/** 印を比べる今の値(viewers = 参照制限の参照者の欄。欄の無い申請の画面は持たない) */
export type ClearedValues = {
    record: RecordFormData;
    viewers?: unknown[];
};

/** 欄の印(親の欄 = コード → 理由、明細のセル = 明細のコード → 行の key → 列のコード → 理由、参照制限の欄の理由) */
export type FieldMarks = {
    fields: Record<string, ClearedReason>;
    cells: Record<string, Record<string, Record<string, ClearedReason>>>;
    viewers?: ClearedReason;
};

type Position = Pick<ClearedMark, 'kind' | 'field' | 'detail' | 'rowKey'>;

/** 印の位置の今の値(明細の行が無くなったら undefined) */
function valueOf(
    current: ClearedValues,
    mark: Position,
): RecordFieldDraft | unknown[] | undefined {
    if (mark.kind === 'viewers') {
        return current.viewers ?? [];
    }

    if (mark.kind === 'field') {
        return current.record.values[mark.field ?? ''] ?? null;
    }

    const row = (current.record.details[mark.detail ?? ''] ?? []).find(
        (candidate) => candidate.key === mark.rowKey,
    );

    return row === undefined
        ? undefined
        : (row.values[mark.field ?? ''] ?? null);
}

/** サーバーの 1 件を印の位置にする(明細の行の添字は行の key に。行が無ければ null = 印にしない) */
function positionOf(
    entry: ClearedField,
    current: ClearedValues,
): Position | null {
    if (entry.kind === 'viewers') {
        return current.viewers === undefined
            ? null
            : { kind: 'viewers', field: null, detail: null, rowKey: null };
    }

    if (entry.kind === 'field') {
        return {
            kind: 'field',
            field: entry.field,
            detail: null,
            rowKey: null,
        };
    }

    const rowKey =
        current.record.details[entry.detail ?? '']?.[entry.row ?? -1]?.key;

    return rowKey === undefined
        ? null
        : { kind: 'cell', field: entry.field, detail: entry.detail, rowKey };
}

/**
 * サーバーが返した空にした欄に、空にした直後の値を添えた印(消えていない で始まる)。current = 空にした値を当てた直後の値。
 * 参照者は参照者の欄がある画面(current.viewers がある)だけ印にする(申請の画面は見出しの下の 1 行 = 表 2 の最後の行)。
 */
export function clearedMarksOf(
    cleared: ClearedField[],
    reason: ClearedReason,
    current: ClearedValues,
): ClearedMark[] {
    return cleared.flatMap((entry): ClearedMark[] => {
        const position = positionOf(entry, current);

        return position === null
            ? []
            : [
                  {
                      ...position,
                      reason,
                      baseline: JSON.stringify(valueOf(current, position)),
                      gone: false,
                  },
              ];
    });
}

/**
 * 今の値で消えた印を決める(画面.md の 5 節の表 1)。何も変わらなければ同じ配列を返す(描画の中で状態を更新し続けないため)。
 */
export function settledMarksOf(
    marks: ClearedMark[],
    current: ClearedValues,
): ClearedMark[] {
    let changed = false;
    const next = marks.map((mark) => {
        if (mark.gone) {
            return mark;
        }

        const value = valueOf(current, mark);

        if (value !== undefined && JSON.stringify(value) === mark.baseline) {
            return mark;
        }

        changed = true;

        return { ...mark, gone: true };
    });

    return changed ? next : marks;
}

/** 印の組と、組を作った元・比べた値(画面ごとの状態) */
export type ClearedState = {
    sources: ClearedMark[][];
    current: ClearedValues;
    groups: ClearedMark[][];
};

/** 開いた時・戻った時の状態(画面.md の 5 節の表 1 の初めの 1 回) */
export function clearedStateOf(
    sources: ClearedMark[][],
    current: ClearedValues,
): ClearedState {
    return {
        sources,
        current,
        groups: sources.map((marks) => settledMarksOf(marks, current)),
    };
}

/**
 * 次の状態(画面.md の 5 節の表 1。組の元の配列が替わったら、その組は写し直した新しい印)。何も変わらなければ同じ状態を返す
 * (描画の中で状態を更新し続けないため)。
 */
export function nextClearedStateOf(
    state: ClearedState,
    sources: ClearedMark[][],
    current: ClearedValues,
): ClearedState {
    const replaced = sources.some((marks, i) => marks !== state.sources[i]);
    const changed =
        current.record !== state.current.record ||
        current.viewers !== state.current.viewers;

    if (!replaced && !changed) {
        return state;
    }

    const groups = sources.map((marks, i) =>
        marks === state.sources[i] ? state.groups[i] : marks,
    );

    return {
        sources,
        current,
        groups: changed
            ? groups.map((marks) => settledMarksOf(marks, current))
            : groups,
    };
}

function keyOf(mark: ClearedMark): string {
    return [mark.kind, mark.detail ?? '', mark.rowKey ?? '', mark.field].join(
        '\u0000',
    );
}

/**
 * 今出す印(画面.md の 5 節の表 2。件数 = 返した印の数)。viewersShown = 参照制限のチェックが付いているか。
 */
export function shownMarksOf(
    groups: ClearedMark[][],
    viewersShown = false,
): ClearedMark[] {
    const merged = new Map<string, ClearedMark>();

    for (const mark of groups.flat()) {
        if (!mark.gone && (mark.kind !== 'viewers' || viewersShown)) {
            merged.set(keyOf(mark), mark);
        }
    }

    return [...merged.values()];
}

/** 欄とセルへ渡す形 */
export function fieldMarksOf(marks: ClearedMark[]): FieldMarks {
    const result: FieldMarks = { fields: {}, cells: {} };

    for (const mark of marks) {
        if (mark.kind === 'viewers') {
            result.viewers = mark.reason;
        } else if (mark.kind === 'field') {
            result.fields[mark.field ?? ''] = mark.reason;
        } else {
            const rows = (result.cells[mark.detail ?? ''] ??= {});
            (rows[mark.rowKey ?? ''] ??= {})[mark.field ?? ''] = mark.reason;
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
