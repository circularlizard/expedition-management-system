import React, { useEffect, useMemo, useState } from 'react';
import { RichTextEditor, ShortcodeOption } from '../expedition-board/RichTextEditor';
import { ModuleErrorBoundary } from '../../shared/ModuleErrorBoundary';

export interface EmailTemplateToken extends ShortcodeOption {
    example?: string;
}

export interface EmailTemplateRecord {
    slug: string;
    label: string;
    category: string;
    description: string;
    subject: string;
    reply_to: string;
    body: string;
    default_subject: string;
    default_body: string;
    is_customized: boolean;
    tokens: EmailTemplateToken[];
}

interface EmailTemplatesConfig {
    root_url: string;
    nonce: string;
    current_user_email?: string;
}

declare global {
    interface Window {
        emsEmailTemplates?: EmailTemplatesConfig;
    }
}

export const EmailTemplatesInner: React.FC = () => {
    const config = window.emsEmailTemplates || { root_url: '/wp-json/ems/v1', nonce: '' };
    const [templates, setTemplates] = useState<EmailTemplateRecord[]>([]);
    const [selectedSlug, setSelectedSlug] = useState<string>('');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const [subject, setSubject] = useState<string>('');
    const [replyTo, setReplyTo] = useState<string>('');
    const [body, setBody] = useState<string>('');
    const [saving, setSaving] = useState<boolean>(false);
    const [testRecipient, setTestRecipient] = useState<string>(config.current_user_email || '');
    const [sendingTest, setSendingTest] = useState<boolean>(false);

    const loadTemplates = async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetch(`${config.root_url}/email-templates`, {
                headers: { 'X-WP-Nonce': config.nonce },
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data?.message || 'Failed to load email templates.');
            }
            const list: EmailTemplateRecord[] = Array.isArray(data) ? data : [];
            setTemplates(list);
            if (list.length > 0 && !selectedSlug) {
                const first = list[0];
                setSelectedSlug(first.slug);
                setSubject(first.subject);
                setReplyTo(first.reply_to || '');
                setBody(first.body);
            }
        } catch (err: any) {
            setError(err?.message || 'Failed to load email templates.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadTemplates();
    }, []);

    const selectedTemplate = useMemo(
        () => templates.find((t) => t.slug === selectedSlug) || null,
        [templates, selectedSlug]
    );

    const selectTemplate = (tpl: EmailTemplateRecord) => {
        setSelectedSlug(tpl.slug);
        setSubject(tpl.subject);
        setReplyTo(tpl.reply_to || '');
        setBody(tpl.body);
        setNotice(null);
        setError(null);
    };

    const groupedCategories = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        const filtered = templates.filter(
            (t) =>
                !q ||
                t.label.toLowerCase().includes(q) ||
                t.category.toLowerCase().includes(q) ||
                t.description.toLowerCase().includes(q)
        );
        const groups: Record<string, EmailTemplateRecord[]> = {};
        filtered.forEach((t) => {
            if (!groups[t.category]) groups[t.category] = [];
            groups[t.category].push(t);
        });
        return Object.entries(groups);
    }, [templates, searchQuery]);

    const handleSave = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedTemplate) return;
        setSaving(true);
        setError(null);
        setNotice(null);
        try {
            const res = await fetch(`${config.root_url}/email-templates/${selectedTemplate.slug}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ subject, reply_to: replyTo, body }),
            });
            const data = await res.json();
            if (!res.ok || data?.code) {
                throw new Error(data?.message || 'Failed to save template.');
            }
            setTemplates((prev) => prev.map((t) => (t.slug === data.slug ? data : t)));
            setNotice(`Saved "${selectedTemplate.label}" template.`);
        } catch (err: any) {
            setError(err?.message || 'Failed to save template.');
        } finally {
            setSaving(false);
        }
    };

    const handleReset = async () => {
        if (!selectedTemplate) return;
        setSaving(true);
        setError(null);
        setNotice(null);
        try {
            const res = await fetch(`${config.root_url}/email-templates/${selectedTemplate.slug}/reset`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': config.nonce },
            });
            const data = await res.json();
            if (!res.ok || data?.code) {
                throw new Error(data?.message || 'Failed to reset template.');
            }
            setTemplates((prev) => prev.map((t) => (t.slug === data.slug ? data : t)));
            setSubject(data.subject);
            setReplyTo(data.reply_to || '');
            setBody(data.body);
            setNotice(`Reset "${selectedTemplate.label}" to default.`);
        } catch (err: any) {
            setError(err?.message || 'Failed to reset template.');
        } finally {
            setSaving(false);
        }
    };

    const handleSendTest = async () => {
        if (!selectedTemplate) return;
        setSendingTest(true);
        setError(null);
        setNotice(null);
        try {
            const res = await fetch(`${config.root_url}/email-templates/${selectedTemplate.slug}/test`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({
                    to: testRecipient,
                    subject,
                    reply_to: replyTo,
                    body,
                }),
            });
            const data = await res.json();
            if (!res.ok || data?.code) {
                throw new Error(data?.message || 'Failed to send test email.');
            }
            setNotice(`Test email sent to ${data.to}.`);
        } catch (err: any) {
            setError(err?.message || 'Failed to send test email.');
        } finally {
            setSendingTest(false);
        }
    };

    const insertTokenIntoBody = (token: string) => {
        const tinymce = (window as any).tinymce;
        const wrapper = document.querySelector('.ems-email-templates-editor .ems-editor-wrapper textarea');
        const editorId = wrapper?.getAttribute('id');
        const editor = editorId ? tinymce?.get(editorId) : null;
        if (editor) {
            editor.insertContent(token);
            setBody(editor.getContent());
            return;
        }
        setBody((prev) => `${prev}${prev ? ' ' : ''}${token}`);
    };

    if (loading) {
        return <div className="ems-panel">Loading email templates…</div>;
    }

    return (
        <div className="ems-email-templates-layout">
            <aside className="ems-email-templates-sidebar ems-panel">
                <div className="ems-mb-12">
                    <input
                        type="search"
                        className="ems-w-full"
                        placeholder="Filter templates…"
                        aria-label="Filter email templates"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                </div>
                {groupedCategories.length === 0 ? (
                    <div className="ems-meta-text">No templates match your search.</div>
                ) : (
                    groupedCategories.map(([category, items]) => (
                        <div key={category} className="ems-email-templates-group">
                            <div className="ems-email-templates-group__title">{category}</div>
                            {items.map((tpl) => (
                                <button
                                    key={tpl.slug}
                                    type="button"
                                    className={`ems-email-templates-item ${
                                        tpl.slug === selectedSlug ? 'ems-email-templates-item--active' : ''
                                    }`}
                                    onClick={() => selectTemplate(tpl)}
                                >
                                    <span className="ems-font-semibold">{tpl.label}</span>
                                    {tpl.is_customized && (
                                        <span className="ems-badge ems-badge--info">Custom</span>
                                    )}
                                </button>
                            ))}
                        </div>
                    ))
                )}
            </aside>

            <section className="ems-email-templates-main">
                {error && <div className="notice notice-error ems-mb-12"><p>{error}</p></div>}
                {notice && <div className="notice notice-success ems-mb-12"><p>{notice}</p></div>}

                {selectedTemplate && (
                    <form onSubmit={handleSave} className="ems-panel ems-email-templates-editor">
                        <div className="ems-flex-between ems-wrap ems-gap-12 ems-mb-16">
                            <div>
                                <h2 className="ems-mt-0 ems-mb-4">{selectedTemplate.label}</h2>
                                <p className="ems-meta-text ems-mt-0 ems-mb-0">{selectedTemplate.description}</p>
                            </div>
                            <div className="ems-flex-center ems-gap-8">
                                <button
                                    type="button"
                                    className="button button-secondary"
                                    onClick={handleReset}
                                    disabled={saving}
                                >
                                    Reset to Default
                                </button>
                                <button
                                    type="submit"
                                    className="button button-primary"
                                    disabled={saving}
                                >
                                    {saving ? 'Saving…' : 'Save Template'}
                                </button>
                            </div>
                        </div>

                        <div className="ems-mb-12">
                            <label htmlFor="ems-tpl-subject" className="ems-font-semibold ems-display-block ems-mb-4">
                                Subject
                            </label>
                            <input
                                id="ems-tpl-subject"
                                type="text"
                                className="large-text ems-w-full"
                                value={subject}
                                onChange={(e) => setSubject(e.target.value)}
                            />
                        </div>

                        <div className="ems-mb-12">
                            <label htmlFor="ems-tpl-reply-to" className="ems-font-semibold ems-display-block ems-mb-4">
                                Reply-To (optional)
                            </label>
                            <input
                                id="ems-tpl-reply-to"
                                type="text"
                                className="large-text ems-w-full"
                                placeholder="e.g. dofe@example.com"
                                value={replyTo}
                                onChange={(e) => setReplyTo(e.target.value)}
                            />
                        </div>

                        <div className="ems-mb-16">
                            <label className="ems-font-semibold ems-display-block ems-mb-4">
                                HTML Body
                            </label>
                            <RichTextEditor
                                key={selectedTemplate.slug}
                                value={body}
                                onChange={setBody}
                                minHeight="260px"
                                ariaLabel="Email HTML Body"
                                shortcodes={selectedTemplate.tokens}
                            />
                        </div>

                        <div className="ems-email-templates-test-box ems-mb-16">
                            <label htmlFor="ems-tpl-test-to" className="ems-font-semibold">
                                Send Test Email:
                            </label>
                            <input
                                id="ems-tpl-test-to"
                                type="email"
                                placeholder="recipient@example.com"
                                value={testRecipient}
                                onChange={(e) => setTestRecipient(e.target.value)}
                            />
                            <button
                                type="button"
                                className="button button-secondary"
                                onClick={handleSendTest}
                                disabled={sendingTest || !testRecipient.trim()}
                            >
                                {sendingTest ? 'Sending…' : 'Send Test Email'}
                            </button>
                        </div>

                        <div className="ems-email-templates-shortcodes">
                            <h3 className="ems-mt-0 ems-mb-8">Available Shortcodes</h3>
                            <div className="ems-email-templates-shortcodes__list">
                                {selectedTemplate.tokens.map((tok) => (
                                    <div key={tok.token} className="ems-email-templates-shortcode-row">
                                        <div>
                                            <code>{tok.token}</code>
                                            {tok.supports_levels && (
                                                <span className="ems-badge ems-badge--info ems-ml-6">
                                                    supports levels=&quot;bronze,silver,gold&quot;
                                                </span>
                                            )}
                                            <div className="ems-meta-text ems-small-text ems-mt-4">
                                                {tok.description}
                                            </div>
                                        </div>
                                        <div className="ems-flex-center ems-gap-6">
                                            <button
                                                type="button"
                                                className="button button-small"
                                                onClick={() => insertTokenIntoBody(tok.token)}
                                            >
                                                Insert into Body
                                            </button>
                                            <button
                                                type="button"
                                                className="button button-small"
                                                onClick={() => setSubject((prev) => `${prev} ${tok.token}`.trim())}
                                            >
                                                + Subject
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </form>
                )}
            </section>
        </div>
    );
};

export default function EmailTemplatesApp() {
    return (
        <ModuleErrorBoundary moduleName="Email Templates">
            <EmailTemplatesInner />
        </ModuleErrorBoundary>
    );
}
