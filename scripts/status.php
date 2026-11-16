#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Print what is in flight, what comes next and the next release, from the board, the
 * pull requests, the milestones, CI and git. `just status` runs it, on the host.
 *
 * The board is the single source of what comes next (docs/contributing/card-management.md,
 * "The board"). This reads it, and the places it must agree with, and prints one page:
 *
 *   - the header: the repository, `main`, CI on `main`, and the drift count;
 *   - Now: the open cards `In review` or `In Progress`, each with its pull requests
 *     and their CI, or else its branches on the upstream and how far ahead of `main`
 *     they are. A lot card is never listed: nobody works on one directly;
 *   - Next: the open `Todo` cards, by `Order` and then by number, those with no
 *     `Order` last;
 *   - Release: the open release milestone (`0.x.y`) with the lowest version, how many
 *     of its issues are closed, and its open cards by status. `1.0` is the V1 bar, not
 *     a release, so it never shows here;
 *   - Maintainer's turn and Drift, which print their empty answers for now.
 *
 * The same board and the same git give the same bytes: plain text, sorted, no colour
 * and no clock time. Git is read from the upstream's branches only, never from the
 * local ones, so two machines give the same answer.
 *
 * It only asks. GitHub gets `gh repo view` and `gh api` reads (GraphQL queries and REST
 * GETs), git gets one `fetch --prune` of the upstream remote, and nothing else is
 * written anywhere. Every list is read in full, page by page (`gh api --paginate
 * --slurp`), so a long list is never cut and never a failure. `gh` takes its next
 * cursor from the first `pageInfo` in an answer, which is why each paged query selects
 * its own `pageInfo { hasNextPage endCursor }` before its `nodes`, and a nested set
 * selects `hasNextPage` alone: a second `endCursor` would silently stop the paging.
 *
 * `gh` is never given `--repo` (the convention of scripts/release-lib.sh): it reads the
 * repository from the checkout, so a checkout of a fork would mix the fork's pull
 * requests with this board. The script therefore first asks which repository `gh`
 * resolves and which git remote names this one, and stops when they are not
 * Gcob/lara-spec-first.
 *
 * Which card a pull request serves: the cards it closes (`closingIssuesReferences`)
 * when it closes any, otherwise the card its branch names, `{type}/{card}/{context}`.
 * A branch serves the card its name gives the same way.
 *
 * Usage:
 *
 *   php scripts/status.php
 *
 * It takes no argument. Exit codes: 0 it printed, 2 a real error (no `gh`, `gh` or git
 * failing, the wrong repository or remote, an answer the script does not recognise) or a
 * usage error. On exit 2 nothing but the error is printed, on standard error. It is not
 * run by CI: it needs a token with the `project` scope, which CI does not have.
 */

/** The board and the repository its cards live in. */
const OWNER = 'Gcob';
const PROJECT_NUMBER = 1;
const REPOSITORY = 'Gcob/lara-spec-first';

/** The label that says the next move is the maintainer's. */
const WAITING_LABEL = 'waiting-on-maintainer';

/** The board's statuses, in the order the sections list them. */
const STATUS_IN_REVIEW = 'In review';
const STATUS_IN_PROGRESS = 'In Progress';
const STATUS_TODO = 'Todo';
const STATUS_BACKLOG = 'Backlog';
const STATUS_READY_TO_PUBLISH = 'Ready to publish';
const STATUS_DONE = 'Done';

/** A title longer than this is cut, the dots included. */
const TITLE_WIDTH = 60;

const USAGE = "usage: php scripts/status.php\n\nTakes no argument. It prints what is in flight, what comes next and the next release.\n";

const QUERY_BOARD = <<<'GRAPHQL'
query($endCursor: String) {
  user(login: "__OWNER__") {
    projectV2(number: __PROJECT__) {
      items(first: 100, after: $endCursor) {
        pageInfo { hasNextPage endCursor }
        nodes {
          content { __typename ... on Issue { number body } }
          fieldValues(first: 100) {
            pageInfo { hasNextPage }
            nodes {
              __typename
              ... on ProjectV2ItemFieldSingleSelectValue { name field { ... on ProjectV2FieldCommon { name } } }
              ... on ProjectV2ItemFieldNumberValue { number field { ... on ProjectV2FieldCommon { name } } }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

const QUERY_ISSUES = <<<'GRAPHQL'
query($owner: String!, $name: String!, $endCursor: String) {
  repository(owner: $owner, name: $name) {
    issues(first: 100, after: $endCursor) {
      pageInfo { hasNextPage endCursor }
      nodes {
        number
        title
        state
        stateReason
        milestone { title }
        labels(first: 100) { pageInfo { hasNextPage } nodes { name } }
      }
    }
  }
}
GRAPHQL;

const QUERY_OPEN_PULL_REQUESTS = <<<'GRAPHQL'
query($owner: String!, $name: String!, $endCursor: String) {
  repository(owner: $owner, name: $name) {
    pullRequests(states: [OPEN], first: 100, after: $endCursor) {
      pageInfo { hasNextPage endCursor }
      nodes {
        number
        headRefName
        isDraft
        closingIssuesReferences(first: 100) { pageInfo { hasNextPage } nodes { number } }
        commits(last: 1) { nodes { commit { statusCheckRollup { contexts(first: 100) {
          pageInfo { hasNextPage }
          nodes {
            __typename
            ... on CheckRun { name status conclusion }
            ... on StatusContext { context state }
          }
        } } } } }
      }
    }
  }
}
GRAPHQL;

const QUERY_MERGED_PULL_REQUESTS = <<<'GRAPHQL'
query($owner: String!, $name: String!, $endCursor: String) {
  repository(owner: $owner, name: $name) {
    pullRequests(states: [MERGED], first: 100, after: $endCursor) {
      pageInfo { hasNextPage endCursor }
      nodes {
        number
        headRefName
        mergedAt
        closingIssuesReferences(first: 100) { pageInfo { hasNextPage } nodes { number } }
      }
    }
  }
}
GRAPHQL;

/**
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    if (count($argv) > 1) {
        fwrite(STDERR, USAGE);

        return 2;
    }

    try {
        $report = build_report(dirname(__DIR__));
    } catch (RuntimeException $failure) {
        fwrite(STDERR, 'status: '.$failure->getMessage()."\n");

        return 2;
    }

    fwrite(STDOUT, $report);

    return 0;
}

/**
 * Read everything, then print it: nothing reaches standard output before every read has worked.
 */
function build_report(string $root): string
{
    $slug = trim(gh($root, ['repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner'], 'the repository'));

    if ($slug !== REPOSITORY) {
        throw new RuntimeException(
            "gh resolves the repository '{$slug}', and this board belongs to ".REPOSITORY.'. Run: gh repo set-default '.REPOSITORY,
        );
    }

    $upstream = upstream_remote($root);

    git($root, ['fetch', '--prune', '--quiet', $upstream]);

    $cards = read_cards($root);
    $comments = read_comments($root);
    $pullRequests = read_pull_requests($root);
    read_merged_pull_requests($root);
    $milestones = read_milestones($root);
    $main = read_main($root, $upstream);
    $branches = read_branches($root, $upstream);

    // Read and checked, but only the sections of the later scopes print them.
    unset($comments);

    $drift = 0;
    $sections = [
        sprintf('%s · main %s · CI %s · drift %d', substr(REPOSITORY, (int) strpos(REPOSITORY, '/') + 1), $main['sha'], $main['ci'], $drift),
        section_now($cards, $pullRequests, $branches),
        section_next($cards),
        section_release($cards, $milestones),
        "Maintainer's turn\n  Nothing waits on the maintainer.",
        'Drift: none.',
    ];

    return implode("\n\n", $sections)."\n";
}

// ---------------------------------------------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------------------------------------------

/**
 * Run a command in the repository, with nothing to read on standard input.
 *
 * @param  list<string>  $command
 * @return array{exit: int, out: string, err: string}
 */
function run_command(string $root, array $command): array
{
    $errors = tmpfile();

    if ($errors === false) {
        throw new RuntimeException('could not create a temporary file');
    }

    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $errors], $pipes, $root);

    if (! is_resource($process)) {
        throw new RuntimeException("could not run {$command[0]}");
    }

    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);

    rewind($errors);
    $err = trim((string) stream_get_contents($errors));
    fclose($errors);

    return ['exit' => $exit, 'out' => $out, 'err' => $err];
}

/**
 * @param  list<string>  $arguments
 */
function gh(string $root, array $arguments, string $what): string
{
    $run = run_command($root, ['gh', ...$arguments]);

    if ($run['exit'] !== 0) {
        throw new RuntimeException("gh could not read {$what}".($run['err'] === '' ? '.' : ': '.$run['err']));
    }

    return $run['out'];
}

/**
 * @param  list<string>  $arguments
 */
function git(string $root, array $arguments): string
{
    $run = run_command($root, ['git', ...$arguments]);

    if ($run['exit'] !== 0) {
        throw new RuntimeException('git '.implode(' ', $arguments).' failed'.($run['err'] === '' ? '.' : ': '.$run['err']));
    }

    return trim($run['out']);
}

/**
 * @return array<mixed>
 */
function decode(string $json, string $what): array
{
    $decoded = json_decode($json, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("gh answered something unexpected for {$what}.");
    }

    return $decoded;
}

/**
 * Every node of a paged GraphQL connection, whatever the number of pages.
 *
 * @param  list<string>  $path  where the connection sits under `data`
 * @return list<array<mixed>>
 */
function graphql_nodes(string $root, string $query, array $path, string $what, bool $repository): array
{
    $arguments = ['api', 'graphql', '--paginate', '--slurp'];

    if ($repository) {
        array_push($arguments, '-F', 'owner={owner}', '-F', 'name={repo}');
    }

    $query = str_replace(['__OWNER__', '__PROJECT__'], [OWNER, (string) PROJECT_NUMBER], $query);
    $pages = decode(gh($root, [...$arguments, '-f', 'query='.$query], $what), $what);
    $nodes = [];

    foreach ($pages as $page) {
        $connection = is_array($page) ? $page['data'] ?? null : null;

        foreach ($path as $key) {
            $connection = is_array($connection) ? $connection[$key] ?? null : null;
        }

        if (! is_array($connection) || ! is_array($connection['nodes'] ?? null)) {
            throw new RuntimeException("gh answered something unexpected for {$what}.");
        }

        foreach ($connection['nodes'] as $node) {
            if (! is_array($node)) {
                throw new RuntimeException("gh answered something unexpected for {$what}.");
            }

            $nodes[] = $node;
        }
    }

    return $nodes;
}

/**
 * Every item of a paged REST list, whatever the number of pages.
 *
 * @return list<array<mixed>> the pages, each as `gh api --paginate --slurp` hands it back
 */
function rest_pages(string $root, string $path, string $what): array
{
    $pages = array_values(decode(gh($root, ['api', $path, '--paginate', '--slurp'], $what), $what));

    foreach ($pages as $page) {
        if (! is_array($page)) {
            throw new RuntimeException("gh answered something unexpected for {$what}.");
        }
    }

    /** @var list<array<mixed>> $pages */
    return $pages;
}

/**
 * A nested set read with `first: 100`: one with a next page means the query is wrong for this repository.
 *
 * @param  array<mixed>  $set
 * @return list<array<mixed>>
 */
function nested_nodes(mixed $set, string $name, string $owner): array
{
    if (! is_array($set) || ! is_array($set['nodes'] ?? null)) {
        throw new RuntimeException("gh answered something unexpected: {$owner} has no list of {$name}.");
    }

    if (($set['pageInfo']['hasNextPage'] ?? false) === true) {
        throw new RuntimeException("{$owner} has more than 100 {$name}: the query reads 100 and needs a page of its own.");
    }

    return array_values(array_filter($set['nodes'], 'is_array'));
}

/**
 * The remote that names this repository, `origin` first: the raw configured address, so an `insteadOf` does not hide
 * it.
 */
function upstream_remote(string $root): string
{
    $run = run_command($root, ['git', 'config', '--get-regexp', '^remote\..+\.url$']);
    $names = [];

    foreach (explode("\n", $run['out']) as $line) {
        if (preg_match('/^remote\.(.+)\.url (.+)$/', $line, $found) !== 1) {
            continue;
        }

        $address = (string) preg_replace('/\.git$/', '', trim($found[2]));

        if (preg_match('#[:/]([^/:]+/[^/]+)$#', $address, $slug) === 1 && strcasecmp($slug[1], REPOSITORY) === 0) {
            $names[] = $found[1];
        }
    }

    if ($names === []) {
        throw new RuntimeException('no git remote names '.REPOSITORY.'. Run: git remote add upstream https://github.com/'.REPOSITORY.'.git');
    }

    return in_array('origin', $names, true) ? 'origin' : $names[0];
}

/**
 * Every issue of the repository, with what the board says about it.
 *
 * @return array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>
 */
function read_cards(string $root): array
{
    $items = [];

    foreach (graphql_nodes($root, QUERY_BOARD, ['user', 'projectV2', 'items'], 'the board', false) as $item) {
        $content = $item['content'] ?? null;

        if (! is_array($content) || ($content['__typename'] ?? null) !== 'Issue' || ! is_int($content['number'] ?? null)) {
            continue;
        }

        $fields = [];

        foreach (nested_nodes($item['fieldValues'] ?? null, 'field values', 'board item #'.$content['number']) as $value) {
            $name = is_array($value['field'] ?? null) ? $value['field']['name'] ?? null : null;

            if (is_string($name)) {
                $fields[$name] = $value['name'] ?? $value['number'] ?? null;
            }
        }

        $items[$content['number']] = [
            'status' => is_string($fields['Status'] ?? null) ? $fields['Status'] : null,
            'kind' => is_string($fields['Kind'] ?? null) ? $fields['Kind'] : null,
            'order' => is_int($fields['Order'] ?? null) || is_float($fields['Order'] ?? null) ? (int) $fields['Order'] : null,
            'body' => is_string($content['body'] ?? null) ? $content['body'] : '',
        ];
    }

    $cards = [];

    foreach (graphql_nodes($root, QUERY_ISSUES, ['repository', 'issues'], 'the issues', true) as $issue) {
        if (! is_int($issue['number'] ?? null) || ! is_string($issue['title'] ?? null) || ! is_string($issue['state'] ?? null)) {
            throw new RuntimeException('gh answered something unexpected for the issues.');
        }

        $number = $issue['number'];
        $milestone = is_array($issue['milestone'] ?? null) ? $issue['milestone']['title'] ?? null : null;
        $labels = array_values(array_filter(array_map(
            static fn (array $label): mixed => $label['name'] ?? null,
            nested_nodes($issue['labels'] ?? null, 'labels', 'issue #'.$number),
        ), 'is_string'));
        $item = $items[$number] ?? null;

        $cards[$number] = [
            'number' => $number,
            'title' => $issue['title'],
            'state' => $issue['state'],
            'stateReason' => is_string($issue['stateReason'] ?? null) ? $issue['stateReason'] : null,
            'milestone' => is_string($milestone) ? $milestone : null,
            'labels' => $labels,
            'onBoard' => $item !== null,
            'status' => $item['status'] ?? null,
            'kind' => $item['kind'] ?? null,
            'order' => $item['order'] ?? null,
            'body' => $item['body'] ?? '',
        ];
    }

    ksort($cards);

    return $cards;
}

/**
 * Every issue comment, read in full. Nothing prints from them yet, but a list that cannot be read is an error today.
 *
 * @return list<array<mixed>>
 */
function read_comments(string $root): array
{
    $comments = [];

    foreach (rest_pages($root, 'repos/{owner}/{repo}/issues/comments?per_page=100', 'the issue comments') as $page) {
        foreach ($page as $comment) {
            if (! is_array($comment)) {
                throw new RuntimeException('gh answered something unexpected for the issue comments.');
            }

            $comments[] = $comment;
        }
    }

    return $comments;
}

/**
 * The open pull requests, with the cards each serves and the state of its checks.
 *
 * @return list<array{number: int, branch: string, draft: bool, serves: list<int>, ci: string}>
 */
function read_pull_requests(string $root): array
{
    $pullRequests = [];

    foreach (graphql_nodes($root, QUERY_OPEN_PULL_REQUESTS, ['repository', 'pullRequests'], 'the open pull requests', true) as $pr) {
        if (! is_int($pr['number'] ?? null) || ! is_string($pr['headRefName'] ?? null)) {
            throw new RuntimeException('gh answered something unexpected for the open pull requests.');
        }

        $owner = 'pull request #'.$pr['number'];
        $serves = array_values(array_filter(array_map(
            static fn (array $reference): mixed => $reference['number'] ?? null,
            nested_nodes($pr['closingIssuesReferences'] ?? null, 'closing references', $owner),
        ), 'is_int'));
        $serves = $serves === [] ? array_filter([card_of_branch($pr['headRefName'])]) : $serves;

        $rollup = $pr['commits']['nodes'][0]['commit']['statusCheckRollup'] ?? null;
        $contexts = is_array($rollup) ? nested_nodes($rollup['contexts'] ?? null, 'check contexts', $owner) : [];

        $pullRequests[] = [
            'number' => $pr['number'],
            'branch' => $pr['headRefName'],
            'draft' => ($pr['isDraft'] ?? false) === true,
            'serves' => array_values($serves),
            'ci' => describe_checks(array_map('check_of_context', $contexts)),
        ];
    }

    usort($pullRequests, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

    return $pullRequests;
}

/**
 * Every merged pull request, read in full and checked. Nothing prints from them yet.
 */
function read_merged_pull_requests(string $root): void
{
    foreach (graphql_nodes($root, QUERY_MERGED_PULL_REQUESTS, ['repository', 'pullRequests'], 'the merged pull requests', true) as $pr) {
        nested_nodes($pr['closingIssuesReferences'] ?? null, 'closing references', 'pull request #'.($pr['number'] ?? '?'));
    }
}

/**
 * @return list<array{title: string, open: bool}>
 */
function read_milestones(string $root): array
{
    $milestones = [];

    foreach (rest_pages($root, 'repos/{owner}/{repo}/milestones?state=all&per_page=100', 'the milestones') as $page) {
        foreach ($page as $milestone) {
            if (! is_array($milestone) || ! is_string($milestone['title'] ?? null)) {
                throw new RuntimeException('gh answered something unexpected for the milestones.');
            }

            $milestones[] = ['title' => $milestone['title'], 'open' => ($milestone['state'] ?? null) === 'open'];
        }
    }

    return $milestones;
}

/**
 * `main` as the upstream has it, and what CI says about it.
 *
 * @return array{sha: string, ci: string}
 */
function read_main(string $root, string $upstream): array
{
    $checks = [];

    foreach (rest_pages($root, 'repos/{owner}/{repo}/commits/main/check-runs?per_page=100', 'CI on main') as $page) {
        foreach (is_array($page['check_runs'] ?? null) ? $page['check_runs'] : [] as $run) {
            if (! is_array($run)) {
                throw new RuntimeException('gh answered something unexpected for CI on main.');
            }

            $finished = ($run['status'] ?? null) === 'completed';
            $checks[] = [
                'name' => is_string($run['name'] ?? null) ? $run['name'] : '?',
                'finished' => $finished,
                'passed' => $finished && in_array($run['conclusion'] ?? null, ['success', 'skipped', 'neutral'], true),
            ];
        }
    }

    return ['sha' => git($root, ['rev-parse', '--short', $upstream.'/main']), 'ci' => describe_checks($checks)];
}

/**
 * The upstream's branches that belong to a card's work, with how far ahead of the upstream's `main` each is.
 *
 * @return array<string, int> branch name => commits ahead
 */
function read_branches(string $root, string $upstream): array
{
    $prefix = 'refs/remotes/'.$upstream.'/';
    $branches = [];

    foreach (explode("\n", git($root, ['for-each-ref', '--format=%(refname)', 'refs/remotes/'.$upstream])) as $ref) {
        $name = substr($ref, strlen($prefix));

        // A phase-A trial is not the card's work, and `HEAD` and `main` are not a card's branch.
        if ($ref === '' || in_array($name, ['HEAD', 'main'], true) || str_ends_with($name, '-claude-spike')) {
            continue;
        }

        $branches[$name] = (int) git($root, ['rev-list', '--count', $upstream.'/main..'.$ref]);
    }

    ksort($branches);

    return $branches;
}

/**
 * The card a branch names, `{type}/{card}/{context}`.
 */
function card_of_branch(string $branch): ?int
{
    return preg_match('#^[^/]+/(\d+)/#', $branch, $found) === 1 ? (int) $found[1] : null;
}

// ---------------------------------------------------------------------------------------------------------------
// CI
// ---------------------------------------------------------------------------------------------------------------

/**
 * One check context of a pull request's last commit: a check run, or a commit status from an outside CI.
 *
 * @param  array<mixed>  $context
 * @return array{name: string, finished: bool, passed: bool}
 */
function check_of_context(array $context): array
{
    if (($context['__typename'] ?? null) === 'StatusContext') {
        $state = $context['state'] ?? null;

        return [
            'name' => is_string($context['context'] ?? null) ? $context['context'] : '?',
            'finished' => ! in_array($state, ['PENDING', 'EXPECTED'], true),
            'passed' => $state === 'SUCCESS',
        ];
    }

    $finished = ($context['status'] ?? null) === 'COMPLETED';

    return [
        'name' => is_string($context['name'] ?? null) ? $context['name'] : '?',
        'finished' => $finished,
        'passed' => $finished && in_array($context['conclusion'] ?? null, ['SUCCESS', 'SKIPPED', 'NEUTRAL'], true),
    ];
}

/**
 * Four words: `green (N checks)` when every check finished well, `red: <names>` when one finished badly, `running` when
 * one has not finished, `no checks` when there is none. Red wins over running, since it is what to fix.
 *
 * @param  list<array{name: string, finished: bool, passed: bool}>  $checks
 */
function describe_checks(array $checks): string
{
    if ($checks === []) {
        return 'no checks';
    }

    $failed = array_column(array_filter($checks, static fn (array $check): bool => $check['finished'] && ! $check['passed']), 'name');

    if ($failed !== []) {
        return 'red: '.implode(', ', $failed);
    }

    if (array_filter($checks, static fn (array $check): bool => ! $check['finished']) !== []) {
        return 'running';
    }

    return sprintf('green (%d %s)', count($checks), count($checks) === 1 ? 'check' : 'checks');
}

// ---------------------------------------------------------------------------------------------------------------
// Printing
// ---------------------------------------------------------------------------------------------------------------

/**
 * Pad on the right to a width counted in characters, not bytes.
 */
function pad_right(string $text, int $width): string
{
    return $text.str_repeat(' ', max(0, $width - mb_strlen($text)));
}

function cut_title(string $title): string
{
    return mb_strlen($title) > TITLE_WIDTH ? mb_substr($title, 0, TITLE_WIDTH - 3).'...' : $title;
}

function commits(int $count): string
{
    return $count.($count === 1 ? ' commit' : ' commits');
}

/**
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  list<array{number: int, branch: string, draft: bool, serves: list<int>, ci: string}>  $pullRequests
 * @param  array<string, int>  $branches
 */
function section_now(array $cards, array $pullRequests, array $branches): string
{
    $rank = [STATUS_IN_REVIEW => 0, STATUS_IN_PROGRESS => 1];
    $inFlight = array_filter(
        $cards,
        static fn (array $card): bool => $card['state'] === 'OPEN' && $card['kind'] !== 'Lot' && isset($rank[(string) $card['status']]),
    );

    usort($inFlight, static fn (array $a, array $b): int => [$rank[(string) $a['status']], $a['order'] ?? PHP_INT_MAX, $a['number']]
        <=> [$rank[(string) $b['status']], $b['order'] ?? PHP_INT_MAX, $b['number']]);

    if ($inFlight === []) {
        return "Now\n  Nothing is in flight.";
    }

    $numberWidth = max(array_map(static fn (array $card): int => strlen('#'.$card['number']), $inFlight));
    $statusWidth = max(array_map(static fn (array $card): int => mb_strlen((string) $card['status']), $inFlight));
    $indent = str_repeat(' ', 2 + $numberWidth + 1);
    $lines = ['Now'];

    foreach ($inFlight as $card) {
        $lines[] = '  '.pad_right('#'.$card['number'], $numberWidth).' '.pad_right((string) $card['status'], $statusWidth).'  '.cut_title($card['title']);

        $serving = array_filter($pullRequests, static fn (array $pr): bool => in_array($card['number'], $pr['serves'], true));

        foreach ($serving as $pr) {
            $lines[] = $indent.'PR #'.$pr['number'].($pr['draft'] ? ' (draft)' : '').' · CI '.$pr['ci'];
        }

        if ($serving !== []) {
            continue;
        }

        $named = array_filter($branches, static fn (int $ahead, string $name): bool => card_of_branch($name) === $card['number'], ARRAY_FILTER_USE_BOTH);

        foreach ($named as $name => $ahead) {
            $lines[] = $indent.'branch '.$name.', '.commits($ahead).' ahead of main · no pull request';
        }

        if ($named === []) {
            $lines[] = $indent.'nothing on a branch · no pull request';
        }
    }

    return implode("\n", $lines);
}

/**
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 */
function section_next(array $cards): string
{
    $todo = array_filter(
        $cards,
        static fn (array $card): bool => $card['state'] === 'OPEN' && $card['kind'] !== 'Lot' && $card['status'] === STATUS_TODO,
    );

    usort($todo, static fn (array $a, array $b): int => [$a['order'] ?? PHP_INT_MAX, $a['number']] <=> [$b['order'] ?? PHP_INT_MAX, $b['number']]);

    if ($todo === []) {
        return "Next (Todo, by Order)\n  Nothing is waiting in Todo.";
    }

    $orders = array_map(static fn (array $card): string => $card['order'] === null ? '-' : (string) $card['order'], $todo);
    $orderWidth = max(array_map('strlen', $orders));
    $numberWidth = max(array_map(static fn (array $card): int => strlen('#'.$card['number']), $todo));
    $titles = array_map(static fn (array $card): string => cut_title($card['title']), $todo);
    $titleWidth = max(array_map('mb_strlen', $titles));
    $milestoneWidth = max(array_map(static fn (array $card): int => mb_strlen($card['milestone'] ?? ''), $todo));
    $lines = ['Next (Todo, by Order)'];

    foreach (array_values($todo) as $index => $card) {
        $line = '  '.str_pad($orders[$index], $orderWidth, ' ', STR_PAD_LEFT).'  '.pad_right('#'.$card['number'], $numberWidth)
            .'  '.pad_right($titles[$index], $titleWidth).'  '.pad_right($card['milestone'] ?? '', $milestoneWidth);

        if (in_array(WAITING_LABEL, $card['labels'], true)) {
            $line .= '  waits on the maintainer';
        }

        $lines[] = rtrim($line);
    }

    return implode("\n", $lines);
}

/**
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  list<array{title: string, open: bool}>  $milestones
 */
function section_release(array $cards, array $milestones): string
{
    $releases = array_column(array_filter(
        $milestones,
        static fn (array $milestone): bool => $milestone['open'] && preg_match('/^0\.\d+\.\d+$/', $milestone['title']) === 1,
    ), 'title');

    if ($releases === []) {
        return "Release\n  No release milestone is open.";
    }

    usort($releases, 'version_compare');
    $release = $releases[0];

    $issues = array_filter($cards, static fn (array $card): bool => $card['milestone'] === $release);
    $closed = array_filter($issues, static fn (array $card): bool => $card['state'] === 'CLOSED');
    $ready = array_filter($closed, static fn (array $card): bool => $card['status'] === STATUS_READY_TO_PUBLISH);
    $done = array_filter($closed, static fn (array $card): bool => $card['status'] === STATUS_DONE);

    $lines = [sprintf(
        'Release %s: %d of %d closed (%d ready to publish, %d done)',
        $release,
        count($closed),
        count($issues),
        count($ready),
        count($done),
    )];

    // The fixed order first; an open card in any other status, or off the board, comes last so none is hidden.
    $groups = [STATUS_IN_REVIEW => [], STATUS_IN_PROGRESS => [], STATUS_TODO => [], STATUS_BACKLOG => []];

    foreach ($issues as $card) {
        if ($card['state'] === 'OPEN') {
            $groups[$card['status'] ?? 'No status'][] = '#'.$card['number'];
        }
    }

    $groups = array_filter($groups, static fn (array $numbers): bool => $numbers !== []);

    if ($groups !== []) {
        $width = max(array_map('mb_strlen', array_keys($groups)));

        foreach ($groups as $status => $numbers) {
            $lines[] = '  '.pad_right((string) $status, $width).'  '.implode(', ', $numbers);
        }
    }

    return implode("\n", $lines);
}

exit(main($argv));
