/*
 * Print a card, then the lot card it belongs to.
 *
 * A card does not name its lot card: the board's `Lot` field says which lot a
 * card is in, and the one item of `Kind` `Lot` carrying the same `Lot` is its
 * lot card. `AGENTS.md` asks an agent to read both before coding, and this is
 * the command it points at rather than a query copied into prose.
 *
 * Four outcomes, as `AGENTS.md` lists them under "Before coding a card, read
 * its lot card":
 *
 *   - The card has no `Lot` and its `Phase` is a gate or the breaking-change
 *     set, which belong to no lot by design: the card is printed alone, and
 *     that is enough.
 *   - Exactly one lot card carries its `Lot`: both are printed, card first.
 *   - The number is a lot card itself: it is printed alone, since it is the lot.
 *   - Anything else stops: no `Lot` outside a gate or the breaking-change set,
 *     or zero lot cards, or more than one. Nothing is printed and the exit code
 *     is non-zero. Guessing which lot card applies, or that none does, is the
 *     one thing this must never do.
 *
 * Reads the board through `gh`, since a project field needs the `project`
 * scope and `gh` is where a local session already has it. Read-only always.
 *
 * Usage: node scripts/card-context.mjs <card number>
 */

import { execFileSync } from 'node:child_process'

const OWNER = 'Gcob'
const PROJECT = '1'

// The phases whose cards belong to no lot by design. A card with no `Lot` in
// any other phase is one nobody has placed yet, not one that needs no lot card.
const LOTLESS_PHASES = ['Gate 0.x', 'Gate 1.0', 'BC enforcement']

// `gh project item-list` returns 30 items unless told otherwise, and says
// nothing when it stops. The ceiling is raised, and checked below against the
// total the board reports, so that a board outgrowing it fails loudly.
const LIMIT = 500

function fail(message) {
    console.error(message)
    console.error('\nStop here and hand the card back. See AGENTS.md, "Before coding a card, read its lot card".')
    process.exit(1)
}

const argument = process.argv[2] ?? ''

if (!/^\d+$/.test(argument)) {
    console.error('Usage: just card-context <card number>, as in `just card-context 36`.')
    process.exit(2)
}

const number = Number(argument)

let board

try {
    board = JSON.parse(
        execFileSync(
            'gh',
            ['project', 'item-list', PROJECT, '--owner', OWNER, '--format', 'json', '--limit', String(LIMIT)],
            { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] }
        )
    )
} catch (error) {
    fail(`Could not read the board through gh: ${(error.stderr || error.message).trim()}`)
}

if (board.items.length !== board.totalCount) {
    fail(`The board holds ${board.totalCount} items and only ${board.items.length} came back. Raise LIMIT.`)
}

const print = (heading, item) => {
    console.log(`# ${heading} #${item.content.number}: ${item.content.title}\n`)
    console.log(`${item.content.body.trim()}\n`)
}

const card = board.items.find((item) => item.content.type === 'Issue' && item.content.number === number)

if (!card) {
    fail(`#${number} is not an issue on the board.`)
}

if (card.kind === 'Lot') {
    print('Lot card', card)
    process.exit(0)
}

if (!card.lot) {
    if (!LOTLESS_PHASES.includes(card.phase)) {
        fail(`#${number} has no Lot, and its Phase, ${card.phase ?? 'none'}, is not one that belongs to no lot by design.`)
    }

    print('Card', card)
    console.log(`This card is in ${card.phase}, which belongs to no lot, so the card is enough.`)
    process.exit(0)
}

const lotCards = board.items.filter(
    (item) => item.content.type === 'Issue' && item.kind === 'Lot' && item.lot === card.lot
)

if (lotCards.length !== 1) {
    const found = lotCards.map((item) => `#${item.content.number}`).join(', ')

    fail(
        lotCards.length === 0
            ? `#${number} is in ${card.lot}, and no item of Kind Lot carries ${card.lot}.`
            : `#${number} is in ${card.lot}, and ${lotCards.length} items of Kind Lot carry it: ${found}.`
    )
}

print('Card', card)
print('Lot card', lotCards[0])
