<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;

/**
 * Smart Cart Optimizer — minimises the FINAL amount paid by the customer:
 *
 *     total = Σ_offers unitPrice·qty  +  Σ_vendors shipping_v(subtotal_v)
 *
 * where shipping_v is the vendor's (destination specific) step function: base cost, optional reduced cost above a
 * reduced threshold, free above a free threshold, and 0 when the vendor is not used.
 *
 * Hard constraints
 *  - exact product identity: an offer can only serve a basket line with the SAME (id_product, id_product_attribute);
 *    the engine never substitutes a different reference or variant;
 *  - each line's quantity is fully allocated (possibly split across several vendors), never exceeding offer stock;
 *  - vendors that do not deliver to the destination (country / postcode exclusion) are discarded up-front.
 *
 * Algorithm
 *  1. Pre-processing: merge duplicate lines, drop offers out of stock / not deliverable, detect unavailable lines.
 *  2. Heuristic (always run, gives the incumbent): multi-start greedy (cheapest-offer start, "prefer vendor v" and
 *     "avoid vendor v" starts) + local search with
 *       a) unit/chunk transfer moves between offers of the same line (amounts: 1, all, "just enough to reach the next
 *          free/reduced threshold" of the receiving vendor, "as much as keeps the giving vendor in its tier"),
 *       b) vendor eviction (empty a vendor completely, then re-optimise) — captures consolidation savings,
 *       c) vendor pull (move everything possible to a vendor, then re-optimise) — captures threshold savings.
 *     Cost per pass O(L·k²) for L lines and k offers per line; starts are capped (maxStarts) and the whole heuristic
 *     is time boxed (heuristicTimeBudgetMs, default 1.5 s — the first start always completes its transfer search).
 *  3. Exact branch-and-bound (when L ≤ exactMaxLines and every line has ≤ maxCompositionsPerLine stock-feasible
 *     quantity splits): depth-first over lines, each node enumerates all feasible splits of the line's quantity over
 *     its offers (cheapest first), pruning with the admissible bound
 *         LB = items so far + Σ remaining min item cost + Σ_used vendors shipping_v(subtotal_v + max remaining spend at v)
 *     (valid because shipping is non-increasing in the subtotal). Seeded with the heuristic incumbent; a node budget
 *     guards worst cases — if it is exhausted the best solution found is returned with provenOptimal = false.
 *     Worst case O(Π_l splits_l) but in practice a few hundred/thousand nodes for typical baskets (≤ 12 lines).
 */
final class CartOptimizer
{
    private const EPS = 0.000001;

    // ---- per-call working state (arrays indexed by ints for speed) ----
    /** @var array<int, array{0:int,1:int}> line => [idProduct, idProductAttribute] */
    private array $lineProd = [];
    /** @var int[] */
    private array $lineQty = [];
    /** @var array<int, int[]> line => offer indexes */
    private array $lineOffers = [];
    /** @var int[] */
    private array $oLine = [];
    /** @var int[] offer => vendor index */
    private array $oVendor = [];
    /** @var float[] */
    private array $oPrice = [];
    /** @var int[] usable stock (≤ line quantity) */
    private array $oCap = [];
    /** @var OfferCandidate[] */
    private array $oCand = [];
    /** @var int[] vendor index => idVendor */
    private array $vId = [];
    /** @var ShippingRule[] vendor index => effective rule for destination */
    private array $vRule = [];
    /** @var array<int, string|null> */
    private array $vName = [];

    // ---- branch-and-bound state ----
    /** @var int[] */
    private array $bbOrder = [];
    /** @var array<int, array<int, array{0:float,1:array<int,float>,2:array<int,int>}>> */
    private array $bbComps = [];
    /** @var float[] */
    private array $bbSufMin = [];
    /** @var array<int, float[]> */
    private array $bbSufMaxSpend = [];
    /** @var float[] */
    private array $bbSub = [];
    /** @var int[] */
    private array $bbX = [];
    private float $bbBestTotal = INF;
    /** @var int[] */
    private array $bbBestX = [];
    private int $bbNodes = 0;
    private bool $bbAborted = false;

    public function __construct(
        private readonly ShippingRuleResolver $resolver = new ShippingRuleResolver(),
        private readonly int $exactMaxLines = 12,
        private readonly int $maxCompositionsPerLine = 2000,
        private readonly int $nodeBudget = 400000,
        private readonly bool $allowPartial = false,
        private readonly int $maxStarts = 12,
        private readonly int $heuristicTimeBudgetMs = 1500,
    ) {
    }

    private float $deadline = INF;
    private bool $deadlineHit = false;

    private function timeUp(): bool
    {
        if (!$this->deadlineHit && hrtime(true) > $this->deadline) {
            $this->deadlineHit = true;
        }

        return $this->deadlineHit;
    }

    /**
     * @param BasketLine[]     $lines
     * @param OfferCandidate[] $offers         any offers; only exact (id_product, id_product_attribute) matches are used
     * @param ShippingPolicy[] $vendorPolicies one per vendor (missing vendor = free shipping everywhere)
     *
     * @throws UnavailableProductException when a line cannot be fully supplied (unless allowPartial)
     */
    public function optimize(array $lines, array $offers, array $vendorPolicies, Destination $dest): AllocationResult
    {
        $t0 = hrtime(true);
        $this->resetState();

        $policies = [];
        foreach ($vendorPolicies as $p) {
            if (!$p instanceof ShippingPolicy) {
                throw new \InvalidArgumentException('vendorPolicies must contain ShippingPolicy objects');
            }
            $policies[$p->idVendor] = $p;
        }

        // 1. merge duplicate lines (same product + variant)
        $merged = [];
        foreach ($lines as $line) {
            if (!$line instanceof BasketLine) {
                throw new \InvalidArgumentException('lines must contain BasketLine objects');
            }
            $k = $line->key();
            if (isset($merged[$k])) {
                $merged[$k][2] += $line->quantity;
            } else {
                $merged[$k] = [$line->idProduct, $line->idProductAttribute, $line->quantity];
            }
        }

        // 2. filter offers: exact identity, stock, delivery eligibility
        $excluded = [];
        $byKey = [];
        $ruleCache = [];
        foreach ($offers as $o) {
            if (!$o instanceof OfferCandidate) {
                throw new \InvalidArgumentException('offers must contain OfferCandidate objects');
            }
            $k = $o->key();
            if (!isset($merged[$k])) {
                continue; // offer for another product/variant: never substituted
            }
            if ($o->availableQuantity <= 0) {
                $excluded[] = ['id_offer' => $o->idOffer, 'id_vendor' => $o->idVendor, 'reason' => 'out_of_stock'];
                continue;
            }
            if (!array_key_exists($o->idVendor, $ruleCache)) {
                $rules = isset($policies[$o->idVendor]) ? $policies[$o->idVendor]->rules : [];
                $ruleCache[$o->idVendor] = $this->resolver->effectiveRule($rules, $dest, $o->idVendor);
            }
            if ($ruleCache[$o->idVendor] === null) {
                $excluded[] = ['id_offer' => $o->idOffer, 'id_vendor' => $o->idVendor, 'reason' => 'no_delivery_to_destination'];
                continue;
            }
            $byKey[$k][] = $o;
        }

        // 3. availability + build indexed model
        $unavailable = [];
        $vIndex = [];
        foreach ($merged as $k => [$idP, $idA, $qty]) {
            $cands = $byKey[$k] ?? [];
            $avail = 0;
            foreach ($cands as $o) {
                $avail += $o->availableQuantity;
            }
            if ($avail < $qty) {
                $unavailable[] = ['id_product' => $idP, 'id_product_attribute' => $idA, 'requested' => $qty, 'available' => $avail];
                if (!$this->allowPartial || $avail === 0) {
                    continue;
                }
                $qty = $avail;
            }
            $l = count($this->lineQty);
            $this->lineProd[$l] = [$idP, $idA];
            $this->lineQty[$l] = $qty;
            $this->lineOffers[$l] = [];
            usort($cands, static fn (OfferCandidate $a, OfferCandidate $b) => [$a->unitPrice, $a->idOffer] <=> [$b->unitPrice, $b->idOffer]);
            foreach ($cands as $o) {
                if (!isset($vIndex[$o->idVendor])) {
                    $v = count($this->vId);
                    $vIndex[$o->idVendor] = $v;
                    $this->vId[$v] = $o->idVendor;
                    $this->vRule[$v] = $ruleCache[$o->idVendor];
                    $this->vName[$v] = isset($policies[$o->idVendor]) ? $policies[$o->idVendor]->vendorName : null;
                }
                $i = count($this->oPrice);
                $this->oLine[$i] = $l;
                $this->oVendor[$i] = $vIndex[$o->idVendor];
                $this->oPrice[$i] = $o->unitPrice;
                $this->oCap[$i] = min($o->availableQuantity, $qty);
                $this->oCand[$i] = $o;
                $this->lineOffers[$l][] = $i;
            }
        }
        if ($unavailable !== [] && !$this->allowPartial) {
            throw new UnavailableProductException($unavailable);
        }

        if ($this->lineQty === []) {
            return new AllocationResult([], 0.0, 0.0, AllocationResult::MODE_EXACT, true, 0, $this->ms($t0), [], ['Empty basket: nothing to allocate.'], $excluded, $unavailable);
        }

        // 4. heuristic incumbent (time boxed: the cheapest-offer start + its local search always complete)
        $this->deadline = hrtime(true) + $this->heuristicTimeBudgetMs * 1e6;
        $this->deadlineHit = false;
        [$x, $total, $starts] = $this->heuristic();
        $mode = AllocationResult::MODE_HEURISTIC;
        $proven = false;
        $nodes = 0;
        $exactReason = $this->exactIneligibilityReason();

        // 5. exact branch-and-bound
        if ($exactReason === null) {
            $this->branchAndBound($x, $total);
            $nodes = $this->bbNodes;
            if ($this->bbBestTotal < $total - self::EPS) {
                $x = $this->bbBestX;
                $total = $this->bbBestTotal;
            }
            if (!$this->bbAborted) {
                $mode = AllocationResult::MODE_EXACT;
                $proven = true;
            } else {
                $exactReason = sprintf('branch-and-bound node budget (%d) exhausted', $this->nodeBudget);
            }
        }

        return $this->buildResult($x, $mode, $proven, $nodes, $starts, $exactReason, $excluded, $unavailable, $dest, $t0);
    }

    // ------------------------------------------------------------------ cost helpers

    private function ship(int $v, float $subtotal): float
    {
        return ShippingRuleResolver::cost($this->vRule[$v], $subtotal);
    }

    /** @param int[] $x */
    private function subtotals(array $x): array
    {
        $sub = array_fill(0, count($this->vId), 0.0);
        foreach ($x as $o => $q) {
            if ($q > 0) {
                $sub[$this->oVendor[$o]] += $q * $this->oPrice[$o];
            }
        }

        return $sub;
    }

    /** @param int[] $x */
    private function evaluate(array $x): float
    {
        $total = 0.0;
        foreach ($this->subtotals($x) as $v => $s) {
            $total += $s + $this->ship($v, $s);
        }

        return $total;
    }

    // ------------------------------------------------------------------ heuristic

    /** @return array{0:int[],1:float,2:int} [x, total, number of starts] */
    private function heuristic(): array
    {
        $V = count($this->vId);
        $starts = [array_fill(0, $V, 1)]; // cheapest-offer greedy

        // rank vendors by coverage (lines they can serve) then by item value, to choose which ones get dedicated starts
        $coverage = array_fill(0, $V, 0);
        foreach ($this->lineOffers as $offs) {
            $seen = [];
            foreach ($offs as $o) {
                $seen[$this->oVendor[$o]] = true;
            }
            foreach ($seen as $v => $_) {
                ++$coverage[$v];
            }
        }
        $vendors = range(0, $V - 1);
        usort($vendors, static fn ($a, $b) => $coverage[$b] <=> $coverage[$a] ?: $a <=> $b);
        $perKind = max(1, intdiv($this->maxStarts - 1, 2));
        foreach (array_slice($vendors, 0, $perKind) as $v) {
            $rank = array_fill(0, $V, 1);
            $rank[$v] = 0;
            $starts[] = $rank;
        }
        if ($V > 1) {
            foreach (array_slice($vendors, 0, $perKind) as $v) {
                $rank = array_fill(0, $V, 1);
                $rank[$v] = 2;
                $starts[] = $rank;
            }
        }

        $bestX = [];
        $bestTotal = INF;
        $seen = [];
        $count = 0;
        foreach ($starts as $rank) {
            if ($count > 0 && $this->timeUp()) {
                break;
            }
            $x = $this->greedy($rank);
            $sig = implode(',', $x);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            ++$count;
            $x = $this->localSearch($x, true);
            $t = $this->evaluate($x);
            if ($t < $bestTotal - self::EPS) {
                $bestTotal = $t;
                $bestX = $x;
            }
        }

        return [$bestX, $bestTotal, $count];
    }

    /**
     * Fill each line from offers ordered by (vendor rank, price).
     *
     * @param int[] $rank vendor index => rank (lower = preferred)
     *
     * @return int[]
     */
    private function greedy(array $rank): array
    {
        $x = array_fill(0, count($this->oPrice), 0);
        foreach ($this->lineOffers as $l => $offs) {
            usort($offs, fn ($a, $b) => [$rank[$this->oVendor[$a]], $this->oPrice[$a], $a] <=> [$rank[$this->oVendor[$b]], $this->oPrice[$b], $b]);
            $need = $this->lineQty[$l];
            foreach ($offs as $o) {
                if ($need <= 0) {
                    break;
                }
                $take = min($need, $this->oCap[$o]);
                $x[$o] = $take;
                $need -= $take;
            }
        }

        return $x;
    }

    /**
     * @param int[] $x
     *
     * @return int[]
     */
    private function localSearch(array $x, bool $withVendorMoves): array
    {
        $sub = $this->subtotals($x);
        $total = $this->evaluate($x);
        for ($iter = 0; $iter < 200; ++$iter) {
            $improved = $this->transferPass($x, $sub, $total);

            if ($withVendorMoves && !$this->timeUp()) {
                $V = count($this->vId);
                for ($v = 0; $v < $V && !$this->timeUp(); ++$v) {
                    foreach ([true, false] as $evict) {
                        if ($evict && $sub[$v] <= self::EPS) {
                            continue;
                        }
                        $y = $evict ? $this->evict($x, $v) : $this->pull($x, $v);
                        if ($y === null || $y === $x) {
                            continue;
                        }
                        $y = $this->localSearch($y, false);
                        $t = $this->evaluate($y);
                        if ($t < $total - self::EPS) {
                            $x = $y;
                            $total = $t;
                            $sub = $this->subtotals($x);
                            $improved = true;
                        }
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        return $x;
    }

    /**
     * One first-improvement pass of quantity transfers between offers of the same line.
     *
     * @param int[]   $x
     * @param float[] $sub
     */
    private function transferPass(array &$x, array &$sub, float &$total): bool
    {
        $improved = false;
        foreach ($this->lineOffers as $offs) {
            if (count($offs) < 2) {
                continue;
            }
            foreach ($offs as $i) {
                foreach ($offs as $j) {
                    if ($i === $j || $x[$i] <= 0) {
                        continue;
                    }
                    $cap = $this->oCap[$j] - $x[$j];
                    if ($cap <= 0) {
                        continue;
                    }
                    $max = min($x[$i], $cap);
                    $bestM = 0;
                    $bestD = -self::EPS;
                    foreach ($this->candidateAmounts($i, $j, $max, $sub) as $m) {
                        $d = $this->moveDelta($i, $j, $m, $sub);
                        if ($d < $bestD) {
                            $bestD = $d;
                            $bestM = $m;
                        }
                    }
                    if ($bestM > 0) {
                        $vi = $this->oVendor[$i];
                        $vj = $this->oVendor[$j];
                        $x[$i] -= $bestM;
                        $x[$j] += $bestM;
                        $sub[$vi] -= $bestM * $this->oPrice[$i];
                        $sub[$vj] += $bestM * $this->oPrice[$j];
                        $total += $bestD;
                        $improved = true;
                    }
                }
            }
        }

        return $improved;
    }

    /** @return int[] */
    private function candidateAmounts(int $i, int $j, int $max, array $sub): array
    {
        $amounts = [1 => true, $max => true];
        $vj = $this->oVendor[$j];
        $pj = $this->oPrice[$j];
        $rj = $this->vRule[$vj];
        foreach ([$rj->freeThreshold, $rj->reducedThreshold] as $thr) {
            if ($thr !== null && $pj > 0 && $thr > $sub[$vj] + self::EPS) {
                $m = (int) ceil(($thr - $sub[$vj] - self::EPS) / $pj);
                if ($m >= 1 && $m <= $max) {
                    $amounts[$m] = true;
                }
            }
        }
        $vi = $this->oVendor[$i];
        $pi = $this->oPrice[$i];
        $ri = $this->vRule[$vi];
        foreach ([$ri->freeThreshold, $ri->reducedThreshold] as $thr) {
            if ($thr !== null && $pi > 0 && $sub[$vi] + self::EPS >= $thr) {
                $m = (int) floor(($sub[$vi] - $thr + self::EPS) / $pi);
                if ($m >= 1 && $m <= $max) {
                    $amounts[$m] = true;
                }
            }
        }

        return array_keys($amounts);
    }

    private function moveDelta(int $i, int $j, int $m, array $sub): float
    {
        $vi = $this->oVendor[$i];
        $vj = $this->oVendor[$j];
        $di = $m * $this->oPrice[$i];
        $dj = $m * $this->oPrice[$j];
        if ($vi === $vj) {
            return ($dj - $di) + $this->ship($vi, $sub[$vi] - $di + $dj) - $this->ship($vi, $sub[$vi]);
        }

        return ($dj - $di)
            + $this->ship($vi, $sub[$vi] - $di) - $this->ship($vi, $sub[$vi])
            + $this->ship($vj, $sub[$vj] + $dj) - $this->ship($vj, $sub[$vj]);
    }

    /**
     * Remove vendor v entirely, re-placing its units on the cheapest other offers (preferring vendors already used).
     *
     * @param int[] $x
     *
     * @return int[]|null null when other vendors lack stock
     */
    private function evict(array $x, int $v): ?array
    {
        $sub = $this->subtotals($x);
        foreach ($this->lineOffers as $offs) {
            $need = 0;
            foreach ($offs as $o) {
                if ($this->oVendor[$o] === $v && $x[$o] > 0) {
                    $need += $x[$o];
                    $x[$o] = 0;
                }
            }
            if ($need === 0) {
                continue;
            }
            $others = array_values(array_filter($offs, fn ($o) => $this->oVendor[$o] !== $v));
            usort($others, fn ($a, $b) => [$sub[$this->oVendor[$a]] > self::EPS ? 0 : 1, $this->oPrice[$a]] <=> [$sub[$this->oVendor[$b]] > self::EPS ? 0 : 1, $this->oPrice[$b]]);
            foreach ($others as $o) {
                $take = min($need, $this->oCap[$o] - $x[$o]);
                if ($take > 0) {
                    $x[$o] += $take;
                    $need -= $take;
                }
                if ($need === 0) {
                    break;
                }
            }
            if ($need > 0) {
                return null;
            }
        }

        return $x;
    }

    /**
     * Move as many units as stock allows onto vendor v (taking from the most expensive other offers first).
     *
     * @param int[] $x
     *
     * @return int[]
     */
    private function pull(array $x, int $v): array
    {
        foreach ($this->lineOffers as $offs) {
            $mine = array_values(array_filter($offs, fn ($o) => $this->oVendor[$o] === $v));
            if ($mine === []) {
                continue;
            }
            $others = array_values(array_filter($offs, fn ($o) => $this->oVendor[$o] !== $v && $x[$o] > 0));
            usort($others, fn ($a, $b) => $this->oPrice[$b] <=> $this->oPrice[$a]);
            foreach ($mine as $m) {
                foreach ($others as $o) {
                    $take = min($x[$o], $this->oCap[$m] - $x[$m]);
                    if ($take > 0) {
                        $x[$o] -= $take;
                        $x[$m] += $take;
                    }
                }
            }
        }

        return $x;
    }

    // ------------------------------------------------------------------ exact branch-and-bound

    private function exactIneligibilityReason(): ?string
    {
        $L = count($this->lineQty);
        if ($L > $this->exactMaxLines) {
            return sprintf('basket has %d distinct lines (> %d exact limit)', $L, $this->exactMaxLines);
        }
        foreach ($this->lineOffers as $l => $offs) {
            $caps = array_map(fn ($o) => $this->oCap[$o], $offs);
            if (self::countSplits($caps, $this->lineQty[$l], $this->maxCompositionsPerLine) > $this->maxCompositionsPerLine) {
                return sprintf('a line has more than %d possible quantity splits', $this->maxCompositionsPerLine);
            }
        }

        return null;
    }

    /** Number of ways to split $qty over offers with the given caps (stops counting above $limit). */
    public static function countSplits(array $caps, int $qty, int $limit = PHP_INT_MAX): int
    {
        $ways = array_fill(0, $qty + 1, 0);
        $ways[0] = 1;
        foreach ($caps as $cap) {
            $next = array_fill(0, $qty + 1, 0);
            for ($s = 0; $s <= $qty; ++$s) {
                if ($ways[$s] === 0) {
                    continue;
                }
                for ($t = 0; $t <= $cap && $s + $t <= $qty; ++$t) {
                    $next[$s + $t] = min($limit + 1, $next[$s + $t] + $ways[$s]);
                }
            }
            $ways = $next;
        }

        return $ways[$qty];
    }

    /** @param int[] $incumbentX */
    private function branchAndBound(array $incumbentX, float $incumbentTotal): void
    {
        $L = count($this->lineQty);
        $V = count($this->vId);

        // order: lines with the largest value at stake first (they drive thresholds), fewer options first on ties
        $order = range(0, $L - 1);
        $weight = [];
        foreach ($order as $l) {
            $maxP = 0.0;
            foreach ($this->lineOffers[$l] as $o) {
                $maxP = max($maxP, $this->oPrice[$o]);
            }
            $weight[$l] = $maxP * $this->lineQty[$l];
        }
        usort($order, fn ($a, $b) => [$weight[$b], count($this->lineOffers[$a])] <=> [$weight[$a], count($this->lineOffers[$b])]);
        $this->bbOrder = $order;

        $this->bbComps = [];
        $minItems = [];
        $maxSpend = [];
        foreach ($order as $d => $l) {
            $comps = [];
            $this->enumerateSplits($this->lineOffers[$l], 0, $this->lineQty[$l], [], $comps);
            usort($comps, static fn ($a, $b) => $a[0] <=> $b[0]);
            $this->bbComps[$d] = $comps;
            $minItems[$d] = $comps[0][0];
            $ms = array_fill(0, $V, 0.0);
            foreach ($comps as $c) {
                foreach ($c[1] as $v => $amt) {
                    if ($amt > $ms[$v]) {
                        $ms[$v] = $amt;
                    }
                }
            }
            $maxSpend[$d] = $ms;
        }
        $this->bbSufMin = array_fill(0, $L + 1, 0.0);
        $this->bbSufMaxSpend = [$L => array_fill(0, $V, 0.0)];
        for ($d = $L - 1; $d >= 0; --$d) {
            $this->bbSufMin[$d] = $this->bbSufMin[$d + 1] + $minItems[$d];
            $row = $this->bbSufMaxSpend[$d + 1];
            foreach ($maxSpend[$d] as $v => $amt) {
                $row[$v] += $amt;
            }
            $this->bbSufMaxSpend[$d] = $row;
        }

        $this->bbBestX = $incumbentX;
        $this->bbBestTotal = $incumbentTotal;
        $this->bbNodes = 0;
        $this->bbAborted = false;
        $this->bbSub = array_fill(0, $V, 0.0);
        $this->bbX = array_fill(0, count($this->oPrice), 0);
        $this->dfs(0, 0.0);
    }

    /**
     * Enumerate all stock-feasible splits of $remaining units over $offs[$k..].
     *
     * @param int[] $offs
     * @param array<int,int> $alloc
     * @param array<int, array{0:float,1:array<int,float>,2:array<int,int>}> $out
     */
    private function enumerateSplits(array $offs, int $k, int $remaining, array $alloc, array &$out): void
    {
        if ($remaining === 0) {
            $cost = 0.0;
            $spend = [];
            foreach ($alloc as $o => $q) {
                $c = $q * $this->oPrice[$o];
                $cost += $c;
                $v = $this->oVendor[$o];
                $spend[$v] = ($spend[$v] ?? 0.0) + $c;
            }
            $out[] = [$cost, $spend, $alloc];

            return;
        }
        if ($k >= count($offs)) {
            return;
        }
        $o = $offs[$k];
        $restCap = 0;
        for ($r = $k + 1, $n = count($offs); $r < $n; ++$r) {
            $restCap += $this->oCap[$offs[$r]];
        }
        $max = min($this->oCap[$o], $remaining);
        $min = max(0, $remaining - $restCap);
        for ($q = $max; $q >= $min; --$q) {
            $next = $alloc;
            if ($q > 0) {
                $next[$o] = $q;
            }
            $this->enumerateSplits($offs, $k + 1, $remaining - $q, $next, $out);
        }
    }

    private function dfs(int $d, float $items): void
    {
        if ($this->bbAborted) {
            return;
        }
        if (++$this->bbNodes > $this->nodeBudget) {
            $this->bbAborted = true;

            return;
        }
        $L = count($this->bbOrder);
        if ($d === $L) {
            $total = $items;
            foreach ($this->bbSub as $v => $s) {
                $total += $this->ship($v, $s);
            }
            if ($total < $this->bbBestTotal - self::EPS) {
                $this->bbBestTotal = $total;
                $this->bbBestX = $this->bbX;
            }

            return;
        }
        // admissible lower bound
        $lb = $items + $this->bbSufMin[$d];
        $suf = $this->bbSufMaxSpend[$d];
        foreach ($this->bbSub as $v => $s) {
            if ($s > self::EPS) {
                $lb += $this->ship($v, $s + $suf[$v]);
            }
        }
        if ($lb >= $this->bbBestTotal - self::EPS) {
            return;
        }
        foreach ($this->bbComps[$d] as [$cost, $spend, $alloc]) {
            if ($items + $cost + $this->bbSufMin[$d + 1] >= $this->bbBestTotal - self::EPS) {
                break; // compositions are sorted by cost: the rest cannot do better on items alone
            }
            foreach ($spend as $v => $amt) {
                $this->bbSub[$v] += $amt;
            }
            foreach ($alloc as $o => $q) {
                $this->bbX[$o] = $q;
            }
            $this->dfs($d + 1, $items + $cost);
            foreach ($alloc as $o => $q) {
                $this->bbX[$o] = 0;
            }
            foreach ($spend as $v => $amt) {
                $this->bbSub[$v] -= $amt;
            }
            if ($this->bbAborted) {
                return;
            }
        }
    }

    // ------------------------------------------------------------------ result + explanation

    /** @param int[] $x */
    private function buildResult(array $x, string $mode, bool $proven, int $nodes, int $starts, ?string $exactReason, array $excluded, array $unavailable, Destination $dest, int|float $t0): AllocationResult
    {
        $sub = $this->subtotals($x);
        $byVendor = [];
        foreach ($this->lineOffers as $l => $offs) {
            foreach ($offs as $o) {
                if ($x[$o] > 0) {
                    $c = $this->oCand[$o];
                    $byVendor[$this->oVendor[$o]][] = new AllocatedLine($c->idProduct, $c->idProductAttribute, $c->idOffer, $c->idVendor, $x[$o], $c->unitPrice, $c->vendorSku);
                }
            }
        }
        ksort($byVendor);
        $vendors = [];
        $items = 0.0;
        $shipping = 0.0;
        foreach ($byVendor as $v => $lines) {
            $s = $sub[$v];
            $sh = $this->ship($v, $s);
            $items += $s;
            $shipping += $sh;
            $vendors[] = new VendorAllocation($this->vId[$v], $lines, $s, $sh, ShippingRuleResolver::tier($this->vRule[$v], $s), $this->vName[$v]);
        }
        usort($vendors, static fn (VendorAllocation $a, VendorAllocation $b) => $a->idVendor <=> $b->idVendor);
        $total = $items + $shipping;

        // alternatives
        $alternatives = [];
        $cheapX = $this->greedy(array_fill(0, count($this->vId), 1));
        $cheapSub = $this->subtotals($cheapX);
        $cheapItems = array_sum($cheapSub);
        $cheapTotal = $this->evaluate($cheapX);
        $alternatives[] = [
            'label' => 'Cheapest offer for each item (ignoring shipping)',
            'total' => $cheapTotal,
            'feasible' => true,
            'vendors' => $this->usedVendorIds($cheapX),
            'note' => sprintf('items %s + shipping %s', self::money($cheapItems), self::money($cheapTotal - $cheapItems)),
        ];
        $bestSingle = null;
        foreach ($this->vId as $v => $idVendor) {
            $sx = $this->singleVendor($v);
            $label = 'Everything from ' . ($this->vName[$v] ?? 'vendor #' . $idVendor);
            if ($sx === null) {
                $alternatives[] = ['label' => $label, 'total' => null, 'feasible' => false, 'vendors' => [$idVendor], 'note' => 'vendor does not offer / stock every item'];
                continue;
            }
            $st = $this->evaluate($sx);
            $alternatives[] = ['label' => $label, 'total' => $st, 'feasible' => true, 'vendors' => [$idVendor]];
            if ($bestSingle === null || $st < $bestSingle[1]) {
                $bestSingle = [$label, $st];
            }
        }
        $alternatives[] = ['label' => 'Optimal allocation (selected)', 'total' => $total, 'feasible' => true, 'vendors' => array_map(static fn (VendorAllocation $v) => $v->idVendor, $vendors)];

        // explanation
        $units = array_sum($this->lineQty);
        $exp = [];
        $exp[] = sprintf(
            'Basket: %d line(s), %d unit(s); %d eligible offer(s) from %d vendor(s) delivering to country #%d%s.',
            count($this->lineQty), $units, count($this->oPrice), count($this->vId), $dest->countryId, $dest->postcode !== '' ? ' ' . $dest->postcode : ''
        );
        if ($excluded !== []) {
            $exp[] = sprintf('%d offer(s) excluded (out of stock or vendor does not deliver to this destination).', count($excluded));
        }
        if ($mode === AllocationResult::MODE_EXACT) {
            $exp[] = sprintf('Exact branch-and-bound over every vendor/quantity split (%d node(s) explored, seeded by %d heuristic start(s)): optimum proven.', $nodes, $starts);
        } else {
            $exp[] = sprintf('Heuristic: %d greedy start(s) + local search (transfers, vendor eviction/consolidation) because %s%s.', $starts, $exactReason ?? 'exact search not applicable', $this->deadlineHit ? sprintf('; time budget of %d ms reached', $this->heuristicTimeBudgetMs) : '');
        }
        $parts = array_map(static fn (VendorAllocation $va) => sprintf(
            '%s: items %s + shipping %s (%s)',
            $va->vendorName ?? 'vendor #' . $va->idVendor, self::money($va->subtotal), self::money($va->shippingCost), $va->shippingTier
        ), $vendors);
        $exp[] = sprintf('Selected: %s = items %s + shipping %s across %d vendor(s) — %s.', self::money($total), self::money($items), self::money($shipping), count($vendors), implode('; ', $parts));
        if ($bestSingle !== null && $bestSingle[1] > $total + self::EPS) {
            $exp[] = sprintf('Saves %s vs. the best single-vendor order (%s: %s).', self::money($bestSingle[1] - $total), $bestSingle[0], self::money($bestSingle[1]));
        }
        if ($cheapTotal > $total + self::EPS) {
            $exp[] = sprintf('Saves %s vs. picking the cheapest offer per item (%s).', self::money($cheapTotal - $total), self::money($cheapTotal));
        }
        if ($unavailable !== []) {
            $exp[] = sprintf('%d line(s) could not be fully supplied (partial allocation).', count($unavailable));
        }

        return new AllocationResult($vendors, $items, $shipping, $mode, $proven, $nodes, $this->ms($t0), $alternatives, $exp, $excluded, $unavailable);
    }

    /** @return int[]|null */
    private function singleVendor(int $v): ?array
    {
        $x = array_fill(0, count($this->oPrice), 0);
        foreach ($this->lineOffers as $l => $offs) {
            $need = $this->lineQty[$l];
            foreach ($offs as $o) { // already sorted by price
                if ($this->oVendor[$o] !== $v || $need === 0) {
                    continue;
                }
                $take = min($need, $this->oCap[$o]);
                $x[$o] = $take;
                $need -= $take;
            }
            if ($need > 0) {
                return null;
            }
        }

        return $x;
    }

    /** @return int[] */
    private function usedVendorIds(array $x): array
    {
        $ids = [];
        foreach ($x as $o => $q) {
            if ($q > 0) {
                $ids[$this->vId[$this->oVendor[$o]]] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    private static function money(float $v): string
    {
        return '€' . number_format($v, 2, '.', '');
    }

    private function ms(int|float $t0): float
    {
        return (hrtime(true) - $t0) / 1e6;
    }

    private function resetState(): void
    {
        $this->lineProd = $this->lineQty = $this->lineOffers = [];
        $this->oLine = $this->oVendor = $this->oPrice = $this->oCap = $this->oCand = [];
        $this->vId = $this->vRule = $this->vName = [];
        $this->bbOrder = $this->bbComps = $this->bbSufMin = $this->bbSufMaxSpend = $this->bbSub = $this->bbX = $this->bbBestX = [];
        $this->bbBestTotal = INF;
        $this->bbNodes = 0;
        $this->bbAborted = false;
    }
}
