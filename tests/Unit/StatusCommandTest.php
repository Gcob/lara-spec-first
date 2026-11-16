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
 * @return array{number: int, title: string, status: string, kind: string, order: int|null, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, labelsNext: bool, body: string}
 */
function statusCard(array $overrides): array
{
    $number = $overrides['number'];

    /** @var array{number: int, title: string, status: string, kind: string, order: int|null, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, labelsNext: bool, body: string} */
    return array_merge([
        'number' => $number,
        'title' => 'Card '.$number,
        'status' => 'Todo',
        'kind' => 'Feature',
        'order' => null,
        'state' => 'OPEN',
        'stateReason' => null,
        'milestone' => null,
        'labels' => [],
        'labelsNext' => false,
        'body' => '',
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
 * @param  array{number: int, title: string, status: string, kind: string, order: int|null, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, labelsNext: bool, body: string}  $card
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
        'content' => ['__typename' => 'Issue', 'number' => $card['number'], 'body' => $card['body']],
        'fieldValues' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => $values],
    ];
}

/**
 * @param  array{number: int, title: string, status: string, kind: string, order: int|null, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, labelsNext: bool, body: string}  $card
 * @return array<string, mixed>
 */
function statusIssueNode(array $card): array
{
    return [
        'number' => $card['number'],
        'title' => $card['title'],
        'state' => $card['state'],
        'stateReason' => $card['stateReason'],
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
 * Each key of `$data` is one list the fake answers with, as a plain list (one page) or as `statusPages()`: `cards`,
 * `issues` (issues that are not on the board), `prs`, `merged` (pull request numbers), `milestones`, `comments`, `ci`
 * (the check runs of `main`) and `slug` (what `gh repo view` answers).
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

    /** @var list<list<array{number: int, title: string, status: string, kind: string, order: int|null, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, labelsNext: bool, body: string}>> $cardPages */
    $cardPages = statusSplit($listOf('cards'));
    $issuePages = statusSplit($listOf('issues'));
    $issueNodes = [];

    foreach ($cardPages as $index => $cards) {
        $issueNodes[$index] = array_map('statusIssueNode', $cards);
    }

    foreach ($issuePages as $index => $issues) {
        $issueNodes[$index] = [...($issueNodes[$index] ?? []), ...$issues];
    }

    /** @var list<list<array{number: int, branch: string, draft: bool, closes: list<int>, checks: list<array<string, mixed>>}>> $prPages */
    $prPages = statusSplit($listOf('prs'));
    $runs = (array) ($data['ci'] ?? [statusRun('a'), statusRun('b'), statusRun('c')]);

    $files = [
        'board.json' => statusGraphqlPages(
            array_map(static fn (array $cards): array => array_map('statusBoardItem', $cards), $cardPages),
            ['user', 'projectV2', 'items'],
        ),
        'issues.json' => statusGraphqlPages(array_values($issueNodes), ['repository', 'issues']),
        'comments.json' => statusSplit($listOf('comments')),
        'prs-open.json' => statusGraphqlPages(
            array_map(static fn (array $prs): array => array_map('statusPrNode', $prs), $prPages),
            ['repository', 'pullRequests'],
        ),
        'prs-merged.json' => statusGraphqlPages(
            array_map(static fn (array $numbers): array => array_map(
                static fn (mixed $number): array => [
                    'number' => $number,
                    'headRefName' => 'feature/'.(is_int($number) ? $number : 0).'/done',
                    'mergedAt' => '2026-11-01T12:00:00Z',
                    'closingIssuesReferences' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
                ],
                $numbers,
            ), statusSplit($listOf('merged'))),
            ['repository', 'pullRequests'],
        ),
        'milestones.json' => statusSplit($listOf('milestones')),
        'main-ci.json' => [['total_count' => count($runs), 'check_runs' => $runs]],
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

    expect($run['exit'])->toBe(0, $run['err'])
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

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '  10  #31  Second  0.3.0',
            '  10  #32  Thirds  0.3.0',
            '  20  #30  Fourth',
            '  30  #34  Fifths  0.3.0  waits on the maintainer',
            '   -  #33  Sixths',
        ]);
});

it('cuts a long title with three dots', function (): void {
    $title = 'Read a multipart part\'s default contentType for the documented encoding of a file';
    $project = statusProject(['cards' => [statusCard(['number' => 40, 'title' => $title, 'order' => 10])]]);

    $run = statusRunScript($project);

    expect(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
        '  10  #40  Read a multipart part\'s default contentType for the docum...',
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

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($run['out'])->toContain("\nRelease 0.3.0: 3 of 8 closed (1 ready to publish, 2 done)\n")
        ->and($run['out'])->not->toContain('Release 0.10.0')
        ->and(statusSection($run['out'], 'Release 0.3.0: 3 of 8 closed (1 ready to publish, 2 done)'))->toBe([
            '  In review    #40',
            '  In Progress  #41',
            '  Todo         #42, #43',
            '  Backlog      #44',
        ]);
});

it('says so when no release milestone is open, and never shows 1.0 there', function (): void {
    $project = statusProject([
        'milestones' => [statusMilestone(['title' => '1.0']), statusMilestone(['title' => '0.2.0', 'state' => 'closed'])],
        'cards' => [statusCard(['number' => 49, 'status' => 'Todo', 'milestone' => '1.0'])],
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Release'))->toBe(['  No release milestone is open.'])
        ->and($run['out'])->not->toContain('Release 1.0');
});

it('prints the header, and the sections that later scopes fill', function (): void {
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

it('says what main\'s CI is in the header', function (array $runs, string $expected): void {
    $project = statusProject(['ci' => $runs]);

    $run = statusRunScript($project);

    expect(explode("\n", $run['out'])[0])->toEndWith($expected.' · drift 0');
})->with([
    'every run green, skipped or neutral' => [[statusRun('a'), statusRun('b', conclusion: 'skipped'), statusRun('c', conclusion: 'neutral')], 'CI green (3 checks)'],
    'a failed run' => [[statusRun('a'), statusRun('tests (8.3)', conclusion: 'failure'), statusRun('docs', conclusion: 'cancelled')], 'CI red: tests (8.3), docs'],
    'a run still going' => [[statusRun('a'), statusRun('b', 'in_progress', null)], 'CI running'],
    'a failed run and one going' => [[statusRun('tests', conclusion: 'failure'), statusRun('b', 'queued', null)], 'CI red: tests'],
    'no run' => [[], 'CI no checks'],
]);

it('asks GitHub only questions, and leaves the repository as it found it', function (): void {
    $project = statusProject(['cards' => [statusCard(['number' => 10, 'status' => 'In Progress'])]]);
    statusPushBranch($project, 'feature/10/alpha', 1);
    $before = statusRepositoryState($project);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err']);

    $calls = statusGhCalls($project);

    expect($calls)->toHaveCount(8)
        ->and($calls[0])->toBe(['repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner']);

    $reads = ['projectV2', 'issues(', 'issues/comments', 'pullRequests(states: [OPEN]', 'pullRequests(states: [MERGED]', 'milestones', 'check-runs'];

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
            [statusCard(['number' => 51, 'title' => 'Second page', 'order' => 20]), statusCard(['number' => 52, 'title' => 'Under review', 'status' => 'In review'])],
        ),
        'prs' => statusPages(
            [statusPr(['number' => 60, 'branch' => 'feature/99/elsewhere'])],
            [statusPr(['number' => 61, 'branch' => 'feature/52/second', 'checks' => [['name' => 'lint', 'conclusion' => 'SUCCESS']]])],
        ),
        'comments' => statusPages([statusComment(['issue' => 50])], [statusComment(['issue' => 51])]),
        'merged' => statusPages(range(1, 100), range(101, 200), range(201, 250)),
    ]);

    $run = statusRunScript($project);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(statusSection($run['out'], 'Next (Todo, by Order)'))->toBe([
            '  10  #50  First page',
            '  20  #51  Second page',
        ])
        ->and(statusSection($run['out'], 'Now'))->toBe([
            '  #52 In review  Under review',
            '      PR #61 · CI green (1 check)',
        ]);

    // Every list is asked page by page: a REST list through `--paginate --slurp`, as the GraphQL ones are. A comment
    // only shows once a later scope prints the cards that wait, so here the flags are what proves the second page is read.
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
