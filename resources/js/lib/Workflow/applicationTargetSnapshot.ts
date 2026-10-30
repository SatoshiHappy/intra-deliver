import {
    createFormData,
    drawnDetailsFields,
    editFormData,
    valueFields,
} from '@/lib/recordForm';
import type {
    RecordFieldDraft,
    RecordFormData,
    RecordRowDraft,
} from '@/lib/recordForm';
import { payloadValue } from '@/lib/recordPayload';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordFieldValue,
    RecordModuleDefinition,
} from '@/types';
import { shownValueOf } from './applicationTargetShown';

/**
 * 申請対象の値の写し(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.2 節)。
 * 「写す値と違う入力済みの値」の判定と、写した後のフォームの値。申請対象の項目そのものは比べない(元のレコードを入れる項目なので)。
 */

/** 明細の 1 行の表示用の値(明細の項目のコード → 値) */
export type ShownRow = Record<string, RecordFieldValue>;

/** 確かめのダイアログの 1 行(写すと変わる項目。filled = 今の値が入力済み) */
export type ApplicationTargetChange =
    | {
          kind: 'value';
          field: RecordFieldDefinition;
          current: RecordFieldValue;
          next: RecordFieldValue;
          filled: boolean;
      }
    | {
          kind: 'details';
          field: RecordFieldDefinition;
          child: RecordModuleDefinition;
          current: ShownRow[];
          next: ShownRow[];
          filled: boolean;
      };

/** 比べる値(送る値の形。参照は id、選択はコード、日時は時差つき。空は null・偽) */
function comparableOf(
    field: RecordFieldDefinition,
    draft: RecordFieldDraft,
): string {
    return JSON.stringify(payloadValue(field, draft));
}

/**
 * 入力済みか(判定の定義 = docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.2 節。既定値は createFormData と同じ)。
 */
function isFilled(
    field: RecordFieldDefinition,
    draft: RecordFieldDraft,
    defaultDraft: RecordFieldDraft,
) {
    const value = payloadValue(field, draft);

    return (
        (field.type === 'boolean' || value !== null) &&
        comparableOf(field, draft) !== comparableOf(field, defaultDraft)
    );
}

/** 明細の行を比べる(行の数と、各行の値。行どうしの突き合わせはしないので並びの位置で比べる) */
function rowsDiffer(
    child: RecordModuleDefinition,
    now: RecordRowDraft[],
    next: RecordRowDraft[],
): boolean {
    const fields = valueFields(child);

    return (
        now.length !== next.length ||
        now.some((row, index) =>
            fields.some(
                (field) =>
                    comparableOf(field, row.values[field.code] ?? null) !==
                    comparableOf(
                        field,
                        next[index]?.values[field.code] ?? null,
                    ),
            ),
        )
    );
}

/** 通常・参照の項目の変わるもの(写しの値に無い項目・明細・申請対象の項目は見ない) */
function valueChangesOf(
    module: RecordModuleDefinition,
    targetField: string | null,
    current: RecordFormData,
    snapshot: ApplicationTargetSnapshot,
    snapshotForm: RecordFormData,
): ApplicationTargetChange[] {
    const defaults = createFormData(module).values;

    return valueFields(module).flatMap((field) => {
        const code = field.code;

        if (code === targetField || !(code in snapshot.values)) {
            return [];
        }

        const now = current.values[code] ?? null;

        return comparableOf(field, now) ===
            comparableOf(field, snapshotForm.values[code] ?? null)
            ? []
            : [
                  {
                      kind: 'value',
                      field,
                      current: shownValueOf(field, now),
                      next: snapshot.values[code] ?? null,
                      filled: isFilled(field, now, defaults[code] ?? null),
                  },
              ];
    });
}

/** 明細の項目の変わるもの(写しの値に無い明細は見ない) */
function detailChangesOf(
    module: RecordModuleDefinition,
    current: RecordFormData,
    snapshot: ApplicationTargetSnapshot,
    snapshotForm: RecordFormData,
): ApplicationTargetChange[] {
    return drawnDetailsFields(module).flatMap((field) => {
        const code = field.code;
        const now = current.details[code] ?? [];

        if (
            !(code in snapshot.details) ||
            !rowsDiffer(
                field.childModule,
                now,
                snapshotForm.details[code] ?? [],
            )
        ) {
            return [];
        }

        const fields = valueFields(field.childModule);

        return [
            {
                kind: 'details',
                field,
                child: field.childModule,
                current: now.map((row) =>
                    Object.fromEntries(
                        fields.map((child) => [
                            child.code,
                            shownValueOf(child, row.values[child.code] ?? null),
                        ]),
                    ),
                ),
                next: (snapshot.details[code] ?? []).map((row) => row.values),
                filled: now.length > 0,
            },
        ];
    });
}

/**
 * 写すと値が変わる項目(確かめのダイアログの行。変わる項目が無ければ空)。
 */
export function applicationTargetChangesOf(
    module: RecordModuleDefinition,
    targetField: string | null,
    current: RecordFormData,
    snapshot: ApplicationTargetSnapshot,
): ApplicationTargetChange[] {
    const snapshotForm = editFormData(module, snapshot);

    return [
        ...valueChangesOf(module, targetField, current, snapshot, snapshotForm),
        ...detailChangesOf(module, current, snapshot, snapshotForm),
    ];
}

/**
 * 確かめてから写すか(写す値と違う入力済みの値がある = 変わる項目のどれかが今は入力済み)。
 */
export function needsConfirm(changes: ApplicationTargetChange[]): boolean {
    return changes.some((change) => change.filled);
}

/**
 * 写した後のフォームの値(写しの値にある項目だけを置き換える)。
 */
export function snapshotRecordOf(
    module: RecordModuleDefinition,
    current: RecordFormData,
    snapshot: ApplicationTargetSnapshot,
): RecordFormData {
    const snapshotForm = editFormData(module, snapshot);
    const only = <T>(from: Record<string, T>, keys: string[]) =>
        Object.fromEntries(
            keys.filter((key) => key in from).map((key) => [key, from[key]]),
        );

    return {
        values: {
            ...current.values,
            ...only(snapshotForm.values, Object.keys(snapshot.values)),
        },
        details: {
            ...current.details,
            ...only(snapshotForm.details, Object.keys(snapshot.details)),
        },
    };
}

/**
 * 申請対象の欄の今の値(選んでいなければ null)。
 */
export function applicationTargetOf(
    record: RecordFormData,
    targetField: string | null,
): PickedReference | null {
    const value = targetField === null ? null : record.values[targetField];

    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? value
        : null;
}

/**
 * 申請対象の欄の値だけを替える(ほかの欄は変えない)。
 */
export function withApplicationTarget(
    record: RecordFormData,
    targetField: string,
    applicationTarget: PickedReference | null,
): RecordFormData {
    return {
        ...record,
        values: { ...record.values, [targetField]: applicationTarget },
    };
}
