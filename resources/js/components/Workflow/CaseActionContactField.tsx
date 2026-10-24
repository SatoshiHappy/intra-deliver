import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { UserPickerField } from '@/components/Workflow/UserPickerField';
import {
    CONTACT_MESSAGE_MAX,
    CONTACT_USER_MAX,
} from '@/lib/Workflow/caseActionForm';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';

const CONTACT_ID = 'case-action-contact';
const MESSAGE_ID = 'case-action-contact-message';

/**
 * 連絡の欄(連絡する人とひと言。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節): 人を選ぶ欄(10 人まで)と複数行のひと言(1000 文字まで)。
 * 人もひと言も空なら連絡なし。候補は今日の有効なユーザー(案件を渡さない。案件の操作/README.md の 4.4 節)。誤りのキーは contact.user_ids(人の欄の下)と contact.message(ひと言の下)。
 */
export function CaseActionContactField({
    users,
    message,
    errors,
    onUsersChange,
    onMessageChange,
}: {
    users: PickedUser[];
    message: string;
    errors: Record<string, string | undefined>;
    onUsersChange: (users: PickedUser[]) => void;
    onMessageChange: (message: string) => void;
}) {
    const messageError = errors['contact.message'];

    return (
        <>
            <UserPickerField
                id={CONTACT_ID}
                label="連絡する人"
                help="選択した人へ、ひと言を添えた通知を送ります。履歴には残らず、申請を閲覧できる人は変わりません。"
                hint={`${CONTACT_USER_MAX} 人まで`}
                error={errors['contact.user_ids']}
                caseId={null}
                picked={users}
                max={CONTACT_USER_MAX}
                onChange={onUsersChange}
            />
            <FormBlock id={MESSAGE_ID} label="ひと言" error={messageError}>
                <CountedTextInput
                    id={MESSAGE_ID}
                    isMultiline
                    value={message}
                    max={CONTACT_MESSAGE_MAX}
                    invalid={messageError !== undefined}
                    onChange={(event) => onMessageChange(event.target.value)}
                />
            </FormBlock>
        </>
    );
}
