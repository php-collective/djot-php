<?php

declare(strict_types=1);

namespace Djot\Renderer\Utility;

/**
 * Bounds the cumulative bytes contributed by abbreviation expansion across a
 * single render, guarding against an output-amplification (memory) DoS.
 *
 * Each occurrence of an abbreviation re-emits its full definition (the `title`
 * of an `<abbr>` element, the `(definition)` suffix in ANSI, etc.). A tiny
 * source such as `*[HT]: <50KB of text>` followed by many `HT` occurrences
 * would otherwise expand to `definition_len * occurrence_count` bytes
 * (hundreds of MB), which PHP happily allocates - a true RAM-exhaustion DoS.
 *
 * Policy:
 *   budget = max(BUDGET_BASE, BUDGET_FACTOR * sourceByteLength)
 * Once the next occurrence's expansion would exceed the budget, that occurrence
 * (and every subsequent one) degrades gracefully to its plain key text only -
 * no `<abbr>` wrapper, no title. The budget sits far above any real document,
 * so normal output is byte-identical.
 *
 * The counter is reset per full render call (resetAbbreviationBudget()).
 */
trait AbbreviationBudgetTrait
{
    /**
     * Base (floor) budget in bytes, applied even for tiny sources.
     *
     * @var int
     */
    protected const ABBREVIATION_BUDGET_BASE = 1000000;

    /**
     * Multiplier applied to the source byte length.
     *
     * @var int
     */
    protected const ABBREVIATION_BUDGET_FACTOR = 8;

    /**
     * Cumulative expansion bytes already emitted in the current render.
     */
    protected int $abbreviationExpansionBytes = 0;

    /**
     * Computed budget for the current render (max of base and factor*source).
     */
    protected int $abbreviationBudget = self::ABBREVIATION_BUDGET_BASE;

    /**
     * Reset the budget counter and (re)compute the budget for a fresh render.
     */
    protected function resetAbbreviationBudget(int $sourceLength): void
    {
        $this->abbreviationExpansionBytes = 0;
        $this->abbreviationBudget = max(
            self::ABBREVIATION_BUDGET_BASE,
            self::ABBREVIATION_BUDGET_FACTOR * $sourceLength,
        );
    }

    /**
     * Charge a single abbreviation occurrence against the budget.
     *
     * @param string $expansion The definition text whose bytes are emitted.
     *
     * @return bool True if the expansion fits within budget and may be emitted
     *   (the bytes are charged); false if it would exceed the budget and the
     *   occurrence must degrade to plain key text.
     */
    protected function chargeAbbreviationExpansion(string $expansion): bool
    {
        $cost = strlen($expansion);
        if ($this->abbreviationExpansionBytes + $cost > $this->abbreviationBudget) {
            return false;
        }

        $this->abbreviationExpansionBytes += $cost;

        return true;
    }
}
