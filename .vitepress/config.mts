import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig, type DefaultTheme } from 'vitepress'

// The site source is the repository root, not docs/. That is what lets README.md,
// AGENTS.md and CONTRIBUTING.md be pages here while staying where their own
// conventions require them, with one copy and relative links that resolve both in
// git and on the site.
const ROOT = fileURLToPath(new URL('..', import.meta.url))

const REPOSITORY = 'https://github.com/Gcob/lara-spec-first'

/**
 * A sidebar group. `directory` is published in `sequence` order; anything found in
 * the directory but missing from `sequence` is appended alphabetically, so a new
 * file is never invisible. `also` names pages outside the directory.
 *
 * Titles are never written here: they are read from each document's front matter,
 * which owns them. This file holds order and grouping only.
 */
type Section = {
    text: string
    directory?: string
    sequence?: string[]
    also?: string[]
}

const SECTIONS: Section[] = [
    {
        text: 'Guide',
        directory: 'docs/guide',
        sequence: [
            'openapi-support',
            'code-generation',
            'doctor',
            'lifecycle',
            'remote-references',
        ],
    },
    {
        text: 'Project',
        directory: 'docs/project',
        sequence: ['roadmap', 'stack'],
    },
    {
        text: 'Internals',
        directory: 'docs/internals',
        sequence: ['contract-artifact'],
    },
    {
        text: 'Contributing',
        directory: 'docs/contributing',
        sequence: ['documentation'],
        also: ['CONTRIBUTING.md', 'AGENTS.md'],
    },
]

/** The `title` from a document's front matter, falling back to its first heading. */
function titleOf(path: string): string {
    const contents = readFileSync(join(ROOT, path), 'utf-8')
    const frontMatter = contents.match(/^---\r?\n([\s\S]*?)\r?\n---/)

    const title = frontMatter?.[1].match(/^title:\s*(.+?)\s*$/m)?.[1]
    if (title) {
        return title.replace(/^['"]|['"]$/g, '')
    }

    return contents.match(/^#\s+(.+?)\s*$/m)?.[1] ?? path
}

function itemFor(path: string): DefaultTheme.SidebarItem {
    return { text: titleOf(path), link: '/' + path.replace(/\.md$/, '') }
}

function pagesIn(section: Section): string[] {
    if (!section.directory) {
        return []
    }

    const files = readdirSync(join(ROOT, section.directory))
        .filter((name) => name.endsWith('.md'))
        .map((name) => name.replace(/\.md$/, ''))

    const sequence = section.sequence ?? []
    const ordered = sequence.filter((name) => files.includes(name))
    const rest = files.filter((name) => !sequence.includes(name)).sort()

    return [...ordered, ...rest].map((name) => `${section.directory}/${name}.md`)
}

const sidebar: DefaultTheme.SidebarItem[] = SECTIONS.map((section) => ({
    text: section.text,
    collapsed: false,
    items: [...pagesIn(section), ...(section.also ?? [])].map(itemFor),
}))

export default defineConfig({
    title: 'lara-spec-first',
    description:
        'A Spec-First API framework for Laravel. Define your contracts in OpenAPI, generate stubs, mock endpoints, and bridge legacy code.',

    srcDir: '.',

    // Everything the site must not treat as a page. Dependencies and build output,
    // the Workbench application, and the local-only directories that never leave a
    // machine.
    srcExclude: [
        'vendor/**',
        'node_modules/**',
        'workbench/**',
        'build/**',
        'reviews/**',
        'tests/**',
        '.claude/**',
        '.github/**',
        'CLAUDE.local.md',
    ],

    // README.md is the landing page in git; it is the landing page here too, rather
    // than a second file repeating its pitch.
    //
    // The docs/ prefix stays in the URL. Rewriting it away was tried and broke every
    // link from a root file into docs/: relative links are resolved against the
    // source tree, not against the rewritten one. Keeping the URL equal to the file
    // path is also what lets the same link work in git and on the site.
    rewrites: {
        'README.md': 'index.md',
    },

    // GitHub Pages serves a project site from a subdirectory. Both this and the
    // sitemap hostname change if a custom domain is ever pointed at it.
    base: '/lara-spec-first/',
    sitemap: {
        hostname: 'https://gcob.github.io/lara-spec-first/',
    },

    cleanUrls: true,
    lastUpdated: true,

    // A dead link fails the build, which is how a rename gets caught. The one
    // exception is the local Workbench server, which CONTRIBUTING.md tells a
    // contributor to open and which is unreachable from any build.
    ignoreDeadLinks: [/^https?:\/\/localhost(:\d+)?/],

    head: [
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'lara-spec-first' }],
        [
            'meta',
            {
                property: 'og:description',
                content:
                    'A Spec-First API framework for Laravel, built on OpenAPI contracts.',
            },
        ],
    ],

    themeConfig: {
        nav: [
            { text: 'Guide', link: '/docs/guide/openapi-support' },
            { text: 'Project', link: '/docs/project/roadmap' },
            { text: 'Internals', link: '/docs/internals/contract-artifact' },
            { text: 'Contributing', link: '/docs/contributing/documentation' },
        ],

        sidebar,

        outline: [2, 3],

        search: {
            provider: 'local',
        },

        socialLinks: [{ icon: 'github', link: REPOSITORY }],

        editLink: {
            pattern: `${REPOSITORY}/edit/main/:path`,
            text: 'Edit this page on GitHub',
        },

        footer: {
            message: `Released under the <a href="${REPOSITORY}/blob/main/LICENSE">MIT License</a>.`,
            copyright: `<a href="${REPOSITORY}">github.com/Gcob/lara-spec-first</a>`,
        },
    },
})
