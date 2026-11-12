import { openingOf } from '@/lib/Workflow/applicationTarget';
import type { ApplicationTargetOpening } from '@/lib/Workflow/applicationTarget';
import { withApplicationTarget } from '@/lib/Workflow/applicationTargetSnapshot';
import { initialApplyFormOf } from '@/lib/Workflow/applyForm';
import { clearedMarksOf } from '@/lib/clearedFields';
import type { ClearedMark } from '@/lib/clearedFields';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import type { PickedReference, WorkflowApplyProps } from '@/types';

type Props = WorkflowApplyProps;

/** 写しのためだけのクエリ(申請するものを決める record は外さない。docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「写しのためだけのクエリ」) */
const COPY_ONLY_QUERIES = ['copy', 'target'];

/** 開いた時の props のうち、開いた後の GET では返らないもの(どの申請の画面のものかを mode と案件で覚える) */
export type ApplyOpened = {
    mode: Props['mode'];
    caseId: number | null;
    targetEntry: Props['targetEntry'];
    // 案件からの複製の前に覚えた履歴の項目には無い
    copy?: Props['copy'];
};

/**
 * 写しのためだけのクエリを外した URL(外すものが無ければ null = 置き換えない)。
 */
export function strippedUrlOf(href: string): string | null {
    const url = new URL(href);
    const present = COPY_ONLY_QUERIES.filter((key) =>
        url.searchParams.has(key),
    );

    if (present.length === 0) {
        return null;
    }

    present.forEach((key) => url.searchParams.delete(key));

    return url.toString();
}

/**
 * 開いた時に覚えるもの。
 */
export function openedOf(props: Props): ApplyOpened {
    return {
        mode: props.mode,
        caseId: props.case?.id ?? null,
        targetEntry: props.targetEntry,
        copy: props.copy,
    };
}

/**
 * 開いた時の props を保った props(同じ申請の画面 = mode と案件が開いた時と同じ。違えば今の props のまま)。
 * 申請対象からの申請の targetEntry と案件からの複製の copy は同じ規則(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節)。
 */
export function withOpened(props: Props, opened: ApplyOpened): Props {
    const isSameScreen =
        props.mode === opened.mode &&
        (props.case?.id ?? null) === opened.caseId;

    return isSameScreen
        ? {
              ...props,
              targetEntry: opened.targetEntry,
              copy: opened.copy ?? null,
          }
        : props;
}

/**
 * 戻る・進むで覚える値の鍵(同じ履歴の項目でも、一時保存の後のように画面が変われば別の鍵)。
 */
export function rememberKeyOf(props: Props): string {
    return `WorkflowApply:${props.mode}:${props.case?.id ?? ''}`;
}

/** 戻る・進むで覚える申請対象の写しの状態(settled = 最後に決まった申請対象) */
export type RememberedTarget = {
    settled: PickedReference | null;
};

/** 戻る・進むで覚える空にした欄の印(copy = 元の申請の値、target = 申請対象の値の写し。消えたかを含む) */
export type RememberedCleared = {
    copy: ClearedMark[];
    target: ClearedMark[];
};

/** 覚えた印か(印の形・消えたかの前に覚えた履歴の項目は違う形なので使わない) */
function isRememberedCleared(value: unknown): value is RememberedCleared {
    const isMarks = (marks: unknown) =>
        Array.isArray(marks) &&
        marks.every(
            (mark) =>
                typeof mark === 'object' &&
                mark !== null &&
                typeof (mark as { gone?: unknown }).gone === 'boolean',
        );

    return (
        typeof value === 'object' &&
        value !== null &&
        isMarks((value as RememberedCleared).copy) &&
        isMarks((value as RememberedCleared).target)
    );
}

/** 元の申請の値の印(開いた時の申請対象の写しを当てる前の値 = 画面.md の 5 節の表 1。案件からの複製の画面だけ) */
function copyMarksOf(props: Props, record: ApplyForm['record']): ClearedMark[] {
    return props.copy === null
        ? []
        : clearedMarksOf(props.copy.clearedFields, 'copy', { record });
}

/**
 * 戻る・進むで来た時のフォームの値と開いた時の写し(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「戻る・進むと申請対象の写し」、
 * 申請対象からの申請.md の 10.2 の表の「戻る・進むで来た時」の行)。
 */
export function restoredOf(
    data: ApplyForm,
    target: RememberedTarget | undefined,
    targetField: string | null,
    cleared: RememberedCleared,
): OpenedForm {
    const record =
        targetField === null || target === undefined
            ? data.record
            : withApplicationTarget(data.record, targetField, target.settled);

    return {
        restored: true,
        data: { ...data, record },
        opening: { record, confirm: null, cleared: cleared.target },
        copyMarks: cleared.copy,
    };
}

/** 開いた時のフォームの値と写しと、元の申請の値の印 */
type OpenedForm = {
    restored: boolean;
    data: ApplyForm;
    opening: ApplicationTargetOpening;
    copyMarks: ClearedMark[];
};

/**
 * 開いた時のフォームの値と写しと印(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「写しのためだけのクエリ」)。印はフォームの値と
 * 同じ分かれ道で取る(明細の行の key をフォームとそろえるため。印を覚えていない前の形の履歴の項目だけは key がそろわない)。
 * restore = 履歴の項目に覚えた値を鍵で引く口(Inertia の router.restore)。
 */
export function openedFormOf(
    props: Props,
    rememberKey: string,
    restore: (key: string) => unknown,
): OpenedForm {
    const remembered = restore(`${rememberKey}:data`) as ApplyForm | undefined;

    if (remembered !== undefined) {
        const target = restore(`${rememberKey}:target`) as
            | RememberedTarget
            | undefined;
        const cleared = restore(`${rememberKey}:cleared`);

        return restoredOf(
            remembered,
            target,
            props.targetField,
            isRememberedCleared(cleared)
                ? cleared
                : {
                      copy: copyMarksOf(
                          props,
                          initialApplyFormOf(props).record,
                      ),
                      target: [],
                  },
        );
    }

    const initial = initialApplyFormOf(props);
    const opening = openingOf(props, initial.record);

    return {
        restored: false,
        opening,
        data: { ...initial, record: opening.record },
        copyMarks: copyMarksOf(props, initial.record),
    };
}
