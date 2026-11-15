import React, { useEffect, useRef } from 'react';

export interface ShortcodeOption {
    token: string;
    label: string;
    description?: string;
    supports_levels?: boolean;
}

interface RichTextEditorProps {
    value: string;
    onChange: (value: string) => void;
    minHeight?: string;
    ariaLabel?: string;
    shortcodes?: ShortcodeOption[];
}

const buildShortcodeMenuItems = (
    shortcodes: ShortcodeOption[],
    insertToken: (token: string) => void
): any[] =>
    shortcodes.map((sc) => {
        if (sc.supports_levels) {
            const baseName = sc.token.replace(/^\{\{|\}\}$/g, '').trim();
            return {
                text: `${sc.label} (${sc.token})`,
                menu: [
                    { text: 'All Levels', onclick: () => insertToken(sc.token) },
                    { text: 'Bronze only', onclick: () => insertToken(`{{${baseName} levels="bronze"}}`) },
                    { text: 'Silver only', onclick: () => insertToken(`{{${baseName} levels="silver"}}`) },
                    { text: 'Gold only', onclick: () => insertToken(`{{${baseName} levels="gold"}}`) },
                    { text: 'Bronze & Silver', onclick: () => insertToken(`{{${baseName} levels="bronze,silver"}}`) },
                ],
            };
        }
        return {
            text: `${sc.label} (${sc.token})`,
            onclick: () => insertToken(sc.token),
        };
    });

export const RichTextEditor: React.FC<RichTextEditorProps> = ({
    value,
    onChange,
    minHeight = '150px',
    ariaLabel,
    shortcodes = [],
}) => {
    // Generate a unique ID for the editor element
    const idRef = useRef(`ems-editor-${Math.random().toString(36).substring(2, 9)}`);
    const onChangeRef = useRef(onChange);
    const shortcodesRef = useRef(shortcodes);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        shortcodesRef.current = shortcodes;
    }, [shortcodes]);

    // Handle external value updates (e.g. when loading another event)
    useEffect(() => {
        const tinymce = (window as any).tinymce;
        const editor = tinymce?.get(idRef.current);
        if (editor && value !== editor.getContent()) {
            editor.setContent(value || '');
        }
    }, [value]);

    useEffect(() => {
        const editorId = idRef.current;
        const wp = (window as any).wp;
        const hasShortcodes = shortcodesRef.current.length > 0;

        if (wp && wp.editor) {
            wp.editor.initialize(editorId, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap hr lists paste tabfocus textcolor wplink',
                    toolbar1: hasShortcodes
                        ? 'formatselect bold italic underline bullist numlist alignleft aligncenter alignright link unlink ems_shortcodes'
                        : 'formatselect bold italic underline bullist numlist alignleft aligncenter alignright link unlink',
                    setup: (editor: any) => {
                        if (hasShortcodes) {
                            editor.addButton('ems_shortcodes', {
                                type: 'menubutton',
                                text: 'Insert Shortcode',
                                icon: false,
                                menu: buildShortcodeMenuItems(shortcodesRef.current, (token) => {
                                    editor.insertContent(token);
                                    onChangeRef.current(editor.getContent());
                                }),
                            });
                        }
                        editor.on('change keyup input', () => {
                            const content = editor.getContent();
                            onChangeRef.current(content);
                        });
                    },
                },
                quicktags: true,
            });
        }

        return () => {
            const currentWp = (window as any).wp;
            if (currentWp && currentWp.editor) {
                currentWp.editor.remove(editorId);
            }
        };
    }, []);

    const wp = (window as any).wp;
    if (!wp || !wp.editor) {
        // Fallback for tests / non-WordPress environments
        return (
            <div className="ems-editor-fallback-wrap">
                {shortcodes.length > 0 && (
                    <div className="ems-rte-shortcode-toolbar">
                        <label htmlFor={`${idRef.current}-sc`} className="ems-small-text ems-font-semibold">
                            Insert Shortcode:
                        </label>
                        <select
                            id={`${idRef.current}-sc`}
                            aria-label="Insert Shortcode"
                            defaultValue=""
                            onChange={(e) => {
                                if (!e.target.value) return;
                                onChange(`${value}${value ? ' ' : ''}${e.target.value}`);
                                e.target.value = '';
                            }}
                        >
                            <option value="">Choose shortcode...</option>
                            {shortcodes.map((sc) => (
                                <option key={sc.token} value={sc.token}>
                                    {sc.label} ({sc.token})
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <textarea
                    id={idRef.current}
                    value={value}
                    aria-label={ariaLabel}
                    onChange={(e) => onChange(e.target.value)}
                    className="ems-textarea-fallback"
                    style={{ minHeight }}
                />
            </div>
        );
    }

    return (
        <div className="ems-editor-wrapper">
            <textarea
                id={idRef.current}
                defaultValue={value}
                aria-label={ariaLabel}
                className="ems-textarea-fallback"
                style={{ minHeight }}
            />
        </div>
    );
};

export default RichTextEditor;
