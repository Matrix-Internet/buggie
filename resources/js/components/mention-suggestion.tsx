import { cn } from '@/lib/utils';
import type { MentionNodeAttrs } from '@tiptap/extension-mention';
import { ReactRenderer } from '@tiptap/react';
import type { SuggestionKeyDownProps, SuggestionOptions, SuggestionProps } from '@tiptap/suggestion';
import { forwardRef, useEffect, useImperativeHandle, useState } from 'react';

export interface Mentionable {
    id: number;
    name: string;
    /** Clients are offered only in text they will be able to read. */
    client?: boolean;
}

type ListProps = SuggestionProps<Mentionable, MentionNodeAttrs>;
type ListHandle = { onKeyDown: (props: SuggestionKeyDownProps) => boolean };

const MentionList = forwardRef<ListHandle, ListProps>(function MentionList({ items, command }, ref) {
    const [active, setActive] = useState(0);

    useEffect(() => setActive(0), [items]);

    const choose = (index: number) => {
        const person = items[index];
        if (person) command({ id: String(person.id), label: person.name });
    };

    useImperativeHandle(ref, () => ({
        onKeyDown: ({ event }) => {
            if (items.length === 0) return false;

            if (event.key === 'ArrowDown') {
                setActive((i) => (i + 1) % items.length);
                return true;
            }
            if (event.key === 'ArrowUp') {
                setActive((i) => (i + items.length - 1) % items.length);
                return true;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                choose(active);
                return true;
            }
            return false;
        },
    }));

    if (items.length === 0) return null;

    return (
        <ul
            role="listbox"
            aria-label="People to mention"
            className="max-h-56 min-w-44 overflow-y-auto rounded-lg border border-border bg-raised py-1 text-sm shadow-lg"
        >
            {items.map((person, index) => (
                <li
                    key={person.id}
                    role="option"
                    aria-selected={index === active}
                    // mousedown, not click: a click would blur the editor first.
                    onMouseDown={(e) => {
                        e.preventDefault();
                        choose(index);
                    }}
                    onMouseEnter={() => setActive(index)}
                    className={cn(
                        'flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5',
                        index === active ? 'bg-accent-soft text-ink' : 'text-ink-muted',
                    )}
                >
                    <span className="truncate">{person.name}</span>
                    {person.client && <span className="text-xs text-ink-subtle">client</span>}
                </li>
            ))}
        </ul>
    );
});

/**
 * The @ menu. People come from a function rather than a list because the editor is
 * built once, and who may be mentioned changes with it — switching a comment between
 * internal and public changes whether clients are offered.
 */
export function mentionSuggestion(
    people: () => Mentionable[],
): Omit<SuggestionOptions<Mentionable, MentionNodeAttrs>, 'editor'> {
    return {
        items: ({ query }) => {
            const q = query.toLowerCase();

            return people()
                .filter((person) => person.name.toLowerCase().includes(q))
                .sort((a, b) => Number(!a.name.toLowerCase().startsWith(q)) - Number(!b.name.toLowerCase().startsWith(q)))
                .slice(0, 8);
        },
        render: () => {
            let renderer: ReactRenderer<ListHandle, ListProps> | null = null;
            let popup: HTMLDivElement | null = null;

            const place = (props: ListProps) => {
                const rect = props.clientRect?.();
                if (!popup || !rect) return;

                popup.style.left = `${rect.left}px`;
                popup.style.top = `${rect.bottom + 4}px`;
            };

            return {
                onStart: (props) => {
                    renderer = new ReactRenderer(MentionList, { props, editor: props.editor });
                    popup = document.createElement('div');
                    popup.style.position = 'fixed';
                    popup.style.zIndex = '60';
                    popup.appendChild(renderer.element);
                    document.body.appendChild(popup);
                    place(props);
                },
                onUpdate: (props) => {
                    renderer?.updateProps(props);
                    place(props);
                },
                onKeyDown: (props) => {
                    if (props.event.key === 'Escape') {
                        popup?.remove();
                        return true;
                    }

                    return renderer?.ref?.onKeyDown(props) ?? false;
                },
                onExit: () => {
                    popup?.remove();
                    renderer?.destroy();
                    popup = null;
                    renderer = null;
                },
            };
        },
    };
}
