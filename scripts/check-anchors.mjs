// Report every link in the built site that points at an anchor no heading
// produces, whether the target is the page the link sits on or another one.
//
// VitePress fails a build on a dead link to another file, which is the check
// `docs:build` exists for. It never looks at the fragment. A link to
// `openapi-support.md#the-four-rules` therefore passes as long as the file is
// there, however the heading has been renamed since.
//
// Fourteen dead same-page anchors had accumulated before anyone looked, all from
// one cause, now fixed at the source by the `markdown.anchor.slugify` override in
// .vitepress/config.mts. What that override cannot prevent is a heading renamed
// while a link to it was not, and splitting a document turns every one of its
// same-page anchors into a cross-page one. This is the check that makes both
// loud.
//
// It reads the built site rather than the Markdown, so it compares the ids that
// were actually emitted against the hrefs that were actually written, and needs
// no second implementation of the slug rule to disagree with the first.
//
// Usage: node scripts/check-anchors.mjs [dist directory]

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const DIST = process.argv[2] ?? '.vitepress/dist'

function htmlFilesUnder(directory) {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry)

        if (statSync(path).isDirectory()) {
            return htmlFilesUnder(path)
        }

        return path.endsWith('.html') ? [path] : []
    })
}

let files
try {
    files = htmlFilesUnder(DIST)
} catch {
    console.error(`No built site at ${DIST}. Run 'npm run docs:build' first.`)
    process.exit(1)
}

/** Every page of the site, keyed by the path it answers on, holding the ids it emits. */
const pages = new Map()

for (const file of files) {
    const route = '/' + relative(DIST, file).replace(/\.html$/, '').replaceAll('\\', '/')
    const ids = new Set([...readFileSync(file, 'utf8').matchAll(/id="([^"]+)"/g)].map((m) => m[1]))

    pages.set(route, ids)

    // A directory index answers on the directory itself as well, which is the
    // form a link to it takes.
    if (route.endsWith('/index')) {
        pages.set(route.slice(0, -'/index'.length) || '/', ids)
    }
}

// The site is published under a base path, so an absolute href carries a prefix
// the dist tree does not. Rather than reading the base out of the config and
// having two places state it, drop leading segments until the rest names a page.
function pageFor(path) {
    const parts = path.replace(/\/$/, '').split('/').filter(Boolean)

    for (let start = 0; start <= parts.length; start++) {
        const candidate = '/' + parts.slice(start).join('/')

        if (pages.has(candidate)) {
            return candidate
        }
    }

    return null
}

const findings = []

for (const file of files) {
    const route = '/' + relative(DIST, file).replace(/\.html$/, '').replaceAll('\\', '/')
    const html = readFileSync(file, 'utf8')
    const page = relative(DIST, file)

    for (const [, href] of html.matchAll(/href="([^"]*#[^"]+)"/g)) {
        // Only the site's own pages. An external URL carrying a fragment is not
        // something this repository can check.
        if (/^[a-z][a-z\d+\-.]*:/i.test(href)) {
            continue
        }

        const resolved = new URL(href, 'https://site' + route)
        const anchor = decodeURIComponent(resolved.hash.slice(1))
        const target = pageFor(resolved.pathname)

        // A path naming no built page is either an asset or a link VitePress has
        // already failed the build over. Not this check's business.
        if (target === null || pages.get(target).has(anchor)) {
            continue
        }

        findings.push({ page, target, anchor })
    }
}

if (findings.length === 0) {
    console.log(`Every anchor resolves, across ${new Set(files).size} pages.`)
    process.exit(0)
}

console.error('These links point at an anchor no heading produces:')
for (const { page, target, anchor } of findings.sort((a, b) => a.page.localeCompare(b.page))) {
    console.error(`  ${page}  ->  ${target}#${anchor}`)
}
console.error('\nEither the heading was renamed and the link was not, or the anchor is a typo.')
process.exit(1)
