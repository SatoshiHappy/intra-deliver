import { BriefcaseBusiness, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PartyMark } from '@/components/PartyMark';
import { SUBJECT_TYPES } from '@/lib/Workflow/subjectRows';
import type { WorkflowSubjectType } from '@/types';

/** 役職・ロールのアイコン(ユーザー・組織は PartyMark の印) */
const ICONS: Record<'company_post' | 'role', LucideIcon> = {
    company_post: BriefcaseBusiness,
    role: ShieldCheck,
};

/**
 * 対象者の札の頭の印(ユーザー = 頭文字の丸、組織 = 組織のアイコン = 会議の関係者の欄と同じ PartyMark。役職・ロールは同じ形の丸に
 * それぞれのアイコン)。種類が混ざる欄なので、種類を読み上げる。
 */
export function SubjectMark({
    type,
    label,
}: {
    type: WorkflowSubjectType;
    label: string;
}) {
    if (type === 'user' || type === 'department') {
        return <PartyMark kind={type} label={label} size="chip" announced />;
    }

    const Icon = ICONS[type];
    const name = SUBJECT_TYPES.find((item) => item.value === type)?.label;

    return (
        <span
            role="img"
            aria-label={name}
            className="grid size-[26px] shrink-0 place-items-center rounded-full bg-accent text-primary"
        >
            <Icon aria-hidden="true" className="size-[15px]" />
        </span>
    );
}
