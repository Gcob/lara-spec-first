/*
 * Every `@see docs/….md — "Heading"` in the code names a heading that exists.
 *
 * The convention this checks is the one the codebase already follows: a class
 * points at the section that justifies it, by document and by title. Renaming a
 * heading breaks every one of those references, and nothing noticed until a
 * review counted them: `docs:check-anchors` reads the built site, so it covers
 * links written in Markdown and nothing written in PHP.
 *
 * Titles rather than anchors, because that is what the convention writes. A
 * title is what a reader greps for, and it survives a change to the slug rule;
 * the four docblocks that do link an anchor are checked here too, against the
 * same headings, with the slug derived the way the site derives it.
 *
 * Dependency-free, and reads the sources rather than the built site, so it runs
 * before anything is built.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const ROOT = process.cwd()
const DOCUMENT_ROOTS = ['docs', '.']
const CODE_ROOTS = ['src', 'tests', 'config']

/** Every Markdown file that can be the target of a reference. */
function markdownFiles() {
    const found = []

    const walk = (directory) => {
        for (const entry of readdirSync(directory)) {
            if (entry === 'node_modules' || entry === 'vendor' || entry.startsWith('.')) continue

            const path = join(directory, entry)

            if (statSync(path).isDirectory()) walk(path)
            else if (entry.endsWith('.md')) found.push(relative(ROOT, path))
        }
    }

    walk(join(ROOT, 'docs'))

    for (const name of ['README.md', 'AGENTS.md', 'CONTRIBUTING.md']) {
        found.push(name)
    }

    return found
}

/** GitHub's slug rule, the one `.vitepress/config.mts` configures the site to use. */
function slug(title) {
    return title
        .toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-')
}

/** The plain text of a heading, or of a list item that leads with a bolded term. */
function titlesIn(contents) {
    const titles = new Set()
    const anchors = new Set()

    for (const line of contents.split('\n')) {
        const heading = line.match(/^#{1,6}\s+(.*)$/)

        if (heading) {
            const text = heading[1].replace(/[`*_]/g, '').trim()
            titles.add(text)
            anchors.add(slug(text))
            continue
        }

        // `- **Term.**` and `- [x] **Term.**`, which the convention also names.
        const item = line.match(/^\s*[-*]\s+(?:\[[ x]\]\s+)?\*\*(.+?)\.?\*\*/)

        if (item) titles.add(item[1].replace(/[`*_]/g, '').trim())
    }

    return { titles, anchors }
}

const documents = new Map()

for (const file of markdownFiles()) {
    try {
        documents.set(file, titlesIn(readFileSync(join(ROOT, file), 'utf-8')))
    } catch {
        // A file named in the walk but unreadable is not this check's problem.
    }
}

/** Every PHP and config file that can carry a reference. */
function codeFiles() {
    const found = []

    const walk = (directory) => {
        for (const entry of readdirSync(directory)) {
            const path = join(directory, entry)

            if (statSync(path).isDirectory()) walk(path)
            else if (entry.endsWith('.php')) found.push(relative(ROOT, path))
        }
    }

    for (const root of CODE_ROOTS) {
        try {
            walk(join(ROOT, root))
        } catch {
            // A root that does not exist yet is not a failure.
        }
    }

    return found
}

const TITLE_REFERENCE = /@see\s+((?:docs\/)?[\w./-]+\.md)\s+—\s+"([^"]+)"/g
const ANCHOR_REFERENCE = /((?:docs\/)?[\w./-]+\.md)#([\w-]+)/g

const problems = []

for (const file of codeFiles()) {
    const contents = readFileSync(join(ROOT, file), 'utf-8')
    const lines = contents.split('\n')

    lines.forEach((line, index) => {
        for (const [, document, title] of line.matchAll(TITLE_REFERENCE)) {
            const known = documents.get(document)

            if (!known) {
                problems.push(`${file}:${index + 1}  no such document: ${document}`)
                continue
            }

            if (!known.titles.has(title.replace(/[`*_]/g, '').trim())) {
                problems.push(`${file}:${index + 1}  ${document} has no "${title}"`)
            }
        }

        for (const [, document, anchor] of line.matchAll(ANCHOR_REFERENCE)) {
            const known = documents.get(document)

            if (known && !known.anchors.has(anchor)) {
                problems.push(`${file}:${index + 1}  ${document} has no heading producing #${anchor}`)
            }
        }
    })
}

if (problems.length > 0) {
    console.error('These references name a heading no document produces:')
    for (const problem of problems) console.error(`  ${problem}`)
    console.error('\nEither the heading was renamed and the reference was not, or the title is a typo.')
    process.exit(1)
}

console.log(`Every \`@see\` names a heading that exists, across ${codeFiles().length} files.`)
