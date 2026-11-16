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
 *   - Maintainer's turn, which prints its empty answer for now;
 *   - Drift: every place where the board, the issues, the pull requests, git and CI
 *     disagree, one line each, with the fix. The header carries the count.
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
 * What counts as drift is in docs/contributing/card-management.md, "What `just status` prints":
 * a rule id (B1, G3, O2...) names each case, and each is one function below.
 *
 * Which card a pull request serves: the cards it closes (`closingIssuesReferences`)
 * when it closes any, otherwise the card its branch names, `{type}/{card}/{context}`.
 * A branch serves the card its name gives the same way.
 *
 * Usage:
 *
 *   php scripts/status.php
 *
 * It takes no argument. Exit codes: 0 no drift, 1 drift (the page is printed), 2 a real
 * error (no `gh`, `gh` or git failing, the wrong repository or remote, an answer the script
 * does not recognise) or a usage error. On exit 2 nothing but the error is printed, on
 * standard error. It is not run by CI: it needs a token with the `project` scope, which CI
 * does not have.
 */

/** The board and the repository its cards live in. */
const OWNER = 'Gcob';
const PROJECT_NUMBER = 1;
const REPOSITORY = 'Gcob/lara-spec-first';

/** The label that says the next move is the maintainer's, and the one a decision card carries. */
const WAITING_LABEL = 'waiting-on-maintainer';
const DECISION_LABEL = 'decision';

/** The label of a card that waits, with its reason and its triggers, and ships in no release yet. */
const EDGE_CASE_LABEL = 'edge-case';

/**
 * What opens the first line of a question posted on a card, and of the decision that answers it. Only a comment from
 * one of these author associations counts.
 */
const QUESTION_MARKER = '/^\*\*Question Q\d+ for the maintainer:\*\*/';
const DECISION_MARKER = '/^\*\*Decided \(D\d+\):\*\*/';
const MAINTAINER_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

/** The board's statuses, in the order the sections list them. */
const STATUS_IN_REVIEW = 'In review';
const STATUS_IN_PROGRESS = 'In Progress';
const STATUS_TODO = 'Todo';
const STATUS_BACKLOG = 'Backlog';
const STATUS_READY_TO_PUBLISH = 'Ready to publish';
const STATUS_DONE = 'Done';

/** A title longer than this is cut, the dots included. */
const TITLE_WIDTH = 60;

/** The kinds of card that need no release: the docs site publishes on merge, and a lot card closes with its lesson. */
const KINDS_WITHOUT_RELEASE = ['Docs', 'Decision', 'Lot'];

const USAGE = "usage: php scripts/status.php\n\nTakes no argument. It prints what is in flight, what comes next and the next release, and the drift between them.\n";

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

    fwrite(STDOUT, $report['page']);

    return $report['drift'] > 0 ? 1 : 0;
}

/**
 * Read everything, then print it: nothing reaches standard output before every read has worked.
 *
 * @return array{page: string, drift: int}
 */
function build_report(string $root): array
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
    $merged = read_merged_pull_requests($root);
    $milestones = read_milestones($root);
    $main = read_main($root, $upstream);
    $branches = read_branches($root, $upstream);

    $questions = open_questions($comments);
    $findings = find_drift($cards, $questions, $pullRequests, $merged, $milestones, $main, $branches);
    $sections = [
        sprintf('%s · main %s · CI %s · drift %d', substr(REPOSITORY, (int) strpos(REPOSITORY, '/') + 1), $main['sha'], $main['ci'], count($findings)),
        section_now($cards, $pullRequests, $branches),
        section_next($cards),
        section_release($cards, $milestones),
        section_maintainer($cards, $questions),
        section_drift($findings),
    ];

    return ['page' => implode("\n\n", $sections)."\n", 'drift' => count($findings)];
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
 * Every issue comment, read in full, in the order GitHub lists them (oldest first). A comment on a pull request is one
 * too: it names a number that is no card, and nothing reads it.
 *
 * @return list<array{card: int, body: string, association: string}>
 */
function read_comments(string $root): array
{
    $comments = [];

    foreach (rest_pages($root, 'repos/{owner}/{repo}/issues/comments?per_page=100', 'the issue comments') as $page) {
        foreach ($page as $comment) {
            if (
                ! is_array($comment)
                || ! is_string($comment['issue_url'] ?? null)
                || preg_match('#/issues/(\d+)$#', $comment['issue_url'], $number) !== 1
                || ! is_string($comment['body'] ?? null)
                || ! is_string($comment['author_association'] ?? null)
            ) {
                throw new RuntimeException('gh answered something unexpected for the issue comments.');
            }

            $comments[] = ['card' => (int) $number[1], 'body' => $comment['body'], 'association' => $comment['author_association']];
        }
    }

    return $comments;
}

/**
 * The question each card still waits on: the title line of its latest question that no decision followed.
 *
 * @param  list<array{card: int, body: string, association: string}>  $comments
 * @return array<int, string>
 */
function open_questions(array $comments): array
{
    $open = [];

    foreach ($comments as $comment) {
        if (! in_array($comment['association'], MAINTAINER_ASSOCIATIONS, true)) {
            continue;
        }

        $firstLine = rtrim(strtok($comment['body'], "\n") ?: '', "\r");

        if (preg_match(QUESTION_MARKER, $firstLine, $marker) === 1) {
            $open[$comment['card']] = trim(substr($firstLine, strlen($marker[0])));
        } elseif (preg_match(DECISION_MARKER, $firstLine) === 1) {
            unset($open[$comment['card']]);
        }
    }

    return $open;
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
        $serves = served_cards($pr['headRefName'], nested_nodes($pr['closingIssuesReferences'] ?? null, 'closing references', $owner));

        $rollup = $pr['commits']['nodes'][0]['commit']['statusCheckRollup'] ?? null;
        $contexts = is_array($rollup) ? nested_nodes($rollup['contexts'] ?? null, 'check contexts', $owner) : [];

        $pullRequests[] = [
            'number' => $pr['number'],
            'branch' => $pr['headRefName'],
            'draft' => ($pr['isDraft'] ?? false) === true,
            'serves' => $serves,
            'ci' => describe_checks(array_map('check_of_context', $contexts)),
        ];
    }

    usort($pullRequests, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

    return $pullRequests;
}

/**
 * Every merged pull request, read in full, with the cards each served.
 *
 * @return list<array{number: int, serves: list<int>}>
 */
function read_merged_pull_requests(string $root): array
{
    $merged = [];

    foreach (graphql_nodes($root, QUERY_MERGED_PULL_REQUESTS, ['repository', 'pullRequests'], 'the merged pull requests', true) as $pr) {
        if (! is_int($pr['number'] ?? null) || ! is_string($pr['headRefName'] ?? null)) {
            throw new RuntimeException('gh answered something unexpected for the merged pull requests.');
        }

        $merged[] = [
            'number' => $pr['number'],
            'serves' => served_cards($pr['headRefName'], nested_nodes($pr['closingIssuesReferences'] ?? null, 'closing references', 'pull request #'.$pr['number'])),
        ];
    }

    usort($merged, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

    return $merged;
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
 * @return array{sha: string, ci: string, failed: list<string>}
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

    return [
        'sha' => git($root, ['rev-parse', '--short', $upstream.'/main']),
        'ci' => describe_checks($checks),
        'failed' => failed_checks($checks),
    ];
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

/**
 * The cards a pull request serves: the ones it closes when it closes any, otherwise the one its branch names.
 *
 * @param  list<array<mixed>>  $references  its `closingIssuesReferences`
 * @return list<int>
 */
function served_cards(string $branch, array $references): array
{
    $closed = array_values(array_filter(array_column($references, 'number'), 'is_int'));

    if ($closed !== []) {
        return $closed;
    }

    $named = card_of_branch($branch);

    return $named === null ? [] : [$named];
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
 * The names of the checks that finished and did not pass.
 *
 * @param  list<array{name: string, finished: bool, passed: bool}>  $checks
 * @return list<string>
 */
function failed_checks(array $checks): array
{
    return array_values(array_column(array_filter($checks, static fn (array $check): bool => $check['finished'] && ! $check['passed']), 'name'));
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

    $failed = failed_checks($checks);

    if ($failed !== []) {
        return 'red: '.implode(', ', $failed);
    }

    if (array_filter($checks, static fn (array $check): bool => ! $check['finished']) !== []) {
        return 'running';
    }

    return sprintf('green (%d %s)', count($checks), count($checks) === 1 ? 'check' : 'checks');
}

// ---------------------------------------------------------------------------------------------------------------
// Drift
// ---------------------------------------------------------------------------------------------------------------

/**
 * Every place where the board, the issues, the pull requests, git and CI disagree.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  array<int, string>  $questions
 * @param  list<array{number: int, branch: string, draft: bool, serves: list<int>, ci: string}>  $pullRequests
 * @param  list<array{number: int, serves: list<int>}>  $merged
 * @param  list<array{title: string, open: bool}>  $milestones
 * @param  array{sha: string, ci: string, failed: list<string>}  $main
 * @param  array<string, int>  $branches
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function find_drift(array $cards, array $questions, array $pullRequests, array $merged, array $milestones, array $main, array $branches): array
{
    $findings = [
        ...drift_main($main),
        ...drift_board($cards, $milestones),
        ...drift_pull_requests($cards, $pullRequests, $merged),
        ...drift_branches($cards, $pullRequests, $branches),
        ...drift_queue($cards),
        ...drift_waiting($cards, $questions),
        ...drift_milestones($cards),
    ];

    // The findings that name no card come first (`main`, then a pull request by its number), then the cards in order.
    $place = static fn (array $finding): array => [
        $finding['card'] !== null ? 2 : ($finding['pr'] !== null ? 1 : 0),
        $finding['card'] ?? $finding['pr'] ?? 0,
        $finding['rule'],
    ];

    usort($findings, static fn (array $a, array $b): int => $place($a) <=> $place($b));

    return $findings;
}

/**
 * @return array{rule: string, card: int|null, pr: int|null, text: string}
 */
function finding(string $rule, ?int $card, ?int $pr, string $text): array
{
    return ['rule' => $rule, 'card' => $card, 'pr' => $pr, 'text' => $text];
}

/**
 * C1: a check on `main` finished and failed.
 *
 * @param  array{sha: string, ci: string, failed: list<string>}  $main
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_main(array $main): array
{
    if ($main['failed'] === []) {
        return [];
    }

    return [finding('C1', null, null, 'CI on main failed: '.implode(', ', $main['failed']).'. Repair main before anything else.')];
}

function is_release_milestone(string $title): bool
{
    return preg_match('/^0\.\d+\.\d+$/', $title) === 1;
}

/**
 * Where a closed card belongs, and why when it is `Done`: a closed card waits in `Ready to publish` for its release,
 * unless it needs none.
 *
 * @param  array{kind: string|null, stateReason: string|null}  $card
 * @return array{status: string, why: string}
 */
function home_of_closed(array $card): array
{
    if (in_array($card['kind'], KINDS_WITHOUT_RELEASE, true)) {
        return ['status' => STATUS_DONE, 'why' => 'it is a '.$card['kind'].' card, which needs no release'];
    }

    if ($card['stateReason'] === 'NOT_PLANNED') {
        return ['status' => STATUS_DONE, 'why' => 'it was closed as not planned, so it ships nothing'];
    }

    return ['status' => STATUS_READY_TO_PUBLISH, 'why' => 'it ships in a release'];
}

/**
 * B1 to B6: the board against the issues.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  list<array{title: string, open: bool}>  $milestones
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_board(array $cards, array $milestones): array
{
    $releaseIsOpen = [];

    foreach ($milestones as $milestone) {
        if (is_release_milestone($milestone['title'])) {
            $releaseIsOpen[$milestone['title']] = $milestone['open'];
        }
    }

    $findings = [];

    foreach ($cards as $card) {
        $number = $card['number'];
        $status = $card['status'] ?? 'No status';
        $release = $card['milestone'] !== null ? ($releaseIsOpen[$card['milestone']] ?? null) : null;

        if (! $card['onBoard']) {
            // A closed issue that never reached the board is old history, not drift.
            if ($card['state'] === 'OPEN') {
                $findings[] = finding('B1', $number, null, 'Open, but not on the board. Add it and triage it.');
            }

            continue;
        }

        if ($card['state'] === 'OPEN') {
            if (in_array($status, [STATUS_READY_TO_PUBLISH, STATUS_DONE], true)) {
                $findings[] = finding('B2', $number, null, $status.', but its issue is open. Close the issue, or move the card back.');
            }

            continue;
        }

        $home = home_of_closed($card);

        if (! in_array($status, [STATUS_READY_TO_PUBLISH, STATUS_DONE], true)) {
            $findings[] = finding('B3', $number, null, 'Closed, but '.$status.'. Move it to '.$home['status'].'.');
        } elseif ($home['status'] === STATUS_DONE && $status === STATUS_READY_TO_PUBLISH) {
            $findings[] = finding('B4', $number, null, 'Ready to publish, but '.$home['why'].'. Move it to Done.');
        } elseif ($home['status'] === STATUS_READY_TO_PUBLISH && $status === STATUS_READY_TO_PUBLISH && $release === false) {
            $findings[] = finding('B5', $number, null, 'Ready to publish, but its release '.$card['milestone'].' has shipped. Move it to Done.');
        } elseif ($home['status'] === STATUS_READY_TO_PUBLISH && $status === STATUS_DONE && $release === true) {
            $findings[] = finding('B6', $number, null, 'Done, but its release '.$card['milestone'].' has not shipped. Move it back to Ready to publish.');
        }
    }

    return $findings;
}

/**
 * The pull request numbers, as `PR #1, #2`.
 *
 * @param  list<int>  $numbers
 */
function pull_request_numbers(array $numbers): string
{
    return 'PR #'.implode(', #', $numbers);
}

/**
 * G1 to G4: the board against the pull requests.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  list<array{number: int, branch: string, draft: bool, serves: list<int>, ci: string}>  $pullRequests
 * @param  list<array{number: int, serves: list<int>}>  $merged
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_pull_requests(array $cards, array $pullRequests, array $merged): array
{
    $findings = [];

    foreach ($pullRequests as $pr) {
        if ($pr['serves'] === []) {
            $findings[] = finding('G1', null, $pr['number'], 'serves no card (branch '.$pr['branch'].'). Rename the branch to {type}/{card}/{context}, or link the card it closes.');
        }

        foreach ($pr['serves'] as $number) {
            $card = $cards[$number] ?? null;

            if ($card === null || ! $card['onBoard']) {
                $findings[] = finding('G2', $number, null, 'PR #'.$pr['number'].' serves it, but it is not on the board. Add the card to the board, or fix the branch name.');
            } elseif ($card['state'] === 'CLOSED') {
                $findings[] = finding('G2', $number, null, 'PR #'.$pr['number'].' serves it, but it is closed. Link the card it really serves, or reopen this one.');
            }
        }
    }

    foreach ($cards as $card) {
        if ($card['state'] !== 'OPEN' || ! $card['onBoard']) {
            continue;
        }

        $number = $card['number'];
        $status = $card['status'] ?? 'No status';
        $open = array_column(array_filter($pullRequests, static fn (array $pr): bool => in_array($number, $pr['serves'], true)), 'number');

        if ($open !== [] && $status !== STATUS_IN_REVIEW) {
            $findings[] = finding('G3', $number, null, $status.', but '.pull_request_numbers($open).(count($open) === 1 ? ' serves' : ' serve').' it. Move it to In review.');
        }

        if ($open !== [] || $status !== STATUS_IN_REVIEW) {
            continue;
        }

        $done = array_column(array_filter($merged, static fn (array $pr): bool => in_array($number, $pr['serves'], true)), 'number');

        if ($done === []) {
            $findings[] = finding('G4', $number, null, 'In review, but no pull request serves it. Move it back to In Progress or Todo.');

            continue;
        }

        $findings[] = finding('G4', $number, null, sprintf(
            'In review, but no open pull request serves it, and %s (merged) does. If every box of its Done when is ticked, close it and move it to %s; otherwise move it back to In Progress, since another pull request is coming.',
            pull_request_numbers($done),
            home_of_closed($card)['status'],
        ));
    }

    return $findings;
}

/**
 * G5 and G6: the board against the upstream's branches. A lot card is exempt: nobody works on one directly. A card an
 * open pull request serves is G3's, not these.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  list<array{number: int, branch: string, draft: bool, serves: list<int>, ci: string}>  $pullRequests
 * @param  array<string, int>  $branches
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_branches(array $cards, array $pullRequests, array $branches): array
{
    $findings = [];

    foreach ($cards as $card) {
        if ($card['state'] !== 'OPEN' || ! $card['onBoard'] || $card['kind'] === 'Lot') {
            continue;
        }

        $number = $card['number'];
        $served = array_filter($pullRequests, static fn (array $pr): bool => in_array($number, $pr['serves'], true)) !== [];
        $named = array_filter($branches, static fn (int $ahead, string $name): bool => card_of_branch($name) === $number, ARRAY_FILTER_USE_BOTH);

        if ($served) {
            continue;
        }

        if (in_array($card['status'], [STATUS_BACKLOG, STATUS_TODO], true)) {
            $ahead = array_filter($named, static fn (int $count): bool => $count > 0);

            if ($ahead !== []) {
                $findings[] = finding('G5', $number, null, sprintf(
                    '%s, but %s. Move it to In Progress, or delete the stale branch.',
                    $card['status'],
                    count($ahead) === 1
                        ? 'branch '.array_key_first($ahead).' is '.commits(reset($ahead)).' ahead of main'
                        : 'branches '.implode(', ', array_map(static fn (string $name, int $count): string => $name.' ('.commits($count).')', array_keys($ahead), $ahead)).' are ahead of main',
                ));
            }
        }

        if ($card['status'] === STATUS_IN_PROGRESS && $named === []) {
            $findings[] = finding('G6', $number, null, 'In Progress, but nothing is on a branch and no pull request is open. Push its branch when the card starts, or move it back to Todo.');
        }
    }

    return $findings;
}

/**
 * O1, O2, T1 and T2: the `Todo` cards, which are the queue.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_queue(array $cards): array
{
    $todo = array_filter($cards, static fn (array $card): bool => $card['state'] === 'OPEN' && $card['onBoard'] && $card['status'] === STATUS_TODO);
    $findings = [];

    foreach ($todo as $card) {
        $number = $card['number'];

        // A lot card is never in the queue, so it has no place in it to share or to miss.
        if ($card['kind'] !== 'Lot') {
            if ($card['order'] === null) {
                $findings[] = finding('O1', $number, null, 'Todo with no Order. Give it one, or it has no place in the queue.');
            } else {
                $sharing = array_column(array_filter($todo, static fn (array $other): bool => $other['kind'] !== 'Lot' && $other['order'] === $card['order'] && $other['number'] !== $number), 'number');

                if ($sharing !== []) {
                    $findings[] = finding('O2', $number, null, 'Todo with Order '.$card['order'].', like #'.implode(', #', $sharing).'. Give each card its own, so the queue has one order.');
                }
            }
        }

        $open = array_values(array_filter(
            ['## Assumptions', '## Discovery'],
            static fn (string $heading): bool => preg_match('/^'.preg_quote($heading, '/').'[ \t]*\r?$/m', $card['body']) === 1,
        ));

        if ($open !== []) {
            $findings[] = finding('T1', $number, null, 'Todo, but its body still has '.implode(' and ', $open).'. A Todo card has nothing left open: settle each into Decisions and delete the section.');
        }

        if (preg_match('/^## Done when[ \t]*\r?$/m', $card['body']) !== 1) {
            $findings[] = finding('T2', $number, null, 'Todo, but its body has no ## Done when. Add it: a Todo card says how to tell it is done.');
        }
    }

    return $findings;
}

/**
 * W1 to W4: the label that says the next move is the maintainer's, against the questions posted on the cards.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  array<int, string>  $questions
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_waiting(array $cards, array $questions): array
{
    $findings = [];

    foreach ($cards as $card) {
        $number = $card['number'];
        $waits = in_array(WAITING_LABEL, $card['labels'], true);
        $isDecision = in_array(DECISION_LABEL, $card['labels'], true);
        $hasQuestion = isset($questions[$number]);

        if ($card['state'] !== 'OPEN') {
            if ($waits) {
                $findings[] = finding('W3', $number, null, 'Closed, but still carries '.WAITING_LABEL.'. Take the label off: it comes off with the decision.');
            }

            continue;
        }

        // A decision card from Todo on is the maintainer's whether or not a question is posted, so W4 speaks for it.
        if ($isDecision && ! $waits && in_array($card['status'], [STATUS_TODO, STATUS_IN_PROGRESS, STATUS_IN_REVIEW], true)) {
            $findings[] = finding('W4', $number, null, 'A decision card in '.$card['status'].' waits on the maintainer, but lacks '.WAITING_LABEL.'. Add the label.');
        } elseif ($hasQuestion && ! $waits) {
            $findings[] = finding('W2', $number, null, 'A question is open, but the card does not carry '.WAITING_LABEL.'. Add the label.');
        }

        if ($waits && ! $isDecision && ! $hasQuestion) {
            $findings[] = finding('W1', $number, null, 'Carries '.WAITING_LABEL.', but no question is posted on it. Post the question, or take the label off.');
        }
    }

    return $findings;
}

/**
 * M1, E1 and E2: a card past `Backlog` has a milestone, and an edge case waits with no release.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @return list<array{rule: string, card: int|null, pr: int|null, text: string}>
 */
function drift_milestones(array $cards): array
{
    $findings = [];

    foreach ($cards as $card) {
        $number = $card['number'];

        // No milestone means not planned: `1.0` counts as one, and a lot card in `Todo` is an opened lot.
        if ($card['onBoard'] && $card['milestone'] === null && in_array($card['status'], [STATUS_TODO, STATUS_IN_PROGRESS, STATUS_IN_REVIEW, STATUS_READY_TO_PUBLISH], true)) {
            $findings[] = finding('M1', $number, null, $card['status'].' with no milestone. Give it the release it ships in, or the milestone of the card in progress whose files it touches.');
        }

        // A closed edge case is history, and an issue off the board has no body to read (B1 speaks for it).
        if ($card['state'] !== 'OPEN' || ! in_array(EDGE_CASE_LABEL, $card['labels'], true)) {
            continue;
        }

        if ($card['onBoard'] && preg_match('/^## Why it can wait[ \t]*\r?$/m', $card['body']) !== 1) {
            $findings[] = finding('E1', $number, null, 'An edge case with no ## Why it can wait. Write the reason it waits and its triggers, under Moves to Todo when:.');
        }

        if ($card['milestone'] !== null && is_release_milestone($card['milestone'])) {
            $findings[] = finding('E2', $number, null, 'An edge case on release '.$card['milestone'].'. A trigger fired, so take '.EDGE_CASE_LABEL.' off; or take the milestone off, since an edge case ships in no release yet.');
        }
    }

    return $findings;
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
        static fn (array $milestone): bool => $milestone['open'] && is_release_milestone($milestone['title']),
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

/**
 * The open cards that carry the waiting label, by number, with the question posted on each.
 *
 * @param  array<int, array{number: int, title: string, state: string, stateReason: string|null, milestone: string|null, labels: list<string>, onBoard: bool, status: string|null, kind: string|null, order: int|null, body: string}>  $cards
 * @param  array<int, string>  $questions
 */
function section_maintainer(array $cards, array $questions): string
{
    $waiting = array_values(array_filter(
        $cards,
        static fn (array $card): bool => $card['state'] === 'OPEN' && in_array(WAITING_LABEL, $card['labels'], true),
    ));

    if ($waiting === []) {
        return "Maintainer's turn\n  Nothing waits on the maintainer.";
    }

    $numberWidth = max(array_map(static fn (array $card): int => strlen('#'.$card['number']), $waiting));
    $statuses = array_map(static fn (array $card): string => $card['status'] ?? 'No status', $waiting);
    $statusWidth = max(array_map('mb_strlen', $statuses));
    $lines = ["Maintainer's turn"];

    foreach ($waiting as $index => $card) {
        $what = isset($questions[$card['number']])
            ? rtrim('question: '.$questions[$card['number']])
            : (in_array(DECISION_LABEL, $card['labels'], true) ? 'decision card' : 'no question posted');

        $lines[] = '  '.pad_right('#'.$card['number'], $numberWidth).'  '.pad_right($statuses[$index], $statusWidth).'  '.$what;
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{rule: string, card: int|null, pr: int|null, text: string}>  $findings
 */
function section_drift(array $findings): string
{
    if ($findings === []) {
        return 'Drift: none.';
    }

    $keys = array_map(
        static fn (array $finding): string => $finding['card'] !== null ? '#'.$finding['card'] : ($finding['pr'] !== null ? 'PR #'.$finding['pr'] : 'main'),
        $findings,
    );
    $width = max(array_map('strlen', $keys));
    $lines = ['Drift ('.count($findings).')'];

    foreach ($findings as $index => $finding) {
        $lines[] = '  '.pad_right($keys[$index], $width).'  '.$finding['text'];
    }

    return implode("\n", $lines);
}

exit(main($argv));
