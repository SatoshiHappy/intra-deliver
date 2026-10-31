import { useRef } from 'react';
import type { ChangeEvent } from 'react';
import { Button } from '@/components/ui/button';

/** 「ファイルを選択」(複数。accept は props の形式の対応表の拡張子。選び終えたら同じファイルをまた選べるよう値を空にする) */
export function PickFiles({
    accept,
    onPick,
}: {
    accept: string;
    onPick: (files: File[]) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const change = (event: ChangeEvent<HTMLInputElement>) => {
        onPick(Array.from(event.target.files ?? []));
        event.target.value = '';
    };

    return (
        <>
            <input
                ref={input}
                type="file"
                multiple
                hidden
                accept={accept}
                aria-label="添付するファイル"
                onChange={change}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => input.current?.click()}
            >
                ファイルを選択
            </Button>
        </>
    );
}

/** 面の下に出す誤り(attachments・remove_attachment_ids) */
export function SurfaceErrors({ messages }: { messages: string[] }) {
    return messages.map((message) => (
        <p
            key={message}
            role="alert"
            tabIndex={-1}
            className="text-sm text-destructive"
        >
            {message}
        </p>
    ));
}
