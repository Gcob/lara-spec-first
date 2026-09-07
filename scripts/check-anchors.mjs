// Report every same-page link in the built site that points at an anchor no
// heading produces.
//
// VitePress fails a build on a dead link to another file, which is the check
// `docs:build` exists for. It does not look at the fragment: a link to an anchor
// on the page it is written in is invisible to it, because the source file it
// resolves against is right there. Fourteen of those had accumulated before
// anyone thought to look, all from one cause, now fixed at the source by the
// `markdown.anchor.slugify` override in .vitepress/config.mts.
//
// What remains is the ordinary way one appears: a heading renamed while a link
// to it was not. This is what turns that into a red check.
//
// It reads the built site rather than the Markdown, so it compares the ids that
// were actually emitted against the hrefs that were actually written, and needs
// no second implementation of the slug rule to disagree with the first.
//
// Usage: node scripts/check-anchors.mjs [dist directory]

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

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

const findings = []

for (const file of files) {
    const html = readFileSync(file, 'utf8')

    const ids = new Set([...html.matchAll(/id="([^"]+)"/g)].map((match) => match[1]))
    const hrefs = new Set([...html.matchAll(/href="#([^"]+)"/g)].map((match) => match[1]))

    for (const href of [...hrefs].sort()) {
        if (!ids.has(href)) {
            findings.push({ page: file.slice(DIST.length + 1), anchor: href })
        }
    }
}

if (findings.length === 0) {
    console.log(`Every same-page anchor resolves, across ${files.length} pages.`)
    process.exit(0)
}

console.error('These links point at an anchor no heading on the page produces:')
for (const { page, anchor } of findings) {
    console.error(`  ${page}  #${anchor}`)
}
console.error('\nEither the heading was renamed and the link was not, or the anchor is a typo.')
process.exit(1)
