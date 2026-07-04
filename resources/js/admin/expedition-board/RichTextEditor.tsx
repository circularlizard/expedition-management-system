import React, { useEffect, useRef, useState } from 'react';

interface RichTextEditorProps {
    value: string;
    onChange: (value: string) => void;
    minHeight?: string;
    ariaLabel?: string;
}

export const RichTextEditor: React.FC<RichTextEditorProps> = ({ value, onChange, minHeight = '80px', ariaLabel }) => {
    const ref = useRef<HTMLDivElement>(null);
    const [focused, setFocused] = useState(false);

    useEffect(() => {
        if (ref.current && !focused && ref.current.innerHTML !== value) {
            ref.current.innerHTML = value;
        }
    }, [value, focused]);

    const exec = (command: string) => {
        if (!ref.current) return;
        ref.current.focus();
        document.execCommand(command, false);
        onChange(ref.current.innerHTML);
    };

    const buttonStyle: React.CSSProperties = {
        background: '#f0f0f1',
        border: '1px solid #c5c5c5',
        borderRadius: '3px',
        padding: '4px 8px',
        fontSize: '13px',
        cursor: 'pointer',
        lineHeight: 1,
    };

    return (
        <div className="ems-rich-text-editor" style={{ border: '1px solid #8c8f94', borderRadius: '4px', overflow: 'hidden' }}>
            <style dangerouslySetInnerHTML={{ __html: `
                .ems-rte-editable ul {
                    list-style-type: disc !important;
                    margin: 8px 0 8px 20px !important;
                    padding: 0 !important;
                }
                .ems-rte-editable ol {
                    list-style-type: decimal !important;
                    margin: 8px 0 8px 20px !important;
                    padding: 0 !important;
                }
                .ems-rte-editable li {
                    display: list-item !important;
                    margin-bottom: 4px !important;
                }
            `}} />
            <div role="toolbar" style={{ display: 'flex', gap: '4px', padding: '6px 8px', background: '#f6f7f7', borderBottom: '1px solid #dcdcde' }}>
                <button type="button" onClick={() => exec('bold')} style={buttonStyle} aria-label="Bold"><strong>B</strong></button>
                <button type="button" onClick={() => exec('italic')} style={buttonStyle} aria-label="Italic"><em>I</em></button>
                <button type="button" onClick={() => exec('underline')} style={buttonStyle} aria-label="Underline"><u>U</u></button>
                <button type="button" onClick={() => exec('insertUnorderedList')} style={buttonStyle} aria-label="Bullet list">• List</button>
            </div>
            <div
                ref={ref}
                contentEditable
                role="textbox"
                aria-label={ariaLabel}
                aria-multiline="true"
                onInput={() => onChange(ref.current?.innerHTML ?? '')}
                onFocus={() => setFocused(true)}
                onBlur={() => setFocused(false)}
                className="ems-rte-editable"
                style={{ padding: '6px 8px', minHeight, fontSize: '14px', lineHeight: '1.5', outline: 'none' }}
            />
        </div>
    );
};

export default RichTextEditor;
