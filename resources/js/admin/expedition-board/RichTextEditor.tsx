import React, { useEffect, useRef } from 'react';

interface RichTextEditorProps {
    value: string;
    onChange: (value: string) => void;
    minHeight?: string;
    ariaLabel?: string;
}

export const RichTextEditor: React.FC<RichTextEditorProps> = ({
    value,
    onChange,
    minHeight = '150px',
    ariaLabel,
}) => {
    // Generate a unique ID for the editor element
    const idRef = useRef(`ems-editor-${Math.random().toString(36).substring(2, 9)}`);
    const onChangeRef = useRef(onChange);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

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

        if (wp && wp.editor) {
            wp.editor.initialize(editorId, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap hr lists paste tabfocus textcolor wplink',
                    toolbar1: 'formatselect bold italic underline bullist numlist alignleft aligncenter alignright link unlink',
                    setup: (editor: any) => {
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
            <textarea
                id={idRef.current}
                value={value}
                aria-label={ariaLabel}
                onChange={(e) => onChange(e.target.value)}
                className="ems-textarea-fallback"
                style={{ minHeight }}
            />
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
