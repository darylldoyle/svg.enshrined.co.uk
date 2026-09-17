<?php

declare(strict_types=1);

namespace SvgTest;

/**
 * Line diff (Myers) with word-level refinement on changed lines.
 *
 * Emits a flat list of rows that render as either a split or a unified view,
 * so the client can switch between the two without asking the server again.
 */
final class Diff
{
    /**
     * Ceiling on the O(ND) search. The algorithm keeps one snapshot per step, so
     * memory grows with the square of this number — 700 is a few MB, 4000 is not.
     * Regions that need more edits than this are split on patience anchors first.
     */
    private const MAX_EDIT_DISTANCE = 700;

    /** Don't attempt word-level refinement on absurdly long lines. */
    private const MAX_REFINE_LENGTH = 4000;

    /**
     * @param bool $ignoreWhitespace Compare lines with their whitespace normalised.
     *                               The sanitizer reindents everything it touches, so
     *                               without this nearly every line reads as changed.
     * @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>,truncated:bool}
     */
    public static function compare(string $before, string $after, bool $ignoreWhitespace = false): array
    {
        $a = self::splitLines($before);
        $b = self::splitLines($after);

        // Line matching runs on the normalised text; the rows still carry the
        // original lines, so what you read is exactly what the sanitizer wrote.
        $keysA = $ignoreWhitespace ? array_map([self::class, 'normalise'], $a) : $a;
        $keysB = $ignoreWhitespace ? array_map([self::class, 'normalise'], $b) : $b;

        [$ops, $truncated] = self::lineOps($keysA, $keysB);

        $rows = self::pairUp($ops, $a, $b);

        $stats = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];

        foreach ($rows as $row) {
            switch ($row['op']) {
                case 'insert':
                    $stats['added']++;
                    break;
                case 'delete':
                    $stats['removed']++;
                    break;
                case 'replace':
                    $stats['changed']++;
                    break;
                default:
                    $stats['unchanged']++;
            }
        }

        return ['rows' => $rows, 'stats' => $stats, 'truncated' => $truncated];
    }

    private static function normalise(string $line): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($line));

        return $collapsed === null ? trim($line) : $collapsed;
    }

    /** @return array<int,string> */
    private static function splitLines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /**
     * Diff whole lines: shave the common prefix and suffix, then Myers the rest.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array{0:array<int,array{0:string,1:int,2:int}>,1:bool}
     */
    private static function lineOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // Sanitizer output is mostly identical to its input, so trimming the
        // matching head and tail usually leaves a tiny region to search.
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < ($n - $prefix)
            && $suffix < ($m - $prefix)
            && $a[$n - $suffix - 1] === $b[$m - $suffix - 1]
        ) {
            $suffix++;
        }

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['=', $i, $i];
        }

        [$midOps, $truncated] = self::diffRegion(
            $a,
            $b,
            $prefix,
            $n - $suffix,
            $prefix,
            $m - $suffix
        );

        foreach ($midOps as $op) {
            $ops[] = $op;
        }

        for ($i = 0; $i < $suffix; $i++) {
            $ops[] = ['=', $n - $suffix + $i, $m - $suffix + $i];
        }

        return [$ops, $truncated];
    }

    /**
     * Diff one region. Try Myers first; if the region needs more edits than we
     * are willing to search, split it on patience anchors — lines that appear
     * exactly once on each side — and Myers each segment between them.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array{0:array<int,array{0:string,1:int,2:int}>,1:bool}
     */
    private static function diffRegion(
        array $a,
        array $b,
        int $aStart,
        int $aEnd,
        int $bStart,
        int $bEnd,
        bool $allowAnchors = true
    ): array {
        $sliceA = array_slice($a, $aStart, $aEnd - $aStart);
        $sliceB = array_slice($b, $bStart, $bEnd - $bStart);

        if ($sliceA === [] || $sliceB === []) {
            return [self::blockReplace(count($sliceA), count($sliceB), $aStart, $bStart), false];
        }

        [$ops, $truncated] = self::myers($sliceA, $sliceB, $aStart, $bStart);

        if (!$truncated) {
            return [$ops, false];
        }

        if (!$allowAnchors) {
            return [$ops, true];
        }

        $anchors = self::anchors($sliceA, $sliceB);

        if ($anchors === []) {
            return [$ops, true];
        }

        $result   = [];
        $degraded = false;
        $cursorA  = $aStart;
        $cursorB  = $bStart;

        foreach ($anchors as [$offsetA, $offsetB]) {
            $anchorA = $aStart + $offsetA;
            $anchorB = $bStart + $offsetB;

            if ($anchorA > $cursorA || $anchorB > $cursorB) {
                [$segment, $segmentTruncated] = self::diffRegion(
                    $a,
                    $b,
                    $cursorA,
                    $anchorA,
                    $cursorB,
                    $anchorB,
                    false
                );

                foreach ($segment as $op) {
                    $result[] = $op;
                }

                $degraded = $degraded || $segmentTruncated;
            }

            $result[] = ['=', $anchorA, $anchorB];
            $cursorA  = $anchorA + 1;
            $cursorB  = $anchorB + 1;
        }

        if ($cursorA < $aEnd || $cursorB < $bEnd) {
            [$segment, $segmentTruncated] = self::diffRegion(
                $a,
                $b,
                $cursorA,
                $aEnd,
                $cursorB,
                $bEnd,
                false
            );

            foreach ($segment as $op) {
                $result[] = $op;
            }

            $degraded = $degraded || $segmentTruncated;
        }

        return [$result, $degraded];
    }

    /**
     * Patience anchors: lines occurring exactly once on each side and identical,
     * reduced to the longest run whose positions increase on both sides.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array{0:int,1:int}>
     */
    private static function anchors(array $a, array $b): array
    {
        $countA = array_count_values(array_map('strval', $a));
        $countB = array_count_values(array_map('strval', $b));

        $indexB = [];
        foreach ($b as $j => $line) {
            if (($countB[$line] ?? 0) === 1) {
                $indexB[$line] = $j;
            }
        }

        $candidates = [];
        foreach ($a as $i => $line) {
            if (($countA[$line] ?? 0) === 1 && isset($indexB[$line]) && trim($line) !== '') {
                $candidates[] = [$i, $indexB[$line]];
            }
        }

        if ($candidates === []) {
            return [];
        }

        // Longest increasing subsequence over the B positions, with back-links
        // so we can walk the chosen chain back out.
        $tails     = [];
        $tailIndex = [];
        $previous  = [];

        foreach ($candidates as $c => [, $bPos]) {
            $low  = 0;
            $high = count($tails);

            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                if ($tails[$mid] < $bPos) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            $tails[$low]     = $bPos;
            $tailIndex[$low] = $c;
            $previous[$c]    = $low > 0 ? $tailIndex[$low - 1] : null;
        }

        $chain   = [];
        $current = $tailIndex[count($tails) - 1];

        while ($current !== null) {
            $chain[]  = $candidates[$current];
            $current  = $previous[$current];
        }

        return array_reverse($chain);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array{0:array<int,array{0:string,1:int,2:int}>,1:bool}
     */
    private static function myers(array $a, array $b, int $offsetA, int $offsetB): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [[], false];
        }

        $max = $n + $m;

        if ($max > self::MAX_EDIT_DISTANCE * 2) {
            return [self::blockReplace($n, $m, $offsetA, $offsetB), true];
        }

        $v     = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                $down = ($k === -$d) || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1));
                $x    = $down ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y    = $x - $k;

                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    return [self::backtrack($trace, $n, $m, $offsetA, $offsetB), false];
                }
            }

            if ($d >= self::MAX_EDIT_DISTANCE) {
                return [self::blockReplace($n, $m, $offsetA, $offsetB), true];
            }
        }

        return [self::blockReplace($n, $m, $offsetA, $offsetB), true];
    }

    /**
     * @param array<int,array<int,int>> $trace
     * @return array<int,array{0:string,1:int,2:int}>
     */
    private static function backtrack(array $trace, int $x, int $y, int $offsetA, int $offsetB): array
    {
        $ops = [];

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;

            $down  = ($k === -$d) || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1));
            $prevK = $down ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $x--;
                $y--;
                $ops[] = ['=', $offsetA + $x, $offsetB + $y];
            }

            if ($d > 0) {
                if ($x > $prevX) {
                    $ops[] = ['-', $offsetA + $prevX, -1];
                } else {
                    $ops[] = ['+', -1, $offsetB + $prevY];
                }
            }

            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }

    /** @return array<int,array{0:string,1:int,2:int}> */
    private static function blockReplace(int $n, int $m, int $offsetA, int $offsetB): array
    {
        $ops = [];

        for ($i = 0; $i < $n; $i++) {
            $ops[] = ['-', $offsetA + $i, -1];
        }

        for ($j = 0; $j < $m; $j++) {
            $ops[] = ['+', -1, $offsetB + $j];
        }

        return $ops;
    }

    /**
     * Turn adjacent delete/insert runs into `replace` rows so the UI can show a
     * line as modified rather than as an unrelated removal next to an addition.
     *
     * @param array<int,array{0:string,1:int,2:int}> $ops
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array<string,mixed>>
     */
    private static function pairUp(array $ops, array $a, array $b): array
    {
        $rows  = [];
        $count = count($ops);
        $i     = 0;

        while ($i < $count) {
            $op = $ops[$i];

            if ($op[0] === '=') {
                $rows[] = [
                    'op'    => 'equal',
                    'aLine' => $op[1] + 1,
                    'bLine' => $op[2] + 1,
                    'a'     => $a[$op[1]] ?? '',
                    'b'     => $b[$op[2]] ?? '',
                ];
                $i++;
                continue;
            }

            $deletes = [];
            $inserts = [];

            while ($i < $count && $ops[$i][0] === '-') {
                $deletes[] = $ops[$i][1];
                $i++;
            }

            while ($i < $count && $ops[$i][0] === '+') {
                $inserts[] = $ops[$i][2];
                $i++;
            }

            $pairs = min(count($deletes), count($inserts));

            for ($p = 0; $p < $pairs; $p++) {
                $aText = $a[$deletes[$p]] ?? '';
                $bText = $b[$inserts[$p]] ?? '';

                $row = [
                    'op'    => 'replace',
                    'aLine' => $deletes[$p] + 1,
                    'bLine' => $inserts[$p] + 1,
                    'a'     => $aText,
                    'b'     => $bText,
                ];

                $refined = self::refine($aText, $bText);
                if ($refined !== null) {
                    $row['aParts'] = $refined[0];
                    $row['bParts'] = $refined[1];
                }

                $rows[] = $row;
            }

            for ($p = $pairs; $p < count($deletes); $p++) {
                $rows[] = [
                    'op'    => 'delete',
                    'aLine' => $deletes[$p] + 1,
                    'bLine' => null,
                    'a'     => $a[$deletes[$p]] ?? '',
                    'b'     => null,
                ];
            }

            for ($p = $pairs; $p < count($inserts); $p++) {
                $rows[] = [
                    'op'    => 'insert',
                    'aLine' => null,
                    'bLine' => $inserts[$p] + 1,
                    'a'     => null,
                    'b'     => $b[$inserts[$p]] ?? '',
                ];
            }
        }

        return $rows;
    }

    /**
     * Word-level diff of two changed lines.
     *
     * @return array{0:array<int,array{0:string,1:string}>,1:array<int,array{0:string,1:string}>}|null
     */
    private static function refine(string $a, string $b): ?array
    {
        if ($a === '' || $b === '') {
            return null;
        }

        if (strlen($a) > self::MAX_REFINE_LENGTH || strlen($b) > self::MAX_REFINE_LENGTH) {
            return null;
        }

        $tokensA = self::tokenise($a);
        $tokensB = self::tokenise($b);

        [$ops, $truncated] = self::myers($tokensA, $tokensB, 0, 0);

        if ($truncated) {
            return null;
        }

        $partsA = [];
        $partsB = [];

        foreach ($ops as $op) {
            if ($op[0] === '=') {
                self::pushPart($partsA, 'eq', $tokensA[$op[1]] ?? '');
                self::pushPart($partsB, 'eq', $tokensB[$op[2]] ?? '');
            } elseif ($op[0] === '-') {
                self::pushPart($partsA, 'diff', $tokensA[$op[1]] ?? '');
            } else {
                self::pushPart($partsB, 'diff', $tokensB[$op[2]] ?? '');
            }
        }

        return [$partsA, $partsB];
    }

    /**
     * Split on SVG-ish boundaries so highlights land on attributes and tag
     * names rather than on arbitrary character runs.
     *
     * @return array<int,string>
     */
    private static function tokenise(string $line): array
    {
        $tokens = preg_split(
            '/(\s+|[<>="\'\/;:,()])/',
            $line,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        return $tokens === false ? [$line] : $tokens;
    }

    /**
     * @param array<int,array{0:string,1:string}> $parts
     */
    private static function pushPart(array &$parts, string $type, string $value): void
    {
        if ($value === '') {
            return;
        }

        $last = count($parts) - 1;

        if ($last >= 0 && $parts[$last][0] === $type) {
            $parts[$last][1] .= $value;

            return;
        }

        $parts[] = [$type, $value];
    }
}
