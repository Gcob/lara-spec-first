<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * `just status`, run the way a maintainer runs it: `php scripts/status.php`, on the
 * host, from inside a checkout.
 *
 * Nothing here reaches GitHub. Each test builds a scratch repository whose `origin`
 * carries the real GitHub address and is sent to a bare repository on disk, and puts
 * a fake `gh` first on `PATH`. The fake answers each of the script's reads from a
 * file the test writes (the shape `gh api --paginate --slurp` hands back: an array of
 * pages) and records every call it gets, query text included, so a test can say what
 * the script asked and what it did not. The same arrangement as ReleaseRecipesTest.
 *
 * A test states only the cards it is about, through `statusCard()`: the board item
 * and the issue are both derived from it, and everything else is a clean default.
 *
 * @see docs/contributing/card-management.md — "The board"
 */

const STATUS_SLUG = 'Gcob/lara-spec-first';

/** The body of a card that is ready to be `Todo`: nothing left open, and a `Done when`. */
const STATUS_COMPLETE_BODY = "## Decisions\n\nSettled.\n\n## Done when\n\n- [ ] It works.\n";

const STATUS_FAKE_GH = <<<'SH'
#!/bin/sh
# Records the call, then answers from the files the test wrote. A file `fail-<route>`
# makes that route exit 1. Anything it was not taught is an error, so a script that
# asks GitHub something new fails loudly.
dir="$FAKE_GH_DIR"
mkdir -p "$dir/calls"
n=$(( $(ls "$dir/calls" | wc -l) + 1 ))
for argument in "$@"; do
    printf '%s\n---ARG---\n' "$argument"
done > "$dir/calls/$(printf '%04d' "$n")"

all="$*"
route=""

case "$1" in
    repo) route=slug ;;
    api)
        case "$2" in
            graphql)
                case "$all" in
                    *projectV2*) route=board ;;
                    *"issues("*) route=issues ;;
                    *"pullRequests("*)
                        case "$all" in
                            *MERGED*) route=prs-merged ;;
                            *OPEN*) route=prs-open ;;
                        esac
                        ;;
                esac
                ;;
            *issues/comments*) route=comments ;;
            *milestones*) route=milestones ;;
            *check-runs*) route=main-ci ;;
            *commits/main/status*) route=main-status ;;
        esac
        ;;
esac

if [ -z "$route" ]; then
    echo "fake gh: no answer for: $*" >&2
    exit 99
fi

if [ -e "$dir/fail-$route" ]; then
    echo "fake gh: $route failed" >&2
    exit 1
fi

if [ "$route" = slug ]; then
    cat "$dir/slug"
else
    cat "$dir/$route.json"
fi
SH;

/**
 * The scratch directories of this file, removed by the hook below.
 *
 * @return array<int, string>
 */
function statusScratchDirectories(?string $created = null, bool $forget = false): array
{
    static $directories = [];

    if ($created !== null) {
        $directories[] = $created;
    }

    $held = $directories;

    if ($forget) {
        $directories = [];
    }

    return $held;
}

afterEach(function (): void {
    foreach (statusScratchDirectories(forget: true) as $directory) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
});

/**
 * A card, as the board and the issue list both see it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{number: int, title: string, status: string, kind: string, order: int|float|null, state: string, stateReason: string|null, milestone: string|null, closedAt: string|null, labels: list<string>, labelsNext: bool, onBoard: bool, body: string}
 */
function statusCard(array $overrides): array
{
    $number = $overrides['number'];

    /** @var array{number: int, title: string, status: string, kind: string, order: int|float|null, state: string, stateReason: string|null, milestone: string|null, closedAt: string|null, labels: list<string>, labelsNext: bool, onBoard: bool, body: string} */
    return array_merge([
        'number' => $number,
        'title' => 'Card '.$number,
        'status' => 'Todo',
        'kind' => 'Feature',
        'order' => null,
        'state' => 'OPEN',
        'stateReason' => null,
        // A closed card was closed before the release tags the tests write (`statusTags()`), unless a test says otherwise.
        'closedAt' => ($overrides['state'] ?? 'OPEN') === 'CLOSED' ? '2026-11-10T12:00:00Z' : null,
        // A card past Backlog is planned, so it starts on `1.0`, a release like any other (M1): a test that is about a missing milestone says `null`.
        'milestone' => in_array($overrides['status'] ?? 'Todo', ['Todo', 'In Progress', 'In review', 'Ready to publish'], true) ? '1.0' : null,
        'labels' => [],
        'labelsNext' => false,
        'onBoard' => true,
        'body' => STATUS_COMPLETE_BODY,
    ], $overrides);
}

/**
 * An open pull request, with the check contexts of its last commit.
 *
 * A context with a `context` key is a commit status from an outside CI; any other is a check run.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{number: int, branch: string, draft: bool, closes: list<int>, checks: list<array<string, mixed>>}
 */
function statusPr(array $overrides): array
{
    /** @var array{number: int, branch: string, draft: bool, closes: list<int>, checks: list<array<string, mixed>>} */
    return array_merge(['branch' => 'wip-thing', 'draft' => false, 'closes' => [], 'checks' => []], $overrides);
}

/**
 * A milestone, as the REST list gives it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function statusMilestone(array $overrides): array
{
    return array_merge(['state' => 'open', 'open_issues' => 0, 'closed_issues' => 0], $overrides);
}

/**
 * A merged pull request: the cards it closes and the branch it came from.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{number: int, branch: string, closes: list<int>}
 */
function statusMerged(array $overrides): array
{
    /** @var array{number: int, branch: string, closes: list<int>} */
    return array_merge(['branch' => 'merged-thing', 'closes' => []], $overrides);
}

/**
 * @param  int|array{number: int, branch: string, closes: list<int>}  $merged  a bare number is a pull request with no
 *                                                                             card
 * @return array<string, mixed>
 */
function statusMergedNode(int|array $merged): array
{
    $merged = is_int($merged) ? statusMerged(['number' => $merged]) : $merged;

    return [
        'number' => $merged['number'],
        'headRefName' => $merged['branch'],
        'mergedAt' => '2026-11-01T12:00:00Z',
        'closingIssuesReferences' => [
            'pageInfo' => ['hasNextPage' => false],
            'nodes' => array_map(static fn (int $number): array => ['number' => $number], $merged['closes']),
        ],
    ];
}

/**
 * An issue comment, as the REST list gives it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function statusComment(array $overrides): array
{
    return [
        'issue_url' => 'https://api.github.com/repos/'.STATUS_SLUG.'/issues/'.$overrides['issue'],
        'body' => $overrides['body'] ?? 'A comment.',
        'author_association' => $overrides['association'] ?? 'OWNER',
        'user' => ['login' => 'Gcob'],
        'created_at' => '2026-11-15T12:00:00Z',
    ];
}

/**
 * One check run of `main`, as the REST list gives it.
 *
 * @return array<string, string|null>
 */
function statusRun(string $name, string $status = 'completed', ?string $conclusion = 'success'): array
{
    return ['name' => $name, 'status' => $status, 'conclusion' => $conclusion];
}

/**
 * A list that comes back in several pages: the first argument is page one, and so on.
 *
 * @param  list<mixed>  ...$pages
 * @return array{pages: list<list<mixed>>}
 */
function statusPages(array ...$pages): array
{
    return ['pages' => array_values($pages)];
}

/**
 * The pages of a list: the several `statusPages()` carries, or the one page a plain list is. An empty list is one empty
 * page, as `gh api --paginate --slurp` hands it back.
 *
 * @param  array<mixed>  $list
 * @return list<list<mixed>>
 */
function statusSplit(array $list): array
{
    if (isset($list['pages']) && is_array($list['pages'])) {
        return array_values(array_map(static fn (mixed $page): array => array_values((array) $page), $list['pages']));
    }

    return [array_values($list)];
}

/**
 * @param  array{number: int, title: string, status: string, kind: string, order: int|float|null, state: string, stateReason: string|null, milestone: string|null, closedAt: string|null, labels: list<string>, labelsNext: bool, onBoard: bool, body: string, repository?: string}  $card
 * @return array<string, mixed>
 */
function statusBoardItem(array $card): array
{
    $values = [
        ['__typename' => 'ProjectV2ItemFieldSingleSelectValue', 'name' => $card['status'], 'field' => ['name' => 'Status']],
        ['__typename' => 'ProjectV2ItemFieldSingleSelectValue', 'name' => $card['kind'], 'field' => ['name' => 'Kind']],
    ];

    if ($card['order'] !== null) {
        $values[] = ['__typename' => 'ProjectV2ItemFieldNumberValue', 'number' => $card['order'], 'field' => ['name' => 'Order']];
    }

    return [
        'content' => ['__typename' => 'Issue', 'number' => $card['number'], 'body' => $card['body'], 'repository' => ['nameWithOwner' => $card['repository'] ?? STATUS_SLUG]],
        'fieldValues' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => $values],
    ];
}

/**
 * @param  array{number: int, title: string, status: string, kind: string, order: int|float|null, state: string, stateReason: string|null, milestone: string|null, closedAt: string|null, labels: list<string>, labelsNext: bool, onBoard: bool, body: string}  $card
 * @return array<string, mixed>
 */
function statusIssueNode(array $card): array
{
    return [
        'number' => $card['number'],
        'title' => $card['title'],
        'state' => $card['state'],
        'stateReason' => $card['stateReason'],
        'closedAt' => $card['closedAt'],
        'milestone' => $card['milestone'] === null ? null : ['title' => $card['milestone']],
        'labels' => [
            'pageInfo' => ['hasNextPage' => $card['labelsNext']],
            'nodes' => array_map(static fn (string $name): array => ['name' => $name], $card['labels']),
        ],
    ];
}

/**
 * @param  array{number: int, branch: string, draft: bool, closes: list<int>, checks: list<array<string, mixed>>}  $pr
 * @return array<string, mixed>
 */
function statusPrNode(array $pr): array
{
    $contexts = array_map(
        static fn (array $check): array => isset($check['context'])
            ? ['__typename' => 'StatusContext', 'context' => $check['context'], 'state' => $check['state']]
            : ['__typename' => 'CheckRun', 'name' => $check['name'], 'status' => $check['status'] ?? 'COMPLETED', 'conclusion' => $check['conclusion'] ?? null],
        $pr['checks'],
    );

    return [
        'number' => $pr['number'],
        'headRefName' => $pr['branch'],
        'isDraft' => $pr['draft'],
        'closingIssuesReferences' => [
            'pageInfo' => ['hasNextPage' => false],
            'nodes' => array_map(static fn (int $number): array => ['number' => $number], $pr['closes']),
        ],
        'commits' => ['nodes' => [[
            'commit' => ['statusCheckRollup' => $contexts === [] ? null : ['contexts' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => $contexts]]],
        ]]],
    ];
}

/**
 * Wrap a list of nodes as the pages of a GraphQL answer.
 *
 * @param  list<list<mixed>>  $pages
 * @param  list<string>  $path  where the paged connection sits in the answer
 * @return list<array<string, mixed>>
 */
function statusGraphqlPages(array $pages, array $path): array
{
    $answers = [];

    foreach ($pages as $index => $nodes) {
        $more = $index < count($pages) - 1;
        $connection = ['pageInfo' => ['hasNextPage' => $more, 'endCursor' => $more ? 'cursor-'.$index : null], 'nodes' => $nodes];

        foreach (array_reverse($path) as $key) {
            $connection = [$key => $connection];
        }

        $answers[] = ['data' => $connection];
    }

    return $answers;
}

/**
 * A repository with a bare `origin`, a fake `gh`, and a clean board: no card, no pull request, `main` green.
 *
 * Each key of `$data` is one list the fake answers with, as a plain list (one page) or as `statusPages()`: `cards` (a
 * card with `onBoard` false is an issue the board does not have), `prs`, `merged` (a pull request number, or a
 * `statusMerged()`), `milestones`, `comments`, `ci` (the check runs of `main`) and `slug` (what `gh repo view`
 * answers).
 *
 * @param  array<string, mixed>  $data
 * @return array{dir: string, work: string, origin: string, bin: string, state: string}
 */
function statusProject(array $data = []): array
{
    $dir = realpath(sys_get_temp_dir()).'/lsf-status-'.bin2hex(random_bytes(6));
    $project = ['dir' => $dir, 'work' => $dir.'/work', 'origin' => $dir.'/origin.git', 'bin' => $dir.'/bin', 'state' => $dir.'/state'];

    foreach ([$dir, $project['work'], $project['origin'], $project['bin'], $project['state']] as $path) {
        is_dir($path) || mkdir($path);
    }

    statusScratchDirectories($dir);

    statusGit($project['origin'], ['init', '--quiet', '--bare', '--initial-branch=main']);
    statusGit($project['work'], ['init', '--quiet', '--initial-branch=main']);
    statusGit($project['work'], ['config', 'user.name', 'Test']);
    statusGit($project['work'], ['config', 'user.email', 'test@example.com']);

    mkdir($project['work'].'/scripts');
    copy(statusRepository().'/scripts/status.php', $project['work'].'/scripts/status.php');
    chmod($project['work'].'/scripts/status.php', 0755);

    statusGit($project['work'], ['add', '.']);
    statusGit($project['work'], ['commit', '--quiet', '-m', 'chore: start']);
    // The remote carries the address GitHub gives it, and git sends it to the bare repository on disk. The script reads the
    // address from the configuration, as it would in a checkout, and everything it fetches comes from the disk.
    statusGit($project['work'], ['remote', 'add', 'origin', 'https://github.com/'.STATUS_SLUG.'.git']);
    statusGit($project['work'], ['config', 'url.'.$project['origin'].'.insteadOf', 'https://github.com/'.STATUS_SLUG.'.git']);
    statusGit($project['work'], ['push', '--quiet', '--set-upstream', 'origin', 'main']);

    file_put_contents($project['bin'].'/gh', STATUS_FAKE_GH);
    chmod($project['bin'].'/gh', 0755);

    $listOf = static fn (string $key): array => (array) ($data[$key] ?? []);

    /** @var list<list<array{number: int, title: string, status: string, kind: string, order: int|float|null, state: string, stateReason: string|null, milestone: string|null, closedAt: string|null, labels: list<string>, labelsNext: bool, onBoard: bool, body: string}>> $cardPages */
    $cardPages = statusSplit($listOf('cards'));

    /** @var list<list<array{number: int, branch: string, draft: bool, closes: list<int>, checks: list<array<string, mixed>>}>> $prPages */
    $prPages = statusSplit($listOf('prs'));
    $runs = (array) ($data['ci'] ?? [statusRun('a'), statusRun('b'), statusRun('c')]);

    $boardPages = array_map(static fn (array $cards): array => array_map('statusBoardItem', array_values(array_filter($cards, static fn (array $card): bool => $card['onBoard']))), $cardPages);

    // An item of another repository that sits on the same board, which has nothing to do with this one's cards.
    foreach ($listOf('foreign') as $foreign) {
        $boardPages[0][] = statusBoardItem(array_merge(statusCard($foreign), ['repository' => 'Someone/else']));
    }

    foreach ($listOf('tags') as $tag => $date) {
        statusPushTag($project, (string) $tag, (string) $date);
    }

    // A tag only this clone has: fetching never removes a local tag, and it is not the upstream's release.
    foreach ($listOf('localTags') as $tag => $date) {
        statusPushTag($project, (string) $tag, (string) $date, localOnly: true);
    }

    $files = [
        'board.json' => statusGraphqlPages($boardPages, ['user', 'projectV2', 'items']),
        'issues.json' => statusGraphqlPages(
            array_map(static fn (array $cards): array => array_map('statusIssueNode', $cards), $cardPages),
            ['repository', 'issues'],
        ),
        'comments.json' => statusSplit($listOf('comments')),
        'prs-open.json' => statusGraphqlPages(
            array_map(static fn (array $prs): array => array_map('statusPrNode', $prs), $prPages),
            ['repository', 'pullRequests'],
        ),
        'prs-merged.json' => statusGraphqlPages(
            array_map(static fn (array $merged): array => array_map('statusMergedNode', $merged), statusSplit($listOf('merged'))),
            ['repository', 'pullRequests'],
        ),
        'milestones.json' => statusSplit($listOf('milestones')),
        'main-ci.json' => [['total_count' => count($runs), 'check_runs' => $runs]],
        'main-status.json' => [['state' => 'pending', 'statuses' => array_values($listOf('mainStatuses'))]],
    ];

    foreach ($files as $name => $content) {
        file_put_contents($project['state'].'/'.$name, json_encode($content, JSON_THROW_ON_ERROR));
    }

    file_put_contents($project['state'].'/slug', is_string($data['slug'] ?? null) ? $data['slug'] : STATUS_SLUG);

    return $project;
}

function statusRepository(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @param  list<string>  $arguments
 */
function statusGit(string $directory, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $directory, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * Put a branch on the upstream, `$ahead` commits ahead of `main`, and leave the work tree on `main` with no local copy.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 */
function statusPushBranch(array $project, string $branch, int $ahead): void
{
    statusGit($project['work'], ['checkout', '--quiet', '-b', $branch, 'main']);

    for ($commit = 0; $commit < $ahead; $commit++) {
        statusGit($project['work'], ['commit', '--quiet', '--allow-empty', '-m', 'chore: work '.$commit]);
    }

    statusGit($project['work'], ['push', '--quiet', 'origin', $branch]);
    statusGit($project['work'], ['checkout', '--quiet', 'main']);
    statusGit($project['work'], ['branch', '--quiet', '-D', $branch]);
}

/**
 * Put a tag on a new commit of the upstream's `main`, made at `$date` (the date the script reads), and leave no local
 * copy of the tag: the script has to fetch it. `$localOnly` keeps the tag, on a commit that never leaves the work tree.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 */
function statusPushTag(array $project, string $tag, string $date, bool $localOnly = false): void
{
    $environment = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_COMMITTER_DATE' => $date, 'GIT_AUTHOR_DATE' => $date];

    if ($localOnly) {
        statusGit($project['work'], ['checkout', '--quiet', '-b', 'never-pushed', 'main']);
    }

    (new Process(['git', 'commit', '--quiet', '--allow-empty', '-m', 'chore: release '.$tag], $project['work'], $environment))->mustRun();
    (new Process(['git', 'tag', '--annotate', '--message', $tag, $tag], $project['work'], $environment))->mustRun();

    if ($localOnly) {
        statusGit($project['work'], ['checkout', '--quiet', 'main']);
        statusGit($project['work'], ['branch', '--quiet', '-D', 'never-pushed']);

        return;
    }

    statusGit($project['work'], ['push', '--quiet', 'origin', 'main', 'refs/tags/'.$tag]);
    statusGit($project['work'], ['tag', '--delete', $tag]);
}

/**
 * Run the script in the work tree, with the fake `gh` first on `PATH`.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @param  list<string>  $arguments
 * @return array{exit: int, out: string, err: string}
 */
function statusRunScript(array $project, array $arguments = []): array
{
    $process = new Process(
        [PHP_BINARY, 'scripts/status.php', ...$arguments],
        $project['work'],
        [
            'PATH' => $project['bin'].':'.getenv('PATH'),
            'FAKE_GH_DIR' => $project['state'],
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ],
    );
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/**
 * Every call the script made to `gh`, each as its list of arguments.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @return list<list<string>>
 */
function statusGhCalls(array $project): array
{
    $files = glob($project['state'].'/calls/*') ?: [];
    sort($files);

    return array_map(
        static fn (string $file): array => array_slice(explode("\n---ARG---\n", (string) file_get_contents($file)), 0, -1),
        $files,
    );
}

/**
 * The lines of one section of the output: what follows its heading, up to the blank line.
 *
 * @return list<string>
 */
function statusSection(string $output, string $heading): array
{
    $lines = explode("\n", $output);
    $start = array_search($heading, $lines, true);

    if ($start === false) {
        return [];
    }

    $section = [];

    foreach (array_slice($lines, $start + 1) as $line) {
        if ($line === '') {
            break;
        }

        $section[] = $line;
    }

    return $section;
}

/**
 * What the Drift section says: its findings, one line each, and the count in its heading and in the header.
 *
 * @return array{lines: list<string>, count: int, header: int}
 */
function statusDrift(string $output): array
{
    $lines = explode("\n", $output);
    $heading = array_values(array_filter($lines, static fn (string $line): bool => preg_match('/^Drift \(\d+\)$/', $line) === 1));
    preg_match('/ · drift (\d+)$/', $lines[0], $header);

    return [
        'lines' => $heading === [] ? [] : statusSection($output, $heading[0]),
        'count' => $heading === [] ? 0 : (int) substr($heading[0], 7, -1),
        'header' => (int) ($header[1] ?? -1),
    ];
}

/**
 * What a test compares to see that a run left the repository as it found it.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @return array<string, string>
 */
function statusRepositoryState(array $project): array
{
    return [
        'head' => statusGit($project['work'], ['rev-parse', 'HEAD']),
        'branch' => statusGit($project['work'], ['branch', '--show-current']),
        'local branches' => statusGit($project['work'], ['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads']),
        'work tree' => statusGit($project['work'], ['status', '--porcelain']),
        'origin branches' => statusGit($project['origin'], ['for-each-ref', '--format=%(refname) %(objectname)']),
    ];
}

it('refuses an argument', function (): void {
    $project = statusProject();

    $run = statusRunScript($project, ['--all']);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('usage: php scripts/status.php')
        ->and(statusGhCalls($project))->toBe([]);
});

it('stops with exit 2 when GitHub cannot be read, or the sources are not this repository', function (): void {
    $unreadable = statusProject();
    touch($unreadable['state'].'/fail-board');
    $run = statusRunScript($unreadable);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('gh could not read');

    $fork = statusProject(['slug' => 'someone/lara-spec-first']);
    $run = statusRunScript($fork);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('gh repo set-default Gcob/lara-spec-first')
        ->and(statusGhCalls($fork))->toHaveCount(1);

    $stranger = statusProject();
    statusGit($stranger['work'], ['remote', 'set-url', 'origin', 'https://github.com/someone/lara-spec-first.git']);
    $run = statusRunScript($stranger);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('git remote add upstream https://github.com/Gcob/lara-spec-first.git');

    $crowded = statusProject(['cards' => [statusCard(['number' => 7, 'labelsNext' => true])]]);
    $run = statusRunScript($crowded);

    expect($run['exit'])->toBe(2)
        ->and($run['out'])->toBe('')
        ->and($run['err'])->toContain('labels');
});

it('prints the cards in flight with their branch, pull request and CI', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 10, 'title' => 'Alpha', 'status' => 'In Progress']),
            statusCard(['number' => 11, 'title' => 'Beta', 'status' => 'In review']),
            statusCard(['number' => 12, 'title' => 'Gamma', 'status' => 'In review']),
            statusCard(['number' => 13, 'title' => 'Delta', 'status' => 'In review']),
            statusCard(['number' => 14, 'title' => 'Epsilon', 'status' => 'In review']),
            statusCard(['number' => 15, 'title' => 'Zeta', 'status' => 'In review']),
            statusCard(['number' => 16, 'title' => 'Eta', 'status' => 'In review']),
            statusCard(['number' => 18, 'title' => 'Iota', 'status' => 'In Progress']),
            statusCard(['number' => 99, 'title' => 'A lot', 'status' => 'In Progress', 'kind' => 'Lot']),
        ],
        'prs' => [
            statusPr(['number' => 21, 'branch' => 'feature/11/beta', 'checks' => [
                ['name' => 'lint', 'conclusion' => 'SUCCESS'],
                ['context' => 'ci/outside', 'state' => 'SUCCESS'],
            ]]),
            statusPr(['number' => 22, 'branch' => 'feature/12/gamma', 'checks' => [
                ['name' => 'lint', 'conclusion' => 'SUCCESS'],
                ['name' => 'tests (8.3)', 'conclusion' => 'FAILURE'],
                ['context' => 'ci/outside', 'state' => 'ERROR'],
            ]]),
            statusPr(['number' => 23, 'branch' => 'feature/13/delta', 'checks' => [
                ['name' => 'tests (8.3)', 'status' => 'IN_PROGRESS'],
                ['context' => 'ci/slow', 'state' => 'PENDING'],
            ]]),
            statusPr(['number' => 24, 'branch' => 'feature/14/epsilon']),
            statusPr(['number' => 25, 'branch' => 'feature/15/zeta', 'draft' => true, 'checks' => [
                ['name' => 'lint', 'conclusion' => 'SUCCESS'],
            ]]),
            statusPr(['number' => 26, 'branch' => 'wip-thing', 'closes' => [16], 'checks' => [
                ['name' => 'lint', 'conclusion' => 'SUCCESS'],
            ]]),
        ],
    ]);
    statusPushBranch($project, 'feature/10/alpha', 2);

    $run = statusRunScript($project);

    // #18 is `In Progress` with nothing on a branch: that is the one drift, and the page still prints.
    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusSection($run['out'], 'Now'))->toBe([
            '  #11 In review    Beta',
            '      PR #21 · CI green (2 checks)',
            '  #12 In review    Gamma',
            '      PR #22 · CI red: tests (8.3), ci/outside',
            '  #13 In review    Delta',
            '      PR #23 · CI running',
            '  #14 In review    Epsilon',
            '      PR #24 · CI no checks',
            '  #15 In review    Zeta',
            '      PR #25 (draft) · CI green (1 check)',
            '  #16 In review    Eta',
            '      PR #26 · CI green (1 check)',
            '  #10 In Progress  Alpha',
            '      branch feature/10/alpha, 2 commits ahead of main · no pull request',
            '  #18 In Progress  Iota',
            '      nothing on a branch · no pull request',
        ]);
});

it('lists the Todo cards by Order, then by number, with the unordered ones last', function (): void {
    $project = statusProject(['cards' => [
        statusCard(['number' => 30, 'title' => 'Fourth', 'order' => 20]),
        statusCard(['number' => 31, 'title' => 'Second', 'order' => 10, 'milestone' => '0.3.0']),
        statusCard(['number' => 32, 'title' => 'Thirds', 'order' => 10, 'milestone' => '0.3.0']),
        statusCard(['number' => 33, 'title' => 'Sixths']),
        statusCard(['number' => 34, 'title' => 'Fifths', 'order' => 30, 'milestone' => '0.3.0', 'labels' => ['waiting-on-maintainer']]),
        statusCard(['number' => 35, 'title' => 'A lot', 'kind' => 'Lot', 'order' => 5]),
        statusCard(['number' => 36, 'title' => 'Closed', 'order' => 1, 'state' => 'CLOSED']),
    ]]);

    $run = statusRunScript($project);

    // Two cards share an Order and one has none: drift, and the queue still prints.
    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '  10  #31  Second  0.3.0',
            '  10  #32  Thirds  0.3.0',
            '  20  #30  Fourth  1.0',
            '  30  #34  Fifths  0.3.0  waits on the maintainer',
            '   -  #33  Sixths  1.0',
        ]);
});

it('cuts a long title with three dots', function (): void {
    $title = 'Read a multipart part\'s default contentType for the documented encoding of a file';
    $project = statusProject(['cards' => [statusCard(['number' => 40, 'title' => $title, 'order' => 10])]]);

    $run = statusRunScript($project);

    expect(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
        '  10  #40  Read a multipart part\'s default contentType for the docum...  1.0',
    ]);
});

it('reports the next release as the open release milestone with the lowest version', function (): void {
    $project = statusProject([
        'milestones' => [
            statusMilestone(['title' => '0.10.0']),
            statusMilestone(['title' => '0.3.0']),
            statusMilestone(['title' => '1.0']),
            statusMilestone(['title' => '0.2.0', 'state' => 'closed']),
        ],
        'cards' => [
            statusCard(['number' => 40, 'status' => 'In review', 'milestone' => '0.3.0']),
            statusCard(['number' => 41, 'status' => 'In Progress', 'milestone' => '0.3.0']),
            statusCard(['number' => 42, 'status' => 'Todo', 'milestone' => '0.3.0']),
            statusCard(['number' => 43, 'status' => 'Todo', 'milestone' => '0.3.0']),
            statusCard(['number' => 44, 'status' => 'Backlog', 'milestone' => '0.3.0']),
            statusCard(['number' => 45, 'status' => 'Ready to publish', 'milestone' => '0.3.0', 'state' => 'CLOSED']),
            statusCard(['number' => 46, 'status' => 'Done', 'milestone' => '0.3.0', 'state' => 'CLOSED']),
            statusCard(['number' => 47, 'status' => 'Done', 'milestone' => '0.3.0', 'state' => 'CLOSED']),
            statusCard(['number' => 48, 'status' => 'Todo', 'milestone' => '0.10.0']),
            statusCard(['number' => 49, 'status' => 'Todo', 'milestone' => '1.0']),
        ],
    ]);

    $run = statusRunScript($project);

    // The cards are bare (no PR, no Order): drift, and the release still prints.
    expect($run['exit'])->toBe(1, $run['err'])
        ->and($run['out'])->toContain("\nRelease 0.3.0: 3 of 8 closed (1 ready to publish, 2 done)\n")
        ->and($run['out'])->not->toContain('Release 0.10.0')
        ->and(statusSection($run['out'], 'Release 0.3.0: 3 of 8 closed (1 ready to publish, 2 done)'))->toBe([
            '  In review    #40',
            '  In Progress  #41',
            '  Todo         #42, #43',
            '  Backlog      #44',
        ]);
});

it('shows 1.0 as the next release when it is the lowest open release milestone', function (): void {
    $project = statusProject([
        'milestones' => [statusMilestone(['title' => '1.0']), statusMilestone(['title' => '0.2.0', 'state' => 'closed'])],
        'cards' => [
            statusCard(['number' => 49, 'status' => 'Todo', 'order' => 10, 'milestone' => '1.0']),
            statusCard(['number' => 50, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '1.0']),
            statusCard(['number' => 51, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Decision', 'milestone' => '1.0']),
        ],
        'prs' => [],
        'merged' => [],
    ]);

    $run = statusRunScript($project);

    expect($run['out'])->toContain("\nRelease 1.0: 2 of 3 closed (1 ready to publish, 1 done)\n")
        ->and(statusSection($run['out'], 'Release 1.0: 2 of 3 closed (1 ready to publish, 1 done)'))->toBe(['  Todo  #49']);
});

it('says so when no release milestone is open', function (): void {
    $project = statusProject([
        'milestones' => [statusMilestone(['title' => '0.2.0', 'state' => 'closed'])],
        'cards' => [statusCard(['number' => 49, 'status' => 'Backlog'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Release'))->toBe(['  No release milestone is open.']);
});

it('reads a milestone as a release only when its whole title is a version', function (string $title): void {
    $project = statusProject(['milestones' => [statusMilestone(['title' => $title])]]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Release'))->toBe(['  No release milestone is open.']);
})->with([
    'a release candidate' => '1.0-rc',
    'a pre-release of a patch' => '0.3.0-beta',
    'a label, not a number' => 'v1',
    'four numbers' => '0.3.0.1',
    'a wildcard' => '1.0.x',
    'a number alone' => '1',
    'words' => 'Backlog ideas',
]);

it('reads a milestone titled with only numbers and dots as a release', function (string $title): void {
    $project = statusProject(['milestones' => [statusMilestone(['title' => $title])]]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($run['out'])->toContain("\nRelease {$title}: 0 of 0 closed (0 ready to publish, 0 done)\n");
})->with([
    'major and minor' => '0.3',
    'major, minor and patch' => '0.3.0',
    'the first stable release' => '1.0',
    'several digits' => '12.10.4',
]);

it('prints the header, and the sections that have nothing to say', function (): void {
    $project = statusProject(['ci' => [statusRun('a'), statusRun('b')]]);
    $sha = statusGit($project['work'], ['rev-parse', '--short', 'origin/main']);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($run['err'])->toBe('')
        ->and(explode("\n", $run['out'])[0])->toBe("lara-spec-first · main {$sha} · CI green (2 checks) · drift 0")
        ->and(statusSection($run['out'], 'Now'))->toBe(['  Nothing is in flight.'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe(['  Nothing is waiting in Todo.'])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe(['  Nothing waits on the maintainer.'])
        ->and($run['out'])->toEndWith("\nDrift: none.\n");
});

it('says what main\'s CI is in the header', function (array $runs, string $expected, int $drift): void {
    $project = statusProject(['ci' => $runs]);

    $run = statusRunScript($project);

    expect(explode("\n", $run['out'])[0])->toEndWith(' · CI '.$expected.' · drift '.$drift);
})->with([
    'every run green, skipped or neutral' => [[statusRun('a'), statusRun('b', conclusion: 'skipped'), statusRun('c', conclusion: 'neutral')], 'green (3 checks)', 0],
    'a failed run' => [[statusRun('a'), statusRun('tests (8.3)', conclusion: 'failure'), statusRun('docs', conclusion: 'cancelled')], 'red: tests (8.3), docs', 1],
    'a run still going' => [[statusRun('a'), statusRun('b', 'in_progress', null)], 'running', 0],
    'a failed run and one going' => [[statusRun('tests', conclusion: 'failure'), statusRun('b', 'queued', null)], 'red: tests', 1],
    'no run' => [[], 'no checks', 0],
]);

it('asks GitHub only questions, and leaves the repository as it found it', function (): void {
    $project = statusProject(['cards' => [statusCard(['number' => 10, 'status' => 'In Progress'])]]);
    statusPushBranch($project, 'feature/10/alpha', 1);
    $before = statusRepositoryState($project);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err']);

    $calls = statusGhCalls($project);

    expect($calls)->toHaveCount(9)
        ->and($calls[0])->toBe(['repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner']);

    $reads = ['projectV2', 'issues(', 'issues/comments', 'pullRequests(states: [OPEN]', 'pullRequests(states: [MERGED]', 'milestones', 'check-runs', 'commits/main/status'];

    foreach ($reads as $index => $marker) {
        $call = $calls[$index + 1];

        expect($call[0])->toBe('api')
            ->and(implode("\n", $call))->toContain($marker)
            ->and(implode("\n", $call))->not->toContain('mutation')
            ->and(array_intersect($call, ['-X', '--method', '--input', 'POST', 'PATCH', 'PUT', 'DELETE']))->toBe([]);
    }

    expect(statusRepositoryState($project))->toBe($before);
});

it('sends paginated queries whose outer cursor comes first', function (): void {
    $project = statusProject();

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err']);

    $queries = [];

    foreach (statusGhCalls($project) as $call) {
        if (($call[1] ?? '') !== 'graphql') {
            continue;
        }

        expect($call)->toContain('--paginate')->and($call)->toContain('--slurp');

        foreach ($call as $argument) {
            if (str_starts_with($argument, 'query=')) {
                $queries[] = (string) preg_replace('/\s+/', ' ', substr($argument, 6));
            }
        }
    }

    expect($queries)->toHaveCount(4);

    foreach ($queries as $query) {
        $firstPageInfo = (int) strpos($query, 'pageInfo');

        expect($query)->toContain('after: $endCursor')
            // The paged connection's own `pageInfo` is the first one in the text, so it is the first `gh` finds in the answer.
            ->and(strpos($query, 'nodes'))->toBeGreaterThan($firstPageInfo)
            ->and(substr($query, $firstPageInfo, strlen('pageInfo { hasNextPage endCursor }')))->toBe('pageInfo { hasNextPage endCursor }')
            // No nested set can offer a second cursor.
            ->and(preg_match_all('/(?<!\$)\bendCursor\b/', $query))->toBe(1);
    }
});

it('reads every page of a list', function (): void {
    $project = statusProject([
        'cards' => statusPages(
            [statusCard(['number' => 50, 'title' => 'First page', 'order' => 10])],
            [statusCard(['number' => 51, 'title' => 'Second page', 'order' => 20, 'labels' => ['waiting-on-maintainer']]), statusCard(['number' => 52, 'title' => 'Under review', 'status' => 'In review'])],
        ),
        'prs' => statusPages(
            [statusPr(['number' => 60, 'branch' => 'feature/99/elsewhere'])],
            [statusPr(['number' => 61, 'branch' => 'feature/52/second', 'checks' => [['name' => 'lint', 'conclusion' => 'SUCCESS']]])],
        ),
        'comments' => statusPages(
            [statusComment(['issue' => 50]), statusComment(['issue' => 51, 'body' => 'A note before the question.'])],
            [statusComment(['issue' => 51, 'body' => '**Question Q4 for the maintainer:** is this on page two?'])],
        ),
        'merged' => statusPages(range(1, 100), range(101, 200), range(201, 250)),
    ]);

    $run = statusRunScript($project);

    // PR #60 serves a card the board does not have: drift, and every page still shows.
    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '  10  #50  First page   1.0',
            '  20  #51  Second page  1.0  waits on the maintainer',
        ])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe(['  #51  Todo  question: is this on page two?'])
        ->and(statusSection($run['out'], 'Now'))->toBe([
            '  #52 In review  Under review',
            '      PR #61 · CI green (1 check)',
        ]);

    // Every list is asked page by page: a REST list through `--paginate --slurp`, as the GraphQL ones are.
    foreach (statusGhCalls($project) as $call) {
        if ($call[0] === 'api') {
            expect($call)->toContain('--paginate')->and($call)->toContain('--slurp');
        }
    }
});

it('reads branches from the remote that names this repository', function (): void {
    $project = statusProject(['cards' => [statusCard(['number' => 10, 'title' => 'Alpha', 'status' => 'In Progress'])]]);
    statusPushBranch($project, 'feature/10/alpha', 3);

    // `origin` becomes someone's fork, which has no branch; the repository this board belongs to is now `upstream`.
    $fork = $project['dir'].'/fork.git';
    mkdir($fork);
    statusGit($fork, ['init', '--quiet', '--bare', '--initial-branch=main']);
    statusGit($project['work'], ['remote', 'rename', 'origin', 'upstream']);
    statusGit($project['work'], ['remote', 'add', 'origin', 'https://github.com/someone/lara-spec-first.git']);
    statusGit($project['work'], ['config', 'url.'.$fork.'.insteadOf', 'https://github.com/someone/lara-spec-first.git']);
    statusGit($project['work'], ['push', '--quiet', 'origin', 'main']);
    $sha = statusGit($project['work'], ['rev-parse', '--short', 'upstream/main']);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(explode("\n", $run['out'])[0])->toStartWith("lara-spec-first · main {$sha} · ")
        ->and(statusSection($run['out'], 'Now'))->toBe([
            '  #10 In Progress  Alpha',
            '      branch feature/10/alpha, 3 commits ahead of main · no pull request',
        ]);
});

it('prints the same bytes for the same board, whatever the local branches', function (): void {
    $project = statusProject(['cards' => [statusCard(['number' => 10, 'title' => 'Alpha', 'status' => 'In Progress'])]]);
    statusPushBranch($project, 'feature/10/alpha', 2);

    $first = statusRunScript($project);

    // A branch that only exists here, on this machine, is not part of the answer.
    statusGit($project['work'], ['checkout', '--quiet', '-b', 'feature/18/local-only', 'main']);
    statusGit($project['work'], ['commit', '--quiet', '--allow-empty', '-m', 'chore: local work']);
    statusGit($project['work'], ['checkout', '--quiet', 'main']);

    $second = statusRunScript($project);

    expect($first['exit'])->toBe(0, $first['err'])
        ->and($second['out'])->toBe($first['out'])
        ->and($second['out'])->not->toContain('local-only');
});

it('exits 0 and prints no drift for a board that matches git', function (): void {
    $project = statusProject([
        'milestones' => [
            statusMilestone(['title' => '0.3.0']),
            statusMilestone(['title' => '1.0']),
            statusMilestone(['title' => '0.2.0', 'state' => 'closed']),
        ],
        'cards' => [
            statusCard(['number' => 10, 'status' => 'Todo', 'order' => 10]),
            statusCard(['number' => 11, 'status' => 'Todo', 'order' => 20]),
            statusCard(['number' => 12, 'status' => 'In Progress']),
            statusCard(['number' => 13, 'status' => 'In review']),
            statusCard(['number' => 14, 'status' => 'Backlog']),
            statusCard(['number' => 15, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.3.0']),
            statusCard(['number' => 16, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Docs', 'milestone' => '0.3.0']),
            // On the V1 bar and on no release yet: the label plans nothing, so a closed `v1` card with no milestone is history.
            statusCard(['number' => 17, 'status' => 'Done', 'state' => 'CLOSED', 'labels' => ['v1']]),
            statusCard(['number' => 18, 'status' => 'Done', 'state' => 'CLOSED']),
            statusCard(['number' => 19, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Lot']),
            statusCard(['number' => 20, 'status' => 'Done', 'state' => 'CLOSED', 'stateReason' => 'NOT_PLANNED']),
            statusCard(['number' => 21, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.2.0']),
            statusCard(['number' => 22, 'status' => 'In Progress', 'kind' => 'Lot']),
            statusCard(['number' => 23, 'status' => 'Todo', 'kind' => 'Lot']),
            statusCard(['number' => 24, 'status' => 'Done', 'state' => 'CLOSED', 'onBoard' => false]),
        ],
        'prs' => [statusPr(['number' => 40, 'branch' => 'feature/13/under-review', 'checks' => [['name' => 'lint', 'conclusion' => 'SUCCESS']]])],
        'merged' => [statusMerged(['number' => 41, 'branch' => 'feature/15/merged', 'closes' => [15]])],
    ]);
    statusPushBranch($project, 'feature/12/started', 0);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out']))->toBe(['lines' => [], 'count' => 0, 'header' => 0])
        ->and($run['out'])->toEndWith("\nDrift: none.\n");
});

it('flags an open issue missing from the board, and a card whose status contradicts its issue', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 30, 'status' => 'Todo', 'onBoard' => false]),
            statusCard(['number' => 31, 'status' => 'Done']),
            statusCard(['number' => 32, 'status' => 'Ready to publish']),
            statusCard(['number' => 33, 'status' => 'Todo', 'state' => 'CLOSED']),
            statusCard(['number' => 34, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'kind' => 'Docs']),
            statusCard(['number' => 35, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'kind' => 'Lot']),
            statusCard(['number' => 36, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'stateReason' => 'NOT_PLANNED']),
            statusCard(['number' => 37, 'status' => 'Ready to publish', 'state' => 'CLOSED']),
            statusCard(['number' => 38, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Decision']),
            statusCard(['number' => 39, 'status' => 'Todo', 'state' => 'CLOSED', 'onBoard' => false]),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  #30  Open, but not on the board. Add it and triage it.',
                '  #31  Done, but its issue is open. Close the issue, or move the card back.',
                '  #32  Ready to publish, but its issue is open. Close the issue, or move the card back.',
                '  #33  Closed, but Todo. Move it to Ready to publish.',
                '  #34  Ready to publish, but it is a Docs card, which needs no release. Move it to Done.',
                '  #35  Ready to publish, but it is a Lot card, which needs no release. Move it to Done.',
                '  #36  Ready to publish, but it was closed as not planned, so it ships nothing. Move it to Done.',
            ],
            'count' => 7,
            'header' => 7,
        ]);
});

it('flags a Ready to publish card closed before the latest release, and a Done card whose release has not shipped', function (): void {
    $project = statusProject([
        'milestones' => [
            statusMilestone(['title' => '0.2.0', 'state' => 'closed']),
            statusMilestone(['title' => '0.3.0']),
            statusMilestone(['title' => '1.0']),
        ],
        // The latest release is the newest tag by date, and a prerelease is no release.
        'tags' => ['v0.1.0' => '2026-11-01T00:00:00Z', 'v0.2.0' => '2026-11-12T00:00:00Z', 'v0.4.0-rc.1' => '2026-11-20T00:00:00Z'],
        'cards' => [
            // B5: closed before v0.2.0 was tagged, so it shipped in it, whatever its milestone.
            statusCard(['number' => 40, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.2.0']),
            statusCard(['number' => 47, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '1.0', 'labels' => ['v1']]),
            statusCard(['number' => 55, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.3.0', 'labels' => ['v1']]),
            // Closed after the tag: it waits for the next release.
            statusCard(['number' => 45, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.3.0', 'closedAt' => '2026-11-13T09:00:00Z']),
            statusCard(['number' => 48, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '1.0', 'closedAt' => '2026-11-13T09:00:00Z']),
            statusCard(['number' => 50, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.2.0', 'closedAt' => '2026-11-13T09:00:00Z']),
            // B6: Done while its release is still open, and nothing was tagged since it closed. The others are history, or need no release.
            statusCard(['number' => 41, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.3.0', 'closedAt' => '2026-11-13T09:00:00Z']),
            // Closed before v0.2.0 was tagged: it shipped in it, though its own milestone is still open.
            statusCard(['number' => 51, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.3.0']),
            // Closed at the very second of the tag: the release holds only what closed before it, so this one did not ship.
            statusCard(['number' => 52, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.3.0', 'closedAt' => '2026-11-12T00:00:00Z']),
            statusCard(['number' => 53, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '0.3.0', 'closedAt' => '2026-11-12T00:00:00Z']),
            statusCard(['number' => 42, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => null]),
            statusCard(['number' => 43, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '1.0', 'labels' => ['v1']]),
            // `1.0` is a release milestone and is open: a `v1` card that closed after the tag has not shipped either.
            statusCard(['number' => 54, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '1.0', 'labels' => ['v1'], 'closedAt' => '2026-11-13T09:00:00Z']),
            statusCard(['number' => 44, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.2.0']),
            statusCard(['number' => 46, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Docs', 'milestone' => '0.3.0']),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  #40  Ready to publish, but it was released in v0.2.0. Move it to Done.',
            '  #41  Done, but no release has been tagged since it closed. Move it back to Ready to publish.',
            '  #47  Ready to publish, but it was released in v0.2.0. Move it to Done.',
            '  #52  Done, but no release has been tagged since it closed. Move it back to Ready to publish.',
            '  #54  Done, but no release has been tagged since it closed. Move it back to Ready to publish.',
            '  #55  Ready to publish, but it was released in v0.2.0. Move it to Done.',
        ]);
});

it('flags a Done card on an open release milestone when no release was ever tagged', function (): void {
    $project = statusProject([
        'milestones' => [statusMilestone(['title' => '0.3.0'])],
        'cards' => [
            statusCard(['number' => 40, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => '0.3.0']),
            statusCard(['number' => 41, 'status' => 'Done', 'state' => 'CLOSED', 'milestone' => null]),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  #40  Done, but no release has been tagged since it closed. Move it back to Ready to publish.',
        ]);
});

it('reads releases from the upstream, so a tag only this clone has is not one', function (): void {
    $project = statusProject([
        'tags' => ['v0.1.0' => '2026-11-01T00:00:00Z'],
        // Fetched from elsewhere, or deleted on GitHub since: the clone keeps it, the upstream does not have it.
        'localTags' => ['v0.2.0' => '2026-11-12T00:00:00Z'],
        'cards' => [statusCard(['number' => 40, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '1.0'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out']))->toBe(['lines' => [], 'count' => 0, 'header' => 0]);
});

it('says nothing about a release when the upstream has no release tag', function (): void {
    $project = statusProject([
        'tags' => ['nightly' => '2026-11-20T00:00:00Z'],
        'cards' => [statusCard(['number' => 40, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => '1.0'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out']))->toBe(['lines' => [], 'count' => 0, 'header' => 0]);
});

it('flags a pull request that serves no card, or a closed one', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 50, 'status' => 'Ready to publish', 'state' => 'CLOSED']),
            statusCard(['number' => 51, 'status' => 'In review', 'onBoard' => false]),
            statusCard(['number' => 52, 'status' => 'In review']),
            statusCard(['number' => 53, 'status' => 'In review']),
        ],
        'prs' => [
            statusPr(['number' => 70, 'branch' => 'wip-thing']),
            statusPr(['number' => 71, 'branch' => 'feature/50/closed-card']),
            statusPr(['number' => 72, 'branch' => 'feature/51/not-on-the-board']),
            statusPr(['number' => 73, 'branch' => 'feature/52/fine']),
            statusPr(['number' => 74, 'branch' => 'wip-two', 'closes' => [53]]),
            statusPr(['number' => 75, 'branch' => 'feature/999/ghost']),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  PR #70  serves no card (branch wip-thing). Rename the branch to {type}/{card}/{context}, or link the card it closes.',
            '  #50     PR #71 serves it, but it is closed. Link the card it really serves, or reopen this one.',
            '  #51     Open, but not on the board. Add it and triage it.',
            '  #51     PR #72 serves it, but it is not on the board. Add the card to the board, or fix the branch name.',
            '  #999    PR #75 serves it, but it is not on the board. Add the card to the board, or fix the branch name.',
        ]);
});

it('flags a card whose status contradicts its pull requests', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 60, 'status' => 'Todo', 'order' => 10]),
            statusCard(['number' => 61, 'status' => 'In Progress']),
            statusCard(['number' => 62, 'status' => 'In review']),
            statusCard(['number' => 63, 'status' => 'In review']),
            statusCard(['number' => 64, 'status' => 'In review']),
            statusCard(['number' => 65, 'status' => 'In review', 'kind' => 'Docs']),
            statusCard(['number' => 66, 'status' => 'In review']),
        ],
        'prs' => [
            statusPr(['number' => 80, 'branch' => 'feature/60/drafted', 'draft' => true]),
            statusPr(['number' => 81, 'branch' => 'feature/61/started']),
            statusPr(['number' => 82, 'branch' => 'feature/62/fine']),
            statusPr(['number' => 83, 'branch' => 'feature/66/second']),
            statusPr(['number' => 84, 'branch' => 'feature/61/again']),
        ],
        'merged' => [
            statusMerged(['number' => 90, 'branch' => 'wip-thing', 'closes' => [63]]),
            statusMerged(['number' => 91, 'branch' => 'docs/65/merged']),
            statusMerged(['number' => 92, 'branch' => 'feature/66/first']),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  #60  Todo, but PR #80 serves it. Move it to In review.',
            '  #61  In Progress, but PR #81, #84 serve it. Move it to In review.',
            '  #63  In review, but no open pull request serves it, and PR #90 (merged) does. If every box of its Done when is ticked, close it and move it to Ready to publish; otherwise move it back to In Progress, since another pull request is coming.',
            '  #64  In review, but no pull request serves it. Move it back to In Progress or Todo.',
            '  #65  In review, but no open pull request serves it, and PR #91 (merged) does. If every box of its Done when is ticked, close it and move it to Done; otherwise move it back to In Progress, since another pull request is coming.',
        ]);
});

it('flags a card whose status contradicts its branches upstream', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 70, 'status' => 'Todo', 'order' => 10]),
            statusCard(['number' => 71, 'status' => 'Backlog']),
            statusCard(['number' => 72, 'status' => 'Todo', 'order' => 20]),
            statusCard(['number' => 73, 'status' => 'Todo', 'order' => 30]),
            statusCard(['number' => 74, 'status' => 'Todo', 'order' => 40]),
            statusCard(['number' => 75, 'status' => 'In Progress']),
            statusCard(['number' => 76, 'status' => 'In Progress']),
            statusCard(['number' => 77, 'status' => 'In Progress', 'kind' => 'Lot']),
            statusCard(['number' => 78, 'status' => 'Todo', 'kind' => 'Lot']),
            statusCard(['number' => 79, 'status' => 'In Progress']),
            statusCard(['number' => 80, 'status' => 'Todo', 'order' => 50]),
        ],
        'prs' => [
            statusPr(['number' => 100, 'branch' => 'feature/79/open']),
            statusPr(['number' => 101, 'branch' => 'feature/80/open']),
        ],
    ]);
    statusPushBranch($project, 'feature/70/stale', 2);
    statusPushBranch($project, 'feature/71/stale', 1);
    statusPushBranch($project, 'feature/72/just-created', 0);
    statusPushBranch($project, 'feature/73/trial-spike', 2);
    statusPushBranch($project, 'feature/76/just-created', 0);
    statusPushBranch($project, 'feature/78/lot-branch', 1);
    statusPushBranch($project, 'feature/80/open', 1);
    // A branch that only exists on this machine is not on the upstream.
    statusGit($project['work'], ['checkout', '--quiet', '-b', 'feature/74/local-only', 'main']);
    statusGit($project['work'], ['commit', '--quiet', '--allow-empty', '-m', 'chore: local work']);
    statusGit($project['work'], ['checkout', '--quiet', 'main']);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  #70  Todo, but branch feature/70/stale is 2 commits ahead of main. Move it to In Progress, or delete the stale branch.',
            '  #71  Backlog, but branch feature/71/stale is 1 commit ahead of main. Move it to In Progress, or delete the stale branch.',
            '  #75  In Progress, but nothing is on a branch and no pull request is open. Push its branch when the card starts, or move it back to Todo.',
            '  #79  In Progress, but PR #100 serves it. Move it to In review.',
            '  #80  Todo, but PR #101 serves it. Move it to In review.',
        ]);
});

it('flags a red main', function (): void {
    $red = statusProject(['ci' => [statusRun('lint'), statusRun('tests (8.3)', conclusion: 'failure'), statusRun('docs', 'in_progress', null)]]);

    $run = statusRunScript($red);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe(['  main  CI on main failed: tests (8.3). Repair main before anything else.']);

    $going = statusProject(['ci' => [statusRun('lint'), statusRun('docs', 'in_progress', null)]]);

    $run = statusRunScript($going);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([]);
});

it('flags a Todo card with no Order, a shared Order, or a body that is still a sketch', function (): void {
    $project = statusProject(['cards' => [
        statusCard(['number' => 110]),
        statusCard(['number' => 111, 'order' => 10]),
        statusCard(['number' => 112, 'order' => 10]),
        statusCard(['number' => 113, 'order' => 20, 'body' => "## Assumptions\n\nMaybe.\n\n## Discovery\n\nUnknown.\n\n## Done when\n\n- [ ] Yes.\n"]),
        statusCard(['number' => 114, 'order' => 30, 'body' => "## Discovery\r\n\r\nUnknown.\r\n\r\n## Done when\r\n\r\n- [ ] Yes.\r\n"]),
        statusCard(['number' => 115, 'order' => 40, 'body' => "## Decisions\n\nSettled.\n"]),
        statusCard(['number' => 116, 'order' => 50]),
        statusCard(['number' => 117, 'kind' => 'Lot']),
        statusCard(['number' => 118, 'order' => 60, 'body' => "We checked our assumptions and our discovery.\n\n## Done when\n\n- [ ] Yes.\n"]),
        statusCard(['number' => 119, 'status' => 'Backlog', 'body' => "## Assumptions\n\nMaybe.\n"]),
    ]]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out'])['lines'])->toBe([
            '  #110  Todo with no Order. Give it one, or it has no place in the queue.',
            '  #111  Todo with Order 10, like #112. Give each card its own, so the queue has one order.',
            '  #112  Todo with Order 10, like #111. Give each card its own, so the queue has one order.',
            '  #113  Todo, but its body still has ## Assumptions and ## Discovery. A Todo card has nothing left open: settle each into Decisions and delete the section.',
            '  #114  Todo, but its body still has ## Discovery. A Todo card has nothing left open: settle each into Decisions and delete the section.',
            '  #115  Todo, but its body has no ## Done when. Add it: a Todo card says how to tell it is done.',
        ]);
});

it('sorts the drift by card, with the findings that name no card first', function (): void {
    $project = statusProject([
        'ci' => [statusRun('tests', conclusion: 'failure')],
        'cards' => [
            statusCard(['number' => 5, 'status' => 'Done']),
            statusCard(['number' => 3, 'body' => 'A sketch.']),
        ],
        'prs' => [statusPr(['number' => 70, 'branch' => 'wip-thing'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  main    CI on main failed: tests. Repair main before anything else.',
                '  PR #70  serves no card (branch wip-thing). Rename the branch to {type}/{card}/{context}, or link the card it closes.',
                '  #3      Todo with no Order. Give it one, or it has no place in the queue.',
                '  #3      Todo, but its body has no ## Done when. Add it: a Todo card says how to tell it is done.',
                '  #5      Done, but its issue is open. Close the issue, or move the card back.',
            ],
            'count' => 5,
            'header' => 5,
        ]);
});

it('lists the cards that wait on the maintainer, with the question posted on each', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 111, 'title' => 'Accept null', 'order' => 20, 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 45, 'title' => 'Which bar', 'order' => 10, 'labels' => ['decision', 'waiting-on-maintainer']]),
            statusCard(['number' => 90, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 91, 'status' => 'Backlog']),
        ],
        'comments' => [
            statusComment(['issue' => 111, 'body' => "**Question Q24 for the maintainer:** what does dto() return for a null body?\r\n\r\nA. null.\nB. an empty array."]),
            statusComment(['issue' => 90, 'body' => "A plain note first.\n\nThe next move is yours."]),
            statusComment(['issue' => 90, 'body' => '**Question Q3 for the maintainer:**   which floor?  ']),
            statusComment(['issue' => 91, 'body' => 'Nothing to decide here.']),
        ],
    ]);

    $run = statusRunScript($project);

    // The title line only, the marker stripped, the cards by number, and the status column as wide as its widest status.
    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe([
            '  #45   Todo     decision card',
            '  #90   Backlog  question: which floor?',
            '  #111  Todo     question: what does dto() return for a null body?',
        ])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '  10  #45   Which bar    1.0  waits on the maintainer',
            '  20  #111  Accept null  1.0  waits on the maintainer',
        ]);
});

it('reads a question answered by a later decision as closed', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 10, 'status' => 'Backlog']),
            statusCard(['number' => 11, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 12, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
        ],
        'comments' => [
            statusComment(['issue' => 10, 'body' => '**Question Q1 for the maintainer:** first?']),
            statusComment(['issue' => 10, 'body' => '**Decided (D1):** A, because it is simpler.']),
            statusComment(['issue' => 11, 'body' => '**Question Q2 for the maintainer:** first?']),
            statusComment(['issue' => 11, 'body' => '**Decided (D2):** A.']),
            statusComment(['issue' => 11, 'body' => '**Question Q3 for the maintainer:** and second?']),
            statusComment(['issue' => 12, 'body' => '**Question Q4 for the maintainer:** asked first?']),
            statusComment(['issue' => 12, 'body' => '**Question Q5 for the maintainer:** asked again, and last?']),
        ],
    ]);

    $run = statusRunScript($project);

    // #10 is answered and carries no label: not waiting, and no W2. #11 was answered, then asked again: open again. #12
    // has two questions and no decision: the latest is the one shown.
    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe([
            '  #11  Backlog  question: and second?',
            '  #12  Backlog  question: asked again, and last?',
        ]);
});

it('counts only an exact marker from a maintainer or a collaborator', function (): void {
    $question = '**Question Q9 for the maintainer:** which one?';
    $project = statusProject([
        'cards' => [
            // Nothing here is a question, so none of these cards carries the label and none is waiting.
            statusCard(['number' => 20, 'status' => 'Backlog']),
            statusCard(['number' => 21, 'status' => 'Backlog']),
            statusCard(['number' => 22, 'status' => 'Backlog']),
            statusCard(['number' => 23, 'status' => 'Backlog']),
            statusCard(['number' => 24, 'status' => 'Backlog']),
            statusCard(['number' => 25, 'status' => 'Backlog']),
            // A question from the maintainer, from a member and from a collaborator is one.
            statusCard(['number' => 30, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 31, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 32, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            // An outsider's decision does not close it.
            statusCard(['number' => 33, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
        ],
        'comments' => [
            statusComment(['issue' => 20, 'body' => '**Question: which one?']),
            statusComment(['issue' => 21, 'body' => $question, 'association' => 'NONE']),
            statusComment(['issue' => 22, 'body' => $question, 'association' => 'CONTRIBUTOR']),
            statusComment(['issue' => 23, 'body' => '**Question Q9 for the maintainer:* which one?']),
            statusComment(['issue' => 24, 'body' => "Thinking out loud.\n".$question]),
            statusComment(['issue' => 25, 'body' => ' '.$question]),
            statusComment(['issue' => 30, 'body' => $question, 'association' => 'OWNER']),
            statusComment(['issue' => 31, 'body' => $question, 'association' => 'MEMBER']),
            statusComment(['issue' => 32, 'body' => $question, 'association' => 'COLLABORATOR']),
            statusComment(['issue' => 33, 'body' => $question]),
            statusComment(['issue' => 33, 'body' => '**Decided (D9):** A.', 'association' => 'NONE']),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe([
            '  #30  Backlog  question: which one?',
            '  #31  Backlog  question: which one?',
            '  #32  Backlog  question: which one?',
            '  #33  Backlog  question: which one?',
        ]);
});

it('flags a waiting card with no question posted, and a posted question with no label', function (): void {
    $project = statusProject([
        'cards' => [
            // W1: the label, and no question; or a question already answered.
            statusCard(['number' => 30, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            statusCard(['number' => 31, 'status' => 'Backlog', 'labels' => ['waiting-on-maintainer']]),
            // A decision card with the label needs no question.
            statusCard(['number' => 32, 'status' => 'Backlog', 'labels' => ['decision', 'waiting-on-maintainer']]),
            // W2: an open question and no label.
            statusCard(['number' => 33, 'status' => 'Backlog']),
            // A decision card in Backlog waits on its lot, not on an answer: no label is asked of it, question or not.
            statusCard(['number' => 34, 'status' => 'Backlog', 'labels' => ['decision']]),
        ],
        'comments' => [
            statusComment(['issue' => 31, 'body' => '**Question Q1 for the maintainer:** which?']),
            statusComment(['issue' => 31, 'body' => '**Decided (D1):** A.']),
            statusComment(['issue' => 33, 'body' => '**Question Q2 for the maintainer:** which?']),
            statusComment(['issue' => 34, 'body' => '**Question Q3 for the maintainer:** which?']),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  #30  Carries waiting-on-maintainer, but no question is posted on it. Post the question, or take the label off.',
                '  #31  Carries waiting-on-maintainer, but no question is posted on it. Post the question, or take the label off.',
                '  #33  A question is open, but the card does not carry waiting-on-maintainer. Add the label.',
            ],
            'count' => 3,
            'header' => 3,
        ]);
});

it('flags a closed card still waiting, and a decision card in Todo that does not wait', function (): void {
    $project = statusProject([
        'cards' => [
            // W3: closed, and still waiting.
            statusCard(['number' => 40, 'status' => 'Done', 'state' => 'CLOSED', 'labels' => ['waiting-on-maintainer']]),
            // W4: a decision card in Todo, without the label; one in Backlog waits on its lot, so it is fine.
            statusCard(['number' => 41, 'order' => 10, 'labels' => ['decision']]),
            statusCard(['number' => 42, 'status' => 'Backlog', 'labels' => ['decision']]),
            // A closed decision card needs nothing.
            statusCard(['number' => 43, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Decision', 'labels' => ['decision']]),
        ],
    ]);

    $run = statusRunScript($project);

    // A closed card never waits, so the section stays empty.
    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  #40  Closed, but still carries waiting-on-maintainer. Take the label off: it comes off with the decision.',
                '  #41  A decision card in Todo waits on the maintainer, but lacks waiting-on-maintainer. Add the label.',
            ],
            'count' => 2,
            'header' => 2,
        ])
        ->and(statusSection($run['out'], "Maintainer's turn"))->toBe(['  Nothing waits on the maintainer.']);
});

it('flags a card past Backlog with no milestone', function (): void {
    $project = statusProject([
        'cards' => [
            // M1: every status past Backlog needs a milestone, and the `v1` label is not one. A lot card ships nothing, so it needs none.
            statusCard(['number' => 60, 'status' => 'Todo', 'order' => 10, 'milestone' => null]),
            statusCard(['number' => 61, 'status' => 'In Progress', 'milestone' => null]),
            statusCard(['number' => 62, 'status' => 'In review', 'milestone' => null]),
            statusCard(['number' => 63, 'status' => 'Ready to publish', 'state' => 'CLOSED', 'milestone' => null]),
            statusCard(['number' => 69, 'status' => 'Todo', 'order' => 40, 'labels' => ['v1'], 'milestone' => null]),
            // Fine: a lot card, Backlog is not planned yet, 1.0 is a release milestone like any other, and Done is history.
            statusCard(['number' => 64, 'status' => 'Todo', 'kind' => 'Lot', 'milestone' => null]),
            statusCard(['number' => 59, 'status' => 'In Progress', 'labels' => ['v1'], 'milestone' => '0.3.0']),
            statusCard(['number' => 65, 'status' => 'Backlog']),
            statusCard(['number' => 66, 'status' => 'Todo', 'order' => 20, 'milestone' => '1.0']),
            statusCard(['number' => 67, 'status' => 'Todo', 'order' => 30, 'milestone' => '0.3.0']),
            statusCard(['number' => 68, 'status' => 'Done', 'state' => 'CLOSED']),
        ],
        'milestones' => [statusMilestone(['title' => '0.3.0']), statusMilestone(['title' => '1.0'])],
        'prs' => [statusPr(['number' => 90, 'branch' => 'feature/62/under-review', 'checks' => [['name' => 'lint', 'conclusion' => 'SUCCESS']]])],
        'merged' => [],
    ]);
    statusPushBranch($project, 'feature/61/started', 0);
    statusPushBranch($project, 'feature/59/started', 0);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  #60  Todo with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.',
                '  #61  In Progress with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.',
                '  #62  In review with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.',
                '  #63  Ready to publish with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.',
                '  #69  Todo with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.',
            ],
            'count' => 5,
            'header' => 5,
        ]);
});

it('flags an edge case without its reason, or planned for a release', function (): void {
    $withReason = "## Decisions\n\nSettled.\n\n## Why it can wait\n\nNothing passes that the contract refuses.\n\nMoves to Todo when:\n\n- a user reports it\n";
    $project = statusProject([
        'cards' => [
            // E1: no `## Why it can wait` section, or the words only inside a paragraph.
            statusCard(['number' => 70, 'status' => 'Backlog', 'labels' => ['edge-case'], 'body' => STATUS_COMPLETE_BODY]),
            statusCard(['number' => 71, 'status' => 'Backlog', 'labels' => ['edge-case'], 'body' => "## Decisions\n\nWhy it can wait is not written yet.\n"]),
            // E2: a release milestone. Both rules can speak about one card.
            statusCard(['number' => 72, 'status' => 'Backlog', 'labels' => ['edge-case'], 'milestone' => '0.3.0', 'body' => $withReason]),
            statusCard(['number' => 73, 'status' => 'Backlog', 'labels' => ['edge-case'], 'milestone' => '0.3.0', 'body' => STATUS_COMPLETE_BODY]),
            // A closed edge case is history, and an issue off the board has no body to read: B1 speaks for it.
            statusCard(['number' => 74, 'status' => 'Done', 'state' => 'CLOSED', 'kind' => 'Docs', 'labels' => ['edge-case'], 'milestone' => '0.3.0', 'body' => STATUS_COMPLETE_BODY]),
            statusCard(['number' => 75, 'status' => 'Backlog', 'labels' => ['edge-case'], 'onBoard' => false]),
            // The same words on a card that is not an edge case are nobody's business.
            statusCard(['number' => 76, 'status' => 'Backlog', 'body' => STATUS_COMPLETE_BODY]),
            // `1.0` is a release milestone: an edge case ships in no release yet, the release of 1.0 included.
            statusCard(['number' => 77, 'status' => 'Backlog', 'labels' => ['edge-case', 'v1'], 'milestone' => '1.0', 'body' => $withReason]),
        ],
        'milestones' => [statusMilestone(['title' => '0.3.0']), statusMilestone(['title' => '1.0'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(1, $run['err'])
        ->and(statusDrift($run['out']))->toBe([
            'lines' => [
                '  #70  An edge case with no ## Why it can wait. Write the reason it waits and its triggers, under Moves to Todo when:.',
                '  #71  An edge case with no ## Why it can wait. Write the reason it waits and its triggers, under Moves to Todo when:.',
                '  #72  An edge case on release 0.3.0. A trigger fired, so take edge-case off; or take the milestone off, since an edge case ships in no release yet.',
                '  #73  An edge case with no ## Why it can wait. Write the reason it waits and its triggers, under Moves to Todo when:.',
                '  #73  An edge case on release 0.3.0. A trigger fired, so take edge-case off; or take the milestone off, since an edge case ships in no release yet.',
                '  #75  Open, but not on the board. Add it and triage it.',
                '  #77  An edge case on release 1.0. A trigger fired, so take edge-case off; or take the milestone off, since an edge case ships in no release yet.',
            ],
            'count' => 7,
            'header' => 7,
        ]);
});

it('accepts an edge case that waits with no milestone, v1 included', function (): void {
    $withReason = "## Decisions\n\nSettled.\n\n## Why it can wait\r\n\r\nNothing passes that the contract refuses.\n\nMoves to Todo when:\n\n- the 1.0 gate opens\n";
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 80, 'status' => 'Backlog', 'labels' => ['edge-case'], 'body' => $withReason]),
            statusCard(['number' => 81, 'status' => 'Backlog', 'labels' => ['edge-case', 'v1'], 'body' => $withReason]),
        ],
        'milestones' => [statusMilestone(['title' => '1.0'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out']))->toBe(['lines' => [], 'count' => 0, 'header' => 0])
        ->and($run['out'])->toEndWith("\nDrift: none.\n");
});

it('leaves a lot card to its lesson pull request, which never moves it to In review', function (): void {
    $project = statusProject([
        'cards' => [statusCard(['number' => 94, 'status' => 'In Progress', 'kind' => 'Lot'])],
        'prs' => [statusPr(['number' => 90, 'branch' => 'docs/94/lot-2-lesson', 'checks' => [['name' => 'lint', 'conclusion' => 'SUCCESS']]])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusDrift($run['out']))->toBe(['lines' => [], 'count' => 0, 'header' => 0])
        ->and(statusSection($run['out'], 'Now'))->toBe(['  Nothing is in flight.']);
});

it('keeps a decimal Order, so a card slotted between two sorts there and shares no Order', function (): void {
    $project = statusProject([
        'cards' => [
            statusCard(['number' => 30, 'order' => 20]),
            statusCard(['number' => 31, 'order' => 15.5]),
            statusCard(['number' => 32, 'order' => 15]),
            statusCard(['number' => 33, 'order' => 15.25]),
        ],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '     15  #32  Card 32  1.0',
            '  15.25  #33  Card 33  1.0',
            '   15.5  #31  Card 31  1.0',
            '     20  #30  Card 30  1.0',
        ]);
});

it('reads only the board items of this repository', function (): void {
    $project = statusProject([
        'cards' => [statusCard(['number' => 10, 'status' => 'Backlog', 'kind' => 'Chore'])],
        // Same number, another repository, a status that would put card 10 in the queue: it is not this card's.
        'foreign' => [statusCard(['number' => 10, 'status' => 'Todo', 'kind' => 'Feature', 'order' => 10])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe(['  Nothing is waiting in Todo.']);
});

it('reads a commit status on main as it reads a check run, so an outside CI can turn main red', function (array $statuses, string $expected, int $drift): void {
    $project = statusProject(['ci' => [statusRun('tests')], 'mainStatuses' => $statuses]);

    $run = statusRunScript($project);

    expect(explode("\n", $run['out'])[0])->toEndWith(' · CI '.$expected.' · drift '.$drift);
})->with([
    'a status that succeeded' => [[['context' => 'ci/outside', 'state' => 'success']], 'green (2 checks)', 0],
    'a status that failed' => [[['context' => 'ci/outside', 'state' => 'failure']], 'red: ci/outside', 1],
    'a status that errored' => [[['context' => 'ci/outside', 'state' => 'error']], 'red: ci/outside', 1],
    'a status still pending' => [[['context' => 'ci/outside', 'state' => 'pending']], 'running', 0],
]);
