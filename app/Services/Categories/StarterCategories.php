<?php

namespace App\Services\Categories;

use App\Enums\BookKind;
use App\Models\Book;
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
     * Categories by Book kind and category type. Each category maps to a list
     * whose plain values are Plaid categories it is suggested for and whose
     * string keys are its subcategories (each mapping to its Plaid categories).
     *
     * @var array<string, array<string, array<string, array<int|string, string|list<string>>>>>
     */
    private const array CATEGORIES = [
        'business' => [
            'income' => [
                'Sales and services' => ['INCOME', 'TRANSFER_IN_DEPOSIT', 'TRANSFER_IN_WIRE'],
                'Other income' => ['INCOME_INTEREST_EARNED', 'INCOME_DIVIDENDS', 'INCOME_RENTAL'],
            ],
            'expense' => [
                'Advertising' => [],
                'Car and truck' => ['TRANSPORTATION_GAS', 'TRANSPORTATION_PARKING', 'TRANSPORTATION_TOLLS', 'GENERAL_SERVICES_AUTOMOTIVE'],
                'Commissions and fees' => [],
                'Contract labor' => [],
                'Employee benefits' => [],
                'Insurance' => ['GENERAL_SERVICES_INSURANCE'],
                'Interest' => ['Mortgage' => [], 'Other interest' => ['BANK_FEES_INTEREST_CHARGE']],
                'Legal and professional services' => ['GENERAL_SERVICES_ACCOUNTING_AND_FINANCIAL_PLANNING', 'GENERAL_SERVICES_CONSULTING_AND_LEGAL'],
                'Office expense' => ['GENERAL_MERCHANDISE_OFFICE_SUPPLIES', 'GENERAL_SERVICES_POSTAGE_AND_SHIPPING'],
                'Retirement plans' => [],
                'Rent or lease' => ['Vehicles and equipment' => [], 'Business property' => ['RENT_AND_UTILITIES_RENT', 'GENERAL_SERVICES_STORAGE']],
                'Repairs and maintenance' => ['HOME_IMPROVEMENT_REPAIR_AND_MAINTENANCE'],
                'Supplies' => ['GENERAL_MERCHANDISE', 'HOME_IMPROVEMENT'],
                'Taxes and licenses' => ['GOVERNMENT_AND_NON_PROFIT_GOVERNMENT_DEPARTMENTS_AND_AGENCIES'],
                'Travel and meals' => [
                    'Travel' => ['TRAVEL', 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES', 'TRANSPORTATION_PUBLIC_TRANSIT'],
                    'Meals' => ['FOOD_AND_DRINK'],
                ],
                'Utilities' => ['RENT_AND_UTILITIES'],
                'Wages' => [],
                'Other expenses' => [
                    'Software and subscriptions' => [],
                    'Hosting and infrastructure' => [],
                    'AI services' => [],
                    'Bank and payment fees' => ['BANK_FEES'],
                    'Dues and memberships' => [],
                    'Education and training' => ['GENERAL_SERVICES_EDUCATION'],
                ],
            ],
            'transfer' => [
                'Owner’s draw' => [],
                'Owner contribution' => [],
                'Transfer between accounts' => ['TRANSFER_IN', 'TRANSFER_OUT'],
                'Credit card payment' => ['LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
            ],
        ],
        'personal' => [
            'income' => [
                'Paycheck' => ['INCOME_SALARY', 'INCOME_CONTRACTOR', 'INCOME_GIG_ECONOMY', 'INCOME_MILITARY'],
                'Interest and dividends' => ['INCOME_INTEREST_EARNED', 'INCOME_DIVIDENDS'],
                'Refunds and reimbursements' => ['INCOME_TAX_REFUND'],
                'Other income' => ['INCOME'],
            ],
            'expense' => [
                'Housing' => [
                    'Rent or mortgage' => ['RENT_AND_UTILITIES_RENT', 'LOAN_PAYMENTS_MORTGAGE_PAYMENT'],
                    'Utilities' => ['RENT_AND_UTILITIES'],
                    'Internet and phone' => ['RENT_AND_UTILITIES_INTERNET_AND_CABLE', 'RENT_AND_UTILITIES_TELEPHONE'],
                    'Maintenance' => ['HOME_IMPROVEMENT'],
                    'Home insurance' => [],
                ],
                'Food' => [
                    'FOOD_AND_DRINK',
                    'Groceries' => ['FOOD_AND_DRINK_GROCERIES'],
                    'Restaurants' => ['FOOD_AND_DRINK_RESTAURANT', 'FOOD_AND_DRINK_FAST_FOOD'],
                    'Coffee' => ['FOOD_AND_DRINK_COFFEE'],
                ],
                'Transportation' => [
                    'TRANSPORTATION',
                    'Fuel' => ['TRANSPORTATION_GAS'],
                    'Parking and tolls' => ['TRANSPORTATION_PARKING', 'TRANSPORTATION_TOLLS'],
                    'Public transit' => ['TRANSPORTATION_PUBLIC_TRANSIT'],
                    'Rideshare and taxis' => ['TRANSPORTATION_TAXIS_AND_RIDE_SHARES'],
                    'Car payment' => ['LOAN_PAYMENTS_CAR_PAYMENT'],
                    'Car insurance' => [],
                    'Car maintenance' => ['GENERAL_SERVICES_AUTOMOTIVE'],
                ],
                'Health' => [
                    'MEDICAL',
                    'Medical' => ['MEDICAL_PRIMARY_CARE', 'MEDICAL_DENTAL_CARE', 'MEDICAL_EYE_CARE', 'MEDICAL_NURSING_CARE', 'MEDICAL_OTHER_MEDICAL'],
                    'Pharmacy' => ['MEDICAL_PHARMACIES_AND_SUPPLEMENTS'],
                    'Health insurance' => [],
                    'Fitness' => ['PERSONAL_CARE_GYMS_AND_FITNESS_CENTERS'],
                ],
                'Shopping' => [
                    'GENERAL_MERCHANDISE',
                    'Clothing' => ['GENERAL_MERCHANDISE_CLOTHING_AND_ACCESSORIES'],
                    'Electronics' => ['GENERAL_MERCHANDISE_ELECTRONICS'],
                    'Household' => ['GENERAL_MERCHANDISE_SUPERSTORES', 'GENERAL_MERCHANDISE_DISCOUNT_STORES', 'GENERAL_MERCHANDISE_DEPARTMENT_STORES', 'HOME_IMPROVEMENT_FURNITURE'],
                ],
                'Entertainment' => [
                    'ENTERTAINMENT',
                    'Streaming and subscriptions' => ['ENTERTAINMENT_TV_AND_MOVIES', 'ENTERTAINMENT_MUSIC_AND_AUDIO'],
                    'Events' => ['ENTERTAINMENT_SPORTING_EVENTS_AMUSEMENT_PARKS_AND_MUSEUMS'],
                    'Hobbies' => ['ENTERTAINMENT_VIDEO_GAMES', 'GENERAL_MERCHANDISE_SPORTING_GOODS'],
                ],
                'Travel' => ['TRAVEL', 'Flights' => ['TRAVEL_FLIGHTS'], 'Lodging' => ['TRAVEL_LODGING']],
                'Personal care' => ['PERSONAL_CARE'],
                'Education' => ['GENERAL_SERVICES_EDUCATION'],
                'Pets' => ['GENERAL_MERCHANDISE_PET_SUPPLIES', 'MEDICAL_VETERINARY_SERVICES'],
                'Gifts and donations' => ['GOVERNMENT_AND_NON_PROFIT_DONATIONS', 'GENERAL_MERCHANDISE_GIFTS_AND_NOVELTIES'],
                'Loan payments' => ['LOAN_PAYMENTS'],
                'Fees and interest' => ['Bank fees' => ['BANK_FEES'], 'Interest charges' => ['BANK_FEES_INTEREST_CHARGE']],
                'Taxes' => ['GOVERNMENT_AND_NON_PROFIT_TAX_PAYMENT', 'GOVERNMENT_AND_NON_PROFIT_GOVERNMENT_DEPARTMENTS_AND_AGENCIES'],
                'Other expenses' => ['GENERAL_SERVICES', 'GOVERNMENT_AND_NON_PROFIT', 'OTHER'],
            ],
            'transfer' => [
                'Transfer between accounts' => ['TRANSFER_IN', 'TRANSFER_OUT', 'LOAN_DISBURSEMENTS'],
                'Credit card payment' => ['LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
                'Savings and investments' => [
                    'TRANSFER_IN_SAVINGS',
                    'TRANSFER_OUT_SAVINGS',
                    'TRANSFER_IN_INVESTMENT_AND_RETIREMENT_FUNDS',
                    'TRANSFER_OUT_INVESTMENT_AND_RETIREMENT_FUNDS',
                    'TRANSFER_OUT_CRYPTO',
                ],
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
                foreach ($categories as $name => $entries) {
                    $parent = $set->categories()->create([
                        'name' => $name,
                        'type' => $type,
                        'plaid_categories' => array_values(array_filter($entries, 'is_string')),
                    ]);

                    foreach (array_filter($entries, 'is_array') as $child => $plaidCategories) {
                        $set->categories()->create([
                            'name' => $child,
                            'type' => $type,
                            'parent_id' => $parent->getKey(),
                            'plaid_categories' => $plaidCategories,
                        ]);
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

            // Updated one at a time so each Book's transactions pick up suggestions.
            $user->books()->where('kind', $kind)->whereNull('category_set_id')->get()
                ->each(fn (Book $book) => $book->update(['category_set_id' => $set->getKey()]));

            return $set;
        }));
    }
}
