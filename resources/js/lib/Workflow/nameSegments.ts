import type { WorkflowLinkedPerson } from '@/types';

/**
 * 名前の札を含む文のひと区切り(文字列 = そのまま出す字、person = 名前の札。text は札に出す字 = 名前と「さん」「(自分)」)。
 * 文の並びと区切りは文字列のまま保ち、人の名前だけを札にする(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.2)。
 */
export type NameSegment =
    | string
    | { person: WorkflowLinkedPerson; text: string };

/**
 * 区切りをつないだ文(札は札に出す字。札の人の型は問わない)。
 */
export function segmentsText(segments: (string | { text: string })[]): string {
    return segments
        .map((segment) =>
            typeof segment === 'string' ? segment : segment.text,
        )
        .join('');
}

/**
 * 人の札を「、」でつないだ区切り(textOf = 札に出す字)。
 */
export function joinedPeople<Person extends WorkflowLinkedPerson>(
    people: Person[],
    textOf: (person: Person) => string,
): NameSegment[] {
    return people.flatMap((person, index) => {
        const segment = { person, text: textOf(person) };

        return index === 0 ? [segment] : ['、', segment];
    });
}
