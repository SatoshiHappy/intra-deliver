import { useState } from 'react';
import {
    filesToSendOf,
    removalsOf,
    toggledMarksOf,
} from '@/lib/Workflow/applyAttachment';
import type { WorkflowApplyAttachment } from '@/types';

/** 選んだファイルの並びが触る外の口(確かめるときは偽物を渡す) */
export type FilesPort = {
    setFiles: (update: (current: File[]) => File[]) => void;
    /** 並びを変える操作の後に呼ぶ(呼び手が、送った時の並びに当たる誤りを消す) */
    onFilesChanged: () => void;
};

/**
 * 選んだファイルの並びを変える操作。再描画の前に操作が重なっても前の結果を失わないよう、次の並びは更新関数が今の並びから作る。
 * 削除はファイルで受け(添字は重なった操作でずれる)、フックの外に置いて口を差し替えて確かめられるようにした。
 */
export function filesOperationsOf(port: FilesPort) {
    const change = (update: (current: File[]) => File[]) => {
        port.setFiles(update);
        port.onFilesChanged();
    };

    return {
        add: (selected: File[]) => {
            if (selected.length > 0) {
                change((current) => [...current, ...selected]);
            }
        },
        remove: (file: File) =>
            change((current) => current.filter((item) => item !== file)),
        reset: () => change(() => []),
    };
}

/**
 * 申請の画面の添付の状態(docs の 設計/ワークフロー/申請の周辺/画面.md の 2 節)。使えなくなっても選んだファイルと印を消さず、
 * 見せる・送るものを今の props から導く(送信の失敗や読み直しの後も、利用者の選んだものを保つため)。
 */
export function useApplyAttachment(
    attachment: WorkflowApplyAttachment,
    onFilesChanged: () => void,
) {
    const [chosen, setChosen] = useState<File[]>([]);
    const [marks, setMarks] = useState<number[]>([]);
    const operations = filesOperationsOf({
        setFiles: setChosen,
        onFilesChanged,
    });

    return {
        files: filesToSendOf(attachment, chosen),
        removals: removalsOf(marks, attachment.drafts),
        addFiles: operations.add,
        removeFile: operations.remove,
        /** 送れたら選んだファイルと印を空に戻す(送った先も同じ画面のとき、画面の状態は残るので、送ったファイルをまた送らない) */
        reset: () => {
            operations.reset();
            setMarks([]);
        },
        toggleRemoval: (id: number) =>
            setMarks((current) =>
                toggledMarksOf(current, attachment.drafts, id),
            ),
    };
}
