import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EDITED_PROPS } from '@/lib/Authz/editMode';
import { subjectKindOf } from '@/lib/Authz/subjectKinds';
import { store } from '@/routes/authz/editor/subjects';
import type { AuthzSubjectCandidate, AuthzSubjectKind } from '@/types';

type Data = { kind: AuthzSubjectKind; target_id: number | null };

const INITIAL: Data = { kind: 'user', target_id: null };

/**
 * 「列を追加」のダイアログの状態と送信(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1)。開くたびに最初の状態(ユーザー・未選択)に戻す。
 * 種類を替えると選んだ候補を捨てる。成功したら閉じ、誤りは閉じずに返す(同じ条件の列がすでにある・今日に有効でない相手は target_id)。
 */
export function useAddColumn() {
    const [open, setOpen] = useState(false);
    const [picked, setPicked] = useState<AuthzSubjectCandidate | null>(null);
    const form = useForm<Data>(INITIAL);

    const reset = (kind: AuthzSubjectKind) => {
        form.setData({ kind, target_id: null });
        form.clearErrors();
        setPicked(null);
    };

    return {
        open,
        setOpen: (next: boolean) => {
            setOpen(next);

            if (next) {
                reset(INITIAL.kind);
            }
        },
        kind: form.data.kind,
        picked,
        errors: form.errors,
        processing: form.processing,
        chooseKind: (value: string) => {
            const kind = subjectKindOf(value);

            if (kind !== undefined) {
                reset(kind);
            }
        },
        pick: (candidate: AuthzSubjectCandidate) => {
            setPicked(candidate);
            form.setData('target_id', candidate.id);
            form.clearErrors('target_id');
        },
        submit: () =>
            form.submit(store(), {
                only: EDITED_PROPS,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setOpen(false),
            }),
    };
}
