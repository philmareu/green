<?php

namespace App\Services\Categories;

use App\Enums\BookKind;
use App\Models\CategorySet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggested categories for a new set, which users then edit freely. The
 * business list follows the expense lines on IRS Schedule C (Form 1040), so a
 * sole proprietor's totals line up with their tax return.
 */
class StarterCategories
{
    /**
     * Category names by Book kind and category type. A name maps to its subcategories.
     *
     * @var array<string, array<string, array<string, list<string>>>>
     */
    private const array CATEGORIES = [
        'business' => [
            'income' => [
                'Sales and services' => [],
                'Other income' => [],
            ],
            'expense' => [
                'Advertising' => [],
                'Car and truck' => [],
                'Commissions and fees' => [],
                'Contract labor' => [],
                'Employee benefits' => [],
                'Insurance' => [],
                'Interest' => ['Mortgage', 'Other interest'],
                'Legal and professional services' => [],
                'Office expense' => [],
                'Retirement plans' => [],
                'Rent or lease' => ['Vehicles and equipment', 'Business property'],
                'Repairs and maintenance' => [],
                'Supplies' => [],
                'Taxes and licenses' => [],
                'Travel and meals' => ['Travel', 'Meals'],
                'Utilities' => [],
                'Wages' => [],
                'Other expenses' => ['Software and subscriptions', 'Bank and payment fees', 'Dues and memberships', 'Education and training'],
            ],
            'transfer' => [
                'Owner’s draw' => [],
                'Owner contribution' => [],
                'Transfer between accounts' => [],
                'Credit card payment' => [],
            ],
        ],
        'personal' => [
            'income' => [
                'Paycheck' => [],
                'Interest and dividends' => [],
                'Refunds and reimbursements' => [],
                'Other income' => [],
            ],
            'expense' => [
                'Housing' => ['Rent or mortgage', 'Utilities', 'Internet and phone', 'Maintenance', 'Home insurance'],
                'Food' => ['Groceries', 'Restaurants', 'Coffee'],
                'Transportation' => ['Fuel', 'Parking and tolls', 'Public transit', 'Rideshare and taxis', 'Car payment', 'Car insurance', 'Car maintenance'],
                'Health' => ['Medical', 'Pharmacy', 'Health insurance', 'Fitness'],
                'Shopping' => ['Clothing', 'Electronics', 'Household'],
                'Entertainment' => ['Streaming and subscriptions', 'Events', 'Hobbies'],
                'Travel' => ['Flights', 'Lodging'],
                'Personal care' => [],
                'Education' => [],
                'Pets' => [],
                'Gifts and donations' => [],
                'Fees and interest' => ['Bank fees', 'Interest charges'],
                'Taxes' => [],
                'Other expenses' => [],
            ],
            'transfer' => [
                'Transfer between accounts' => [],
                'Credit card payment' => [],
                'Savings and investments' => [],
            ],
        ],
    ];

    public static function describe(BookKind $kind): string
    {
        return match ($kind) {
            BookKind::Business => 'Based on the expense lines of IRS Schedule C.',
            BookKind::Personal => 'Common household income and spending.',
        };
    }

    public function addTo(CategorySet $set): void
    {
        DB::transaction(function () use ($set): void {
            foreach (self::CATEGORIES[$set->kind->value] as $type => $categories) {
                foreach ($categories as $name => $children) {
                    $parent = $set->categories()->create(['name' => $name, 'type' => $type]);

                    foreach ($children as $child) {
                        $set->categories()->create(['name' => $child, 'type' => $type, 'parent_id' => $parent->getKey()]);
                    }
                }
            }
        });
    }

    /**
     * Give a user one starter set per Book kind, used by their Books that have no set yet.
     *
     * @return Collection<int, CategorySet>
     */
    public function createSetsFor(User $user): Collection
    {
        return DB::transaction(fn (): Collection => collect(BookKind::cases())->map(function (BookKind $kind) use ($user): CategorySet {
            $set = $user->categorySets()->create(['name' => $kind->getLabel(), 'kind' => $kind]);

            $this->addTo($set);

            $user->books()->where('kind', $kind)->whereNull('category_set_id')->update(['category_set_id' => $set->getKey()]);

            return $set;
        }));
    }
}
