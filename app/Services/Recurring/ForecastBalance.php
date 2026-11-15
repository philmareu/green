<?php

namespace App\Services\Recurring;

use App\Enums\Frequency;
use App\Enums\OccurrenceResolution;
use App\Enums\RecurringKind;
use App\Models\Account;
use App\Models\Occurrence;
use App\Models\Recurring;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * Projects a cash account's balance from what's available today, using its
 * bills, income and flex spends.
 *
 * - The available balance already includes pending transactions, so a bill
 *   linked to one isn't counted again.
 * - A postponed bill or income counts on its new date.
 * - Unpaid bills past their due date count today: better to plan for them.
 * - Late income counts until its match window passes, then it's left out.
 * - A flex spend reserves what's left of this period today, and its full
 *   budget at the start of each later period.
 */
class ForecastBalance
{
    public function handle(Account $account, CarbonInterface $until): Forecast
    {
        $today = today()->toImmutable();
        $until = CarbonImmutable::parse($until)->startOfDay();
        $lines = [];

        $recurrings = Recurring::query()->where('account_id', $account->getKey())->with('category')->get();

        foreach ($recurrings as $recurring) {
            $recurring->syncOccurrences($until->max($today->addDays(Recurring::HORIZON_DAYS)));

            $occurrences = $recurring->occurrences()->expectedBy($until)->get();

            foreach ($occurrences as $occurrence) {
                $occurrence->setRelation('recurring', $recurring);

                $line = $recurring->kind === RecurringKind::Flex
                    ? $this->flexLine($occurrence, $today, $account->currency)
                    : $this->dueLine($occurrence, $today);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        // Money out before money in on the same day, to err low.
        usort($lines, fn (array $a, array $b): int => [$a['date'], $a['amount'], $a['name']] <=> [$b['date'], $b['amount'], $b['name']]);

        $starting = $account->spendableBalance() ?? 0.0;
        $balance = $starting;
        $entries = [];

        foreach ($lines as $line) {
            $balance = round($balance + $line['amount'], 2);
            $entries[] = new ForecastEntry(...$line, balance: $balance);
        }

        return new Forecast($starting, $entries, $today, $until, $account->currency);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function dueLine(Occurrence $occurrence, CarbonImmutable $today): ?array
    {
        $recurring = $occurrence->recurring;

        if (! $occurrence->isOpen()) {
            return null;
        }

        $expectedOn = $occurrence->expectedOn();
        $late = $expectedOn->lt($today);

        if ($late && ! $recurring->kind->isOutflow() && $expectedOn->lt($today->subDays($recurring->frequency->matchWindowDays()))) {
            return null;
        }

        return $this->line($occurrence, $late ? $today : $expectedOn, (float) $recurring->amount, match (true) {
            $late && $recurring->kind->isOutflow() => 'Overdue since '.$expectedOn->format('M j'),
            $late => 'Expected '.$expectedOn->format('M j'),
            $occurrence->postponed_to !== null => 'Postponed from '.$occurrence->due_on->format('M j'),
            default => null,
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function flexLine(Occurrence $occurrence, CarbonImmutable $today, string $currency): ?array
    {
        $recurring = $occurrence->recurring;
        $periodEnd = $occurrence->periodEnd() ?? $occurrence->due_on->addDay();

        if ($occurrence->resolution === OccurrenceResolution::Skipped || $periodEnd->lte($today)) {
            return null;
        }

        if ($occurrence->due_on->gt($today)) {
            return $this->line($occurrence, $occurrence->due_on, (float) $recurring->amount, 'Budget until '.$periodEnd->subDay()->format('M j'));
        }

        $spent = $occurrence->spent();
        $left = max(0.0, round((float) $recurring->amount - $spent, 2));

        if ($left <= 0) {
            return null;
        }

        return $this->line($occurrence, $today, $left, sprintf(
            '%s of %s spent so far',
            Number::currency($spent, $currency),
            Number::currency((float) $recurring->amount, $currency),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(Occurrence $occurrence, CarbonImmutable $date, float $amount, ?string $note): array
    {
        $recurring = $occurrence->recurring;

        return [
            'date' => $date,
            'name' => $recurring->name,
            'kind' => $recurring->kind,
            'amount' => $recurring->kind->isOutflow() ? -$amount : $amount,
            'occurrenceId' => $occurrence->getKey(),
            'recurringId' => $recurring->getKey(),
            'isOneTime' => $recurring->frequency === Frequency::Once,
            'isPostponed' => $occurrence->postponed_to !== null,
            'note' => $note,
        ];
    }
}
