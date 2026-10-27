<?php

namespace App\Services\Categories;

use Illuminate\Support\Str;

/**
 * Plaid's personal finance categories (PFC v2), which Green requests on every
 * sync. A category can be matched by its primary code ("FOOD_AND_DRINK") or a
 * detailed code ("FOOD_AND_DRINK_GROCERIES").
 *
 * @see https://plaid.com/documents/pfc-taxonomy-all.csv
 */
class PlaidCategories
{
    /** @var array<string, list<string>> Detailed codes, without their primary prefix, by primary code. */
    private const array TAXONOMY = [
        'INCOME' => ['CHILD_SUPPORT', 'CONTRACTOR', 'DIVIDENDS', 'GIG_ECONOMY', 'INTEREST_EARNED', 'LONG_TERM_DISABILITY', 'MILITARY', 'RENTAL', 'RETIREMENT_PENSION', 'SALARY', 'TAX_REFUND', 'UNEMPLOYMENT', 'OTHER'],
        'LOAN_DISBURSEMENTS' => ['AUTO', 'CASH_ADVANCES', 'EWA', 'MORTGAGE', 'PERSONAL', 'STUDENT', 'OTHER_DISBURSEMENT'],
        'LOAN_PAYMENTS' => ['BNPL', 'CAR_PAYMENT', 'CASH_ADVANCES', 'CREDIT_CARD_PAYMENT', 'EWA', 'MORTGAGE_PAYMENT', 'PERSONAL_LOAN_PAYMENT', 'STUDENT_LOAN_PAYMENT', 'OTHER_PAYMENT'],
        'TRANSFER_IN' => ['ACCOUNT_TRANSFER', 'DEPOSIT', 'INVESTMENT_AND_RETIREMENT_FUNDS', 'SAVINGS', 'TRANSFER_IN_FROM_APPS', 'WIRE', 'OTHER_TRANSFER_IN'],
        'TRANSFER_OUT' => ['ACCOUNT_TRANSFER', 'CRYPTO', 'INVESTMENT_AND_RETIREMENT_FUNDS', 'SAVINGS', 'TRANSFER_OUT_FROM_APPS', 'WIRE', 'WITHDRAWAL', 'OTHER_TRANSFER_OUT'],
        'BANK_FEES' => ['ATM_FEES', 'INSUFFICIENT_FUNDS', 'INTEREST_CHARGE', 'FOREIGN_TRANSACTION_FEES', 'OVERDRAFT_FEES', 'LATE_FEES', 'CASH_ADVANCE', 'OTHER_BANK_FEES'],
        'ENTERTAINMENT' => ['CASINOS_AND_GAMBLING', 'MUSIC_AND_AUDIO', 'SPORTING_EVENTS_AMUSEMENT_PARKS_AND_MUSEUMS', 'TV_AND_MOVIES', 'VIDEO_GAMES', 'OTHER_ENTERTAINMENT'],
        'FOOD_AND_DRINK' => ['BEER_WINE_AND_LIQUOR', 'COFFEE', 'FAST_FOOD', 'GROCERIES', 'RESTAURANT', 'VENDING_MACHINES', 'OTHER_FOOD_AND_DRINK'],
        'GENERAL_MERCHANDISE' => ['BOOKSTORES_AND_NEWSSTANDS', 'CLOTHING_AND_ACCESSORIES', 'CONVENIENCE_STORES', 'DEPARTMENT_STORES', 'DISCOUNT_STORES', 'ELECTRONICS', 'GIFTS_AND_NOVELTIES', 'OFFICE_SUPPLIES', 'ONLINE_MARKETPLACES', 'PET_SUPPLIES', 'SPORTING_GOODS', 'SUPERSTORES', 'TOBACCO_AND_VAPE', 'OTHER_GENERAL_MERCHANDISE'],
        'HOME_IMPROVEMENT' => ['FURNITURE', 'HARDWARE', 'REPAIR_AND_MAINTENANCE', 'SECURITY', 'OTHER_HOME_IMPROVEMENT'],
        'MEDICAL' => ['DENTAL_CARE', 'EYE_CARE', 'NURSING_CARE', 'PHARMACIES_AND_SUPPLEMENTS', 'PRIMARY_CARE', 'VETERINARY_SERVICES', 'OTHER_MEDICAL'],
        'PERSONAL_CARE' => ['GYMS_AND_FITNESS_CENTERS', 'HAIR_AND_BEAUTY', 'LAUNDRY_AND_DRY_CLEANING', 'OTHER_PERSONAL_CARE'],
        'GENERAL_SERVICES' => ['ACCOUNTING_AND_FINANCIAL_PLANNING', 'AUTOMOTIVE', 'CHILDCARE', 'CONSULTING_AND_LEGAL', 'EDUCATION', 'INSURANCE', 'POSTAGE_AND_SHIPPING', 'STORAGE', 'OTHER_GENERAL_SERVICES'],
        'GOVERNMENT_AND_NON_PROFIT' => ['DONATIONS', 'GOVERNMENT_DEPARTMENTS_AND_AGENCIES', 'TAX_PAYMENT', 'OTHER_GOVERNMENT_AND_NON_PROFIT'],
        'TRANSPORTATION' => ['BIKES_AND_SCOOTERS', 'GAS', 'PARKING', 'PUBLIC_TRANSIT', 'TAXIS_AND_RIDE_SHARES', 'TOLLS', 'OTHER_TRANSPORTATION'],
        'TRAVEL' => ['FLIGHTS', 'LODGING', 'RENTAL_CARS', 'OTHER_TRAVEL'],
        'RENT_AND_UTILITIES' => ['GAS_AND_ELECTRICITY', 'INTERNET_AND_CABLE', 'RENT', 'SEWAGE_AND_WASTE_MANAGEMENT', 'TELEPHONE', 'WATER', 'OTHER_UTILITIES'],
        'OTHER' => ['OTHER'],
    ];

    /**
     * Options for picking Plaid categories, grouped by primary category.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        return collect(self::TAXONOMY)
            ->mapWithKeys(fn (array $details, string $primary): array => [
                static::humanize($primary) => [$primary => 'Any '.Str::lower(static::humanize($primary))]
                    + collect($details)->mapWithKeys(fn (string $detail): array => ["{$primary}_{$detail}" => static::humanize($detail)])->all(),
            ])
            ->all();
    }

    /**
     * "FOOD_AND_DRINK" → "Food and drink"; "FOOD_AND_DRINK_GROCERIES" → "Food and drink › Groceries".
     */
    public static function label(string $code): string
    {
        foreach (self::TAXONOMY as $primary => $details) {
            if ($code === $primary) {
                return static::humanize($primary);
            }

            if (str_starts_with($code, "{$primary}_") && in_array(Str::after($code, "{$primary}_"), $details, true)) {
                return static::humanize($primary).' › '.static::humanize(Str::after($code, "{$primary}_"));
            }
        }

        return static::humanize($code);
    }

    protected static function humanize(string $code): string
    {
        $words = array_map(
            fn (string $word): string => in_array($word, ['ATM', 'BNPL', 'EWA', 'TV'], true) ? $word : Str::lower($word),
            explode('_', $code),
        );

        return Str::ucfirst(implode(' ', $words));
    }
}
