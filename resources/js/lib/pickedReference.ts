import { hasPartyKind, PARTY_KIND_NAMES, partyKindOf } from '@/lib/recordParty';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordPartyKind,
} from '@/types';

/**
 * 選んだ相手を見分けるキー(型「ユーザーと組織」の項目は `<種類>:<id>`、ほかの参照の型は id。ユーザー 3 と組織 3 は別の相手 = ADR 0028)。
 * 一覧の検索条件の値(`filter[<コード>]` = 設計/業務データの入れ物/項目と規則/README.md 7 節)と同じ形。
 */
export function pickedKey(
    field: RecordFieldDefinition,
    picked: PickedReference,
): string {
    return hasPartyKind(field)
        ? `${partyKindOf(field, picked)}:${picked.id}`
        : String(picked.id);
}

/**
 * 複数の欄の候補から選び済みの相手を除く(見分けは pickedKey。型「ユーザーと組織」の項目はユーザー 3 を選んでも組織 3 を残す)。
 */
export function unpickedOptions(
    field: RecordFieldDefinition,
    options: PickedReference[],
    picked: PickedReference[],
): PickedReference[] {
    const pickedKeys = new Set(picked.map((each) => pickedKey(field, each)));

    return options.filter(
        (option) => !pickedKeys.has(pickedKey(field, option)),
    );
}

/**
 * 参照の選択欄のボタンの名前の前に出す種類の印(null = 出さない。種類が分かる形の規則 = 画面/README.md 1.3)。項目の型で決める。
 */
export function pickedMarkOf(
    field: RecordFieldDefinition,
    picked: PickedReference | null,
): RecordPartyKind | null {
    return picked === null || !hasPartyKind(field)
        ? null
        : partyKindOf(field, picked);
}

/** 候補の群(heading = 群の見出し。null = 見出しの無い 1 つの群) */
export type PickedGroup = {
    heading: string | null;
    options: PickedReference[];
};

/**
 * 候補を群に分ける(群の見出しの規則 = docs の 設計/業務データの入れ物/画面/README.md 4 節の参照の選択欄)。項目の型で決める(候補の口が
 * 相手の種類を付けるかと同じ判定にするため)。
 */
export function pickedGroups(
    field: RecordFieldDefinition,
    options: PickedReference[],
): PickedGroup[] {
    if (!hasPartyKind(field)) {
        return options.length === 0 ? [] : [{ heading: null, options }];
    }

    const kinds: RecordPartyKind[] = ['user', 'department'];

    return kinds
        .map((kind) => ({
            heading: PARTY_KIND_NAMES[kind],
            options: options.filter(
                (option) => partyKindOf(field, option) === kind,
            ),
        }))
        .filter((group) => group.options.length > 0);
}
