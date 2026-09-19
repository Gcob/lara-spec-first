/*
 * Every deferred-work marker names the card that removes it.
 *
 * Three places defer work: an `**Open (#67):**` in a document, a `TODO (#34)`
 * in the code, and a card on the board. The number is what ties them together,
 * and what lets a marker expire instead of rotting. `CONTRIBUTING.md` states
 * the rule, under "Deferred work leaves a marker"; this is what enforces it.
 *
 * Two halves, failing for different reasons:
 *
 *   - Offline, through `just check-markers`: every marker carries a number, and
 *     a marker in something this package writes into a consumer's project
 *     carries more than the number. No network, so it runs everywhere. NOT part
 *     of `composer check`, which runs in a container with no Node, the same
 *     reason `format:md` is not part of it either.
 *   - Online, with `--online` and a token, in CI: every number names an issue
 *     that is still open. A marker outliving its card is the drift nobody sees.
 *
 * The reverse direction — every open `Decision` card is named by at least one
 * marker — is NOT checked. `Decision` is a project-board field, and reading it
 * needs the `project` scope, which CI's `GITHUB_TOKEN` does not carry. Giving
 * those issues a `decision` label would put it within reach of the Issues API.
 *
 * What counts as a `TODO`: the word followed by `:` or `(`. The word used in
 * prose, as `config/lara-spec-first.php` does when it explains its own DONE /
 * STARTED / TODO notation, is not a marker and is not flagged. A `// TODO: fix
 * later` is, which is the case worth catching.
 *
 * Dependency-free, and reads the sources rather than any build output.
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const ROOT = process.cwd()
const ROOTS = ['docs', 'src', 'tests', 'config', 'workbench', 'scripts']

// What `vendor:publish` copies into a consumer's project. A marker here is read
// by somebody who has never seen this board, so the number alone is not enough.
const CONSUMER_FACING = [/^config\//]

function sources() {
    const found = []

    const walk = (directory) => {
        for (const entry of readdirSync(directory)) {
            if (entry === 'node_modules' || entry === 'vendor' || entry.startsWith('.')) continue

            const path = join(directory, entry)

            if (statSync(path).isDirectory()) walk(path)
            else if (/\.(md|php)$/.test(entry)) found.push(relative(ROOT, path))
        }
    }

    for (const root of ROOTS) walk(join(ROOT, root))

    // CONTRIBUTING.md and AGENTS.md are deliberately out of scope: they describe
    // the convention, so every marker in them is an example rather than a marker.
    for (const name of ['README.md', 'CHANGELOG.md']) {
        if (existsSync(join(ROOT, name))) found.push(name)
    }

    return found
}

const OPEN_BARE = /(?:\*\*Open:\*\*|^#{2,4} Open:)/
// The number may be written plain or as a link, since a rendered document makes
// it one click and a source file does not.
const OPEN_NUMBERED = /(?:\*\*Open \(\[?#(\d+)\]?|^#{2,4} Open \(\[?#(\d+)\]?)/
// A marker is parenthesised, which is what `CONTRIBUTING.md` writes. That single
// requirement is what separates `TODO (phase 2, #50)` from `TODO: support RFC
// #7807`, where the number belongs to a sentence rather than to the marker. The
// word in prose, as `config/lara-spec-first.php` uses it to explain its own
// notation, is followed by neither and is not a marker.
const TODO_MARKER = /TODO\s*(?:\(([^)]*)\)|(:))/g

const problems = []
const cards = new Set()
const files = sources()

for (const file of files) {
    const consumerFacing = CONSUMER_FACING.some((pattern) => pattern.test(file))

    readFileSync(join(ROOT, file), 'utf8')
        .split('\n')
        .forEach((line, index) => {
            const at = `${file}:${index + 1}`

            const numbered = line.match(OPEN_NUMBERED)
            if (numbered) cards.add(Number(numbered[1] ?? numbered[2]))
            else if (OPEN_BARE.test(line)) problems.push(`${at}  an \`Open\` marker with no card`)

            for (const [, carried, colon] of line.matchAll(TODO_MARKER)) {
                if (colon) {
                    problems.push(`${at}  a \`TODO:\` with no card. Markers are written \`TODO (#34)\``)
                    continue
                }

                const card = carried.match(/#(\d+)/)

                if (!card) {
                    problems.push(`${at}  a \`TODO\` with no card`)
                    continue
                }

                cards.add(Number(card[1]))

                // The rule is about audience, not about one spelling: whatever the
                // marker carries has to say more than the number.
                if (consumerFacing && carried.replace(/#\d+/, '').replace(/[\s,]/g, '') === '') {
                    problems.push(
                        `${at}  a consumer-facing \`TODO\` carrying only a card number. ` +
                            'Name what they can act on first, as `TODO (phase 2, #50)`'
                    )
                }
            }
        })
}

if (problems.length > 0) {
    console.error('These markers do not name the card that removes them:')
    for (const problem of problems) console.error(`  ${problem}`)
    console.error('\nSee CONTRIBUTING.md — "Deferred work leaves a marker".')
    process.exit(1)
}

if (process.argv.includes('--online')) {
    const token = process.env.GITHUB_TOKEN ?? process.env.GH_TOKEN

    if (!token) {
        console.error('`--online` needs GITHUB_TOKEN or GH_TOKEN in the environment.')
        process.exit(1)
    }

    // One request for every card, rather than one request per card. GraphQL
    // aliases make that a single round trip, which is also a single place to
    // fail: thirteen sequential fetches had thirteen ways to half-succeed.
    //
    // `issueOrPullRequest` rather than `issue`, because the two share one
    // numbering. A marker naming a pull request is still wrong, but "#72 is a
    // pull request" is a diagnosis and "#72 does not exist" is a riddle.
    const numbers = [...cards].sort((a, b) => a - b)

    if (numbers.length === 0) {
        console.log('No marker to resolve.')
        process.exit(0)
    }

    const aliases = numbers
        .map(
            (card) => `c${card}: issueOrPullRequest(number: ${card}) {
                __typename
                ... on Issue { state title }
                ... on PullRequest { state title }
            }`
        )
        .join('\n')

    const [owner, name] = (process.env.GITHUB_REPOSITORY ?? 'Gcob/lara-spec-first').split('/')

    const response = await fetch('https://api.github.com/graphql', {
        method: 'POST',
        headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
        body: JSON.stringify({
            query: `query { repository(owner: "${owner}", name: "${name}") { ${aliases} } }`,
        }),
    })

    if (!response.ok) {
        console.error(`GitHub answered ${response.status}.`)
        process.exit(1)
    }

    const body = await response.json()

    // A GraphQL error arrives with HTTP 200, so the status above proves nothing
    // on its own. A number that resolves to nothing is one of these, and it is a
    // finding rather than a breakdown: the field comes back null, the rest of the
    // query still answers, and reporting it alongside the others beats letting one
    // typo hide a card that closed.
    const repository = body.data?.repository
    const unexpected = (body.errors ?? []).filter((error) => !/Could not resolve to an/i.test(error.message))

    if (unexpected.length > 0 || !repository) {
        console.error('GitHub refused the query:')

        const reported = unexpected.length > 0 ? unexpected : (body.errors ?? [])

        if (reported.length > 0) for (const error of reported) console.error(`  ${error.message}`)
        else console.error(`  HTTP ${response.status}, and no repository in the answer for ${owner}/${name}.`)

        process.exit(1)
    }
    const stale = []

    for (const card of numbers) {
        const found = repository?.[`c${card}`]

        if (!found) {
            stale.push(`#${card} does not exist`)
        } else if (found.__typename === 'PullRequest') {
            stale.push(`#${card} is a pull request, not a card: ${found.title}`)
        } else if (found.state === 'CLOSED') {
            stale.push(`#${card} is closed: ${found.title}`)
        }
    }

    if (stale.length > 0) {
        console.error('These markers outlived their card:')
        for (const line of stale) console.error(`  ${line}`)
        console.error('\nThe marker and its card die in the same commit.')
        process.exit(1)
    }

    console.log(`Every marker names an open card, across ${cards.size} cards.`)
    process.exit(0)
}

console.log(`Every marker names a card, across ${files.length} files and ${cards.size} cards.`)
