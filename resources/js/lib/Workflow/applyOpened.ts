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
    // 元の申請の値で空にした欄の印(開いた時の初期値を比べる元にする。印の前に覚えた履歴の項目には無い)
    copyMarks?: ClearedMark[];
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
        copyMarks:
            props.copy === null
                ? []
                : clearedMarksOf(
                      props.copy.clearedFields,
                      'copy',
                      initialApplyFormOf(props).record,
                  ),
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

/** 戻る・進むで覚える申請対象の写しの状態(settled = 最後に決まった申請対象、cleared = 申請対象の値の写しで空にした欄の印) */
export type RememberedTarget = {
    settled: PickedReference | null;
    cleared: ClearedMark[];
};

/**
 * 覚えた申請対象の写しの印(印の形の前に覚えた履歴の項目は、空にした項目の名前の並びを持つので捨てる)。
 */
function rememberedMarksOf(
    target: RememberedTarget | undefined,
): ClearedMark[] {
    return (target?.cleared ?? []).filter(
        (mark: unknown) => typeof mark === 'object' && mark !== null,
    );
}

/**
 * 戻る・進むで来た時のフォームの値と開いた時の写し(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「戻る・進むと申請対象の写し」、
 * 申請対象からの申請.md の 10.2 の「戻る・進むで来た時」の行): 欄は離れた時の値のまま、開いた時の写しは再び当てず、確かめのダイアログは
 * 出さない。申請対象は最後に決まったものに戻す(取得・確かめの途中で離れたときは、確かめのキャンセルと同じ)。1 行は離れた時のまま。
 */
export function restoredOf(
    data: ApplyForm,
    target: RememberedTarget | undefined,
    targetField: string | null,
): { data: ApplyForm; opening: ApplicationTargetOpening } {
    const record =
        targetField === null || target === undefined
            ? data.record
            : withApplicationTarget(data.record, targetField, target.settled);

    return {
        data: { ...data, record },
        opening: { record, confirm: null, cleared: rememberedMarksOf(target) },
    };
}

/**
 * 開いた時のフォームの値と写し。戻る・進むで来た(履歴の項目に覚えた入力がある)ときは {@link restoredOf}、ほかは開いた時の写し
 * (再読み込みでは Inertia が覚えた値を消す)。restore = 履歴の項目に覚えた値を鍵で引く口(Inertia の router.restore)。
 */
export function openedFormOf(
    props: Props,
    rememberKey: string,
    restore: (key: string) => unknown,
): {
    restored: boolean;
    data: ApplyForm;
    opening: ApplicationTargetOpening;
} {
    const remembered = restore(`${rememberKey}:data`) as ApplyForm | undefined;

    if (remembered !== undefined) {
        const target = restore(`${rememberKey}:target`) as
            | RememberedTarget
            | undefined;

        return {
            restored: true,
            ...restoredOf(remembered, target, props.targetField),
        };
    }

    const initial = initialApplyFormOf(props);
    const opening = openingOf(props, initial.record);

    return {
        restored: false,
        opening,
        data: { ...initial, record: opening.record },
    };
}
