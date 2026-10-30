import { pickedKey } from '@/lib/pickedReference';
import { isReferenceField } from '@/lib/recordForm';
import { hasPartyKind } from '@/lib/recordParty';
import { toTokyoLocal } from '@/lib/recordValues';
import type { QueryParams } from '@/wayfinder';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordFilterValue,
    RecordListQuery,
} from '@/types';

/**
 * 業務の一覧の検索欄の値と、クエリの filter への変換(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.1)。
 */

type Range = { from: string; to: string };

/** 欄の値: 文字列・真偽・選択 = 文字列、範囲 = 下限と上限、参照 = 選んだ相手、複数選べる参照 = 選んだ相手の並び */
export type SearchDraft =
    | string
    | Range
    | PickedReference
    | PickedReference[]
    | null;

/** 型「ユーザーと組織」の相手の条件の形(`user:<id>`・`department:<id>` = 項目と規則/README.md 7 節) */
const PARTY_PATTERN = /^(user|department):([1-9][0-9]*)$/;

/**
 * 読み取りが解釈した参照の条件の値 1 つを、選んだ相手にする(表示名を持たないので #<id> を出す)。読めなければ null。
 */
export function pickedOf(
    field: RecordFieldDefinition,
    value: string,
): PickedReference | null {
    if (!hasPartyKind(field)) {
        return /^[1-9][0-9]*$/.test(value)
            ? { id: Number(value), label: `#${value}` }
            : null;
    }

    const matched = PARTY_PATTERN.exec(value);

    return matched === null
        ? null
        : {
              id: Number(matched[2]),
              label: `#${matched[2]}`,
              type: matched[1] === 'user' ? 'user' : 'department',
          };
}

/**
 * 欄の値を、クエリの filter の 1 つの値にする(空は undefined。日時の範囲の秒と時差 = 画面/業務の画面.md 2.1 の「クエリ」)。
 * 複数選べる参照は相手の並び(クエリの配列。添字の形 `filter[<コード>][<n>]`)。
 */
export function filterValue(
    field: RecordFieldDefinition,
    draft: SearchDraft,
): QueryParams[string] {
    if (draft === null || draft === '') {
        return undefined;
    }

    if (typeof draft === 'string') {
        return draft;
    }

    if (Array.isArray(draft)) {
        return draft.length === 0
            ? undefined
            : draft.map((picked) => pickedKey(field, picked));
    }

    if ('id' in draft) {
        return pickedKey(field, draft);
    }

    const suffix = (side: 'from' | 'to') =>
        field.type === 'datetime'
            ? side === 'from'
                ? ':00+09:00'
                : ':59+09:00'
            : '';
    const from = draft.from === '' ? undefined : draft.from + suffix('from');
    const to = draft.to === '' ? undefined : draft.to + suffix('to');

    return from === undefined && to === undefined ? undefined : { from, to };
}

export function isRanged(field: RecordFieldDefinition) {
    return ['integer', 'decimal', 'date', 'datetime'].includes(field.type);
}

export function isPicked(draft: SearchDraft): draft is PickedReference {
    return (
        typeof draft === 'object' &&
        draft !== null &&
        !Array.isArray(draft) &&
        'id' in draft
    );
}

/**
 * 読み取りが解釈した検索条件から、欄の初期値を作る(日時の範囲は Asia/Tokyo の datetime-local の形に直す = 画面/業務の画面.md 2.1)。
 * 参照は表示名を持たないので #<id> を出す。複数選べる参照は相手の並び(条件が無ければ空の並び)。
 */
export function initialDraft(
    field: RecordFieldDefinition,
    value: RecordFilterValue | undefined,
): SearchDraft {
    if (isRanged(field)) {
        const range =
            typeof value === 'object' && !Array.isArray(value) ? value : null;
        const local = (side: string | null | undefined) =>
            side === null || side === undefined
                ? ''
                : field.type === 'datetime'
                  ? (toTokyoLocal(side) ?? '')
                  : side;

        return { from: local(range?.from), to: local(range?.to) };
    }

    if (isReferenceField(field) && field.isMultiple) {
        return Array.isArray(value)
            ? value.flatMap((text) => pickedOf(field, text) ?? [])
            : [];
    }

    if (isReferenceField(field)) {
        return typeof value === 'string' ? pickedOf(field, value) : null;
    }

    return typeof value === 'string' ? value : '';
}

/**
 * 読み取りが解釈した検索条件から、欄の全体を作る。参照は、今の欄が同じ相手(種類と id)を選んでいればその表示名を残す(無ければ #<id>)。
 */
export function draftsOf(
    fields: RecordFieldDefinition[],
    query: RecordListQuery,
    current: Record<string, SearchDraft>,
): Record<string, SearchDraft> {
    return Object.fromEntries(
        fields.map((field) => {
            const next = initialDraft(field, query.filters[field.code]);
            const kept = current[field.code] ?? null;
            const keptList: PickedReference[] = Array.isArray(kept)
                ? kept
                : isPicked(kept)
                  ? [kept]
                  : [];
            const keptPicks = new Map(
                keptList.map((picked): [string, PickedReference] => [
                    pickedKey(field, picked),
                    picked,
                ]),
            );
            const keep = (picked: PickedReference) =>
                keptPicks.get(pickedKey(field, picked)) ?? picked;

            if (Array.isArray(next)) {
                return [field.code, next.map(keep)];
            }

            return [field.code, isPicked(next) ? keep(next) : next];
        }),
    );
}
