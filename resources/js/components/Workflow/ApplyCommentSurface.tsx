import type { InertiaFormProps } from '@inertiajs/react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { FormSurface } from '@/components/FormLayout';
import type { ApplyForm } from '@/lib/Workflow/applyForm';

const COMMENT_ID = 'workflow-case-comment';

/**
 * コメントの面(任意。申請の履歴に残る。max = 文字数の上限)。
 */
export function ApplyCommentSurface({
    form,
    max,
}: {
    form: InertiaFormProps<ApplyForm>;
    max: number;
}) {
    const error = form.errors.comment;

    return (
        <FormSurface aria-labelledby="apply-comment-heading">
            <h2
                id="apply-comment-heading"
                className="text-[15px] font-semibold"
            >
                コメント
            </h2>
            <FormBlock
                id={COMMENT_ID}
                label="コメント"
                help="任意の入力です。"
                error={error}
            >
                <CountedTextInput
                    id={COMMENT_ID}
                    isMultiline
                    value={form.data.comment}
                    max={max}
                    invalid={error !== undefined}
                    hasHelp
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
            </FormBlock>
        </FormSurface>
    );
}
