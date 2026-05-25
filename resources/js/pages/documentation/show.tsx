import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type DocumentationDocument = {
    slug: string;
    locale: string;
    title: string;
    description: string;
    html: string;
};

type NavigationItem = {
    slug: string;
    title: string;
    url: string;
    active: boolean;
};

type LanguageLink = {
    locale: string;
    label: string;
    url: string;
    active: boolean;
};

type DocumentationShowProps = {
    document: DocumentationDocument;
    navigation: NavigationItem[];
    languages: LanguageLink[];
};

type MermaidModule = {
    default: {
        initialize: (options: { startOnLoad: boolean; theme: string }) => void;
        render: (id: string, definition: string) => Promise<{ svg: string }>;
    };
};

function MermaidDocumentationArticle({ html }: { html: string }) {
    const articleRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const article = articleRef.current;

        if (!article) {
            return;
        }

        const handleClick = (event: MouseEvent) => {
            const link = (event.target as HTMLElement | null)?.closest(
                'a[href^="#"]',
            );
            const hash = link?.getAttribute('href');

            if (!hash || hash === '#') {
                return;
            }

            const target = document.getElementById(hash.slice(1));

            if (!target) {
                return;
            }

            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            history.pushState(null, '', hash);
        };

        article.addEventListener('click', handleClick);

        return () => article.removeEventListener('click', handleClick);
    }, [html]);

    useEffect(() => {
        let cancelled = false;

        async function renderMermaidDiagrams() {
            const article = articleRef.current;

            if (!article) {
                return;
            }

            const blocks = Array.from(
                article.querySelectorAll<HTMLElement>('code.language-mermaid'),
            );

            if (blocks.length === 0) {
                return;
            }

            const { default: mermaid } = (await import(
                /* @vite-ignore */ 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs'
            )) as MermaidModule;

            if (cancelled) {
                return;
            }

            mermaid.initialize({
                startOnLoad: false,
                theme: document.documentElement.classList.contains('dark')
                    ? 'dark'
                    : 'default',
            });

            await Promise.all(
                blocks.map(async (block, index) => {
                    const container = document.createElement('div');
                    const source = block.textContent ?? '';
                    const { svg } = await mermaid.render(
                        `documentation-mermaid-${index}-${crypto.randomUUID()}`,
                        source,
                    );

                    container.className = 'documentation-mermaid';
                    container.innerHTML = svg;
                    block.closest('pre')?.replaceWith(container);
                }),
            );
        }

        void renderMermaidDiagrams();

        return () => {
            cancelled = true;
        };
    }, [html]);

    return (
        <article
            ref={articleRef}
            className="max-w-3xl [&_.card]:rounded-xl [&_.card]:border [&_.card]:border-black/10 [&_.card]:bg-black/[0.02] [&_.card]:p-5 dark:[&_.card]:border-white/10 dark:[&_.card]:bg-white/[0.03] [&_.card_h3]:mt-0 [&_.card_h3]:mb-3 [&_.card_p]:mb-4 [&_.card_ul]:mb-0 [&_.cards-wrapper]:my-8 [&_.cards-wrapper]:grid [&_.cards-wrapper]:grid-cols-1 [&_.cards-wrapper]:gap-4 md:[&_.cards-wrapper]:grid-cols-2 [&_.documentation-mermaid]:my-6 [&_.documentation-mermaid]:overflow-x-auto [&_.documentation-mermaid]:p-5 [&_.documentation-toc]:my-8 [&_.documentation-toc]:rounded-xl [&_.documentation-toc]:border [&_.documentation-toc]:border-black/10 [&_.documentation-toc]:bg-black/[0.02] [&_.documentation-toc]:p-5 dark:[&_.documentation-toc]:border-white/10 dark:[&_.documentation-toc]:bg-white/[0.03] [&_.documentation-toc_.toc-level-3]:pl-4 [&_.documentation-toc_.toc-number]:text-[#706f6c] dark:[&_.documentation-toc_.toc-number]:text-[#A1A09A] [&_.documentation-toc_a]:no-underline [&_.documentation-toc_ol]:m-0 [&_.documentation-toc_ol]:list-none [&_.documentation-toc_ol]:space-y-2 [&_.documentation-toc_ol]:p-0 [&_.documentation-toc_p]:mb-3 [&_.documentation-toc_p]:text-sm [&_.documentation-toc_p]:font-semibold [&_a]:font-medium [&_a]:text-[#1b1b18] [&_a]:underline dark:[&_a]:text-[#EDEDEC] [&_blockquote]:my-6 [&_blockquote]:rounded-xl [&_blockquote]:border-l-4 [&_blockquote]:border-[#1b1b18] [&_blockquote]:bg-black/[0.03] [&_blockquote]:px-5 [&_blockquote]:py-4 dark:[&_blockquote]:border-[#EDEDEC] dark:[&_blockquote]:bg-white/[0.04] [&_blockquote_p]:mb-0 [&_code]:rounded [&_code]:bg-black/5 [&_code]:px-1.5 [&_code]:py-0.5 dark:[&_code]:bg-white/10 [&_h1]:mb-5 [&_h1]:text-4xl [&_h1]:leading-tight [&_h1]:font-semibold [&_h2]:mt-12 [&_h2]:mb-4 [&_h2]:border-t [&_h2]:border-black/10 [&_h2]:pt-8 [&_h2]:text-2xl [&_h2]:font-semibold dark:[&_h2]:border-white/10 [&_h3]:mt-8 [&_h3]:mb-3 [&_h3]:text-xl [&_h3]:font-semibold [&_li]:pl-1 [&_ol]:mb-5 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-6 [&_p]:mb-5 [&_p]:leading-7 [&_p]:text-[#706f6c] dark:[&_p]:text-[#A1A09A] [&_pre]:my-6 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:border [&_pre]:border-black/10 [&_pre]:bg-black/[0.03] [&_pre]:p-5 [&_pre]:text-sm dark:[&_pre]:border-white/10 dark:[&_pre]:bg-white/[0.04] [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_ul]:mb-5 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-6"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}

export default function DocumentationShow({
    document,
    navigation,
    languages,
}: DocumentationShowProps) {
    const { appUrl } = usePage<SharedData>().props;

    return (
        <>
            <Head title={__(document.title)}>
                <meta name="description" content={__(document.description)} />
                <link
                    rel="canonical"
                    href={`${appUrl}/documentation/${document.slug}?lang=${document.locale}`}
                />
                <meta name="robots" content="index, follow" />
                <meta property="og:title" content={__(document.title)} />
                <meta
                    property="og:description"
                    content={__(document.description)}
                />
                <meta property="og:type" content="article" />
                <meta
                    property="og:url"
                    content={`${appUrl}/documentation/${document.slug}?lang=${document.locale}`}
                />
            </Head>

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="mx-auto flex max-w-6xl flex-col gap-10 px-6 py-10 lg:flex-row lg:px-8 lg:py-16">
                    <aside className="lg:w-64 lg:shrink-0">
                        <Link
                            href="/"
                            className="mb-8 inline-block text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                        >
                            {__('\u2190 Back to home')}
                        </Link>

                        <div className="mb-8 flex flex-wrap gap-2">
                            {languages.map((language) => (
                                <Link
                                    key={language.locale}
                                    href={language.url}
                                    className={
                                        language.active
                                            ? 'rounded-full bg-[#1b1b18] px-3 py-1 text-xs font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                            : 'rounded-full border border-black/10 px-3 py-1 text-xs font-medium text-[#706f6c] hover:text-[#1b1b18] dark:border-white/10 dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]'
                                    }
                                    aria-current={
                                        language.active ? 'true' : undefined
                                    }
                                >
                                    {language.label}
                                </Link>
                            ))}
                        </div>

                        <nav aria-label={__('Documentation')}>
                            <p className="mb-3 text-xs font-semibold tracking-[0.2em] text-[#706f6c] uppercase dark:text-[#A1A09A]">
                                {__('Documentation')}
                            </p>
                            <div className="flex flex-col gap-1">
                                {navigation.map((item) => (
                                    <Link
                                        key={item.slug}
                                        href={item.url}
                                        className={
                                            item.active
                                                ? 'rounded-lg bg-[#1b1b18] px-3 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                                                : 'rounded-lg px-3 py-2 text-sm text-[#706f6c] hover:bg-black/5 hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-white/10 dark:hover:text-[#EDEDEC]'
                                        }
                                    >
                                        {__(item.title)}
                                    </Link>
                                ))}
                            </div>
                        </nav>
                    </aside>

                    <main className="min-w-0 flex-1">
                        <MermaidDocumentationArticle html={document.html} />
                    </main>
                </div>
            </div>
        </>
    );
}
