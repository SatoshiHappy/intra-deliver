import { isFieldKey } from '@/lib/Workflow/applyScreen';

/**
 * 欄の下に出さない誤り(case・draft・record・封筒の誤り。申請者の欄が無いときは申請者の誤りも)をフォームの先頭に出す。
 * 送った後に最初の誤りへフォーカスを移せるよう、tabIndex をいつも付ける。
 */
export function ApplyUnshownErrors({
    errors,
    showsApplicant,
}: {
    errors: Record<string, string | undefined>;
    showsApplicant: boolean;
}) {
    return Object.keys(errors)
        .filter((key) => !isFieldKey(key, showsApplicant))
        .sort()
        .map((key) => (
            <p
                key={key}
                role="alert"
                tabIndex={-1}
                className="text-sm text-destructive"
            >
                {errors[key]}
            </p>
        ));
}
