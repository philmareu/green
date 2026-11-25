<?php

namespace Database\Seeders;

/**
 * What the demo's banks report, and what its user taught Green about it.
 * `DemoSeeder` turns these rows into transactions; the payments of bills and
 * income are made there, next to each bill.
 *
 * Accounts go by the keys `DemoSeeder::banks()` gives them. Amounts keep
 * Plaid's sign: positive is money out. Plaid categories are detailed codes
 * from `PlaidCategories`, or null where Plaid had none.
 */
final class DemoStatements
{
    /**
     * Payees on categories, by Book kind: a transaction whose description
     * contains one gets that category, whatever Plaid filed it under. The
     * business categories here have no Plaid category of their own, and
     * Plaid files Costco under superstores and Chewy under marketplaces.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const array PAYEES = [
        'business' => [
            'Software and subscriptions' => ['github', 'adobe', 'slack', 'google workspace', 'zoom', 'laracasts'],
            'Hosting and infrastructure' => ['amazon web services', 'digitalocean'],
            'AI services' => ['openai', 'anthropic'],
            'Advertising' => ['linkedin ads'],
        ],
        'personal' => [
            'Groceries' => ['costco'],
            'Pets' => ['chewy'],
        ],
    ];

    /**
     * Everyday spending, drawn at random from a fixed seed. Each row: the
     * merchant, its Plaid category, the lowest and highest amount, and about
     * how many days pass between purchases. A sixth value is how many days
     * ago the latest one was, for merchants that must stay clear of something
     * else: groceries and restaurants stop where their budgets begin, and
     * daycare and AWS have a charge pending today.
     *
     * Figma and Notion match no payee and no Plaid category the business set
     * uses, so they stay uncategorized.
     *
     * @var array<string, list<array{0: string, 1: string, 2: float, 3: float, 4: int, 5?: int}>>
     */
    public const array SPENDING = [
        'everyday' => [
            ['Starbucks', 'FOOD_AND_DRINK_COFFEE', 4.25, 8.95, 4],
            ['CVS Pharmacy', 'MEDICAL_PHARMACIES_AND_SUPPLEMENTS', 8.00, 42.00, 15],
            ['ParkMobile', 'TRANSPORTATION_PARKING', 2.50, 12.00, 11],
            ['Venmo', 'TRANSFER_OUT_TRANSFER_OUT_FROM_APPS', 15.00, 60.00, 19],
            ['Great Clips', 'PERSONAL_CARE_HAIR_AND_BEAUTY', 24.00, 30.00, 36],
            ['Planet Fitness', 'PERSONAL_CARE_GYMS_AND_FITNESS_CENTERS', 24.99, 24.99, 30],
            ['Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 38.00, 118.00, 4, 33],
            ['Chipotle', 'FOOD_AND_DRINK_FAST_FOOD', 11.00, 46.00, 5, 38],
        ],
        'joint' => [
            ['Little Sprouts Daycare', 'GENERAL_SERVICES_CHILDCARE', 85.00, 85.00, 7, 7],
            ['Healthy Paws', 'GENERAL_SERVICES_INSURANCE', 38.50, 38.50, 30],
        ],
        'visa' => [
            ['Amazon', 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES', 12.00, 95.00, 6],
            ['Target', 'GENERAL_MERCHANDISE_SUPERSTORES', 18.00, 120.00, 12],
            ['Uber', 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES', 9.00, 34.00, 9],
            ['Sweetgreen', 'FOOD_AND_DRINK_FAST_FOOD', 12.00, 19.00, 10],
            ['AMC Theatres', 'ENTERTAINMENT_TV_AND_MOVIES', 14.00, 32.00, 24],
            ['Chewy', 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES', 41.00, 67.00, 29],
            ['Spotify', 'ENTERTAINMENT_MUSIC_AND_AUDIO', 11.99, 11.99, 30],
        ],
        'business' => [
            ['Stripe', 'TRANSFER_IN_DEPOSIT', -1650.00, -380.00, 9],
            ['USPS', 'GENERAL_SERVICES_POSTAGE_AND_SHIPPING', 8.00, 28.00, 20],
            ['Gusto', 'GENERAL_SERVICES_ACCOUNTING_AND_FINANCIAL_PLANNING', 46.00, 46.00, 30],
            ['Hiscox', 'GENERAL_SERVICES_INSURANCE', 62.00, 62.00, 30],
        ],
        'businessCard' => [
            ['GitHub', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 21.00, 21.00, 30],
            ['Adobe', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 59.99, 59.99, 30],
            ['Slack', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 17.50, 17.50, 30],
            ['Google Workspace', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 14.40, 14.40, 30],
            ['Zoom', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 15.99, 15.99, 30],
            ['Laracasts', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 15.00, 15.00, 30],
            ['OpenAI', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 20.00, 20.00, 30],
            ['Anthropic', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 100.00, 100.00, 30],
            ['DigitalOcean', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 24.00, 24.00, 30],
            ['Amazon Web Services', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 118.00, 164.00, 30, 30],
            ['LinkedIn Ads', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 150.00, 150.00, 30],
            ['Figma', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 15.00, 15.00, 30],
            ['Notion', 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 10.00, 10.00, 30],
            ['Uber', 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES', 12.00, 46.00, 12],
            ['The Capital Grille', 'FOOD_AND_DRINK_RESTAURANT', 48.00, 136.00, 16],
            ['Staples', 'GENERAL_MERCHANDISE_OFFICE_SUPPLIES', 14.00, 88.00, 21],
            ['Amazon', 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES', 11.00, 140.00, 10],
        ],
    ];

    /**
     * The purchases on Everyday Checking that its three flex spends count, so
     * these amounts are exact. Each row: days ago, merchant, Plaid category,
     * amount.
     *
     * @var list<array{int, string, string, float}>
     */
    public const array BUDGETED = [
        // Groceries, $160 a week. This week began 4 days ago: $84.62 so far.
        [4, 'Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 52.37],
        [1, 'Whole Foods Market', 'FOOD_AND_DRINK_GROCERIES', 32.25],
        // Last week: $152.13, so $7.87 rolls over. Costco counts through its payee.
        [10, 'Costco', 'GENERAL_MERCHANDISE_SUPERSTORES', 96.40],
        [7, 'Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 55.73],
        // Two weeks ago: $139.90, so $20.10 rolls over.
        [17, 'Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 61.15],
        [14, 'Whole Foods Market', 'FOOD_AND_DRINK_GROCERIES', 78.75],
        // Three weeks ago: $171.45, which takes $11.45 away.
        [24, 'Costco', 'GENERAL_MERCHANDISE_SUPERSTORES', 118.22],
        [21, 'Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 53.23],
        // Four weeks ago, the first week of the budget: $148.20, so $11.80 rolls over.
        [31, 'Whole Foods Market', 'FOOD_AND_DRINK_GROCERIES', 84.90],
        [27, 'Trader Joe\'s', 'FOOD_AND_DRINK_GROCERIES', 63.30],

        // Eating out, $120 every two weeks. This period began 9 days ago:
        // $118.30 so far, against the $104.50 left after what rolled over.
        [8, 'Chipotle', 'FOOD_AND_DRINK_FAST_FOOD', 14.85],
        [6, 'Olive Garden', 'FOOD_AND_DRINK_RESTAURANT', 46.20],
        [3, 'DoorDash', 'FOOD_AND_DRINK_RESTAURANT', 31.75],
        [1, 'Shake Shack', 'FOOD_AND_DRINK_FAST_FOOD', 25.50],
        // The period before: $141.60, which takes $21.60 away.
        [22, 'Olive Garden', 'FOOD_AND_DRINK_RESTAURANT', 38.40],
        [19, 'Chipotle', 'FOOD_AND_DRINK_FAST_FOOD', 16.25],
        [15, 'The Cheesecake Factory', 'FOOD_AND_DRINK_RESTAURANT', 52.70],
        [12, 'DoorDash', 'FOOD_AND_DRINK_RESTAURANT', 34.25],
        // The first period of the budget: $113.90, so $6.10 rolls over.
        [36, 'Shake Shack', 'FOOD_AND_DRINK_FAST_FOOD', 27.80],
        [31, 'DoorDash', 'FOOD_AND_DRINK_RESTAURANT', 44.10],
        [26, 'Panera Bread', 'FOOD_AND_DRINK_RESTAURANT', 42.00],

        // Fuel, $145 a month, with no rollover. This month began 16 days ago:
        // $124.85 so far.
        [15, 'Shell', 'TRANSPORTATION_GAS', 38.90],
        [9, 'Chevron', 'TRANSPORTATION_GAS', 44.75],
        [2, 'Shell', 'TRANSPORTATION_GAS', 41.20],
        // Earlier months.
        [23, 'Chevron', 'TRANSPORTATION_GAS', 40.10],
        [31, 'Shell', 'TRANSPORTATION_GAS', 43.65],
        [40, 'Shell', 'TRANSPORTATION_GAS', 39.80],
        [49, 'Chevron', 'TRANSPORTATION_GAS', 45.20],
        [58, 'Shell', 'TRANSPORTATION_GAS', 42.35],
        [67, 'Chevron', 'TRANSPORTATION_GAS', 38.60],
        [76, 'Shell', 'TRANSPORTATION_GAS', 44.05],
        [85, 'Chevron', 'TRANSPORTATION_GAS', 41.75],
    ];

    /**
     * Transfers, checks, fees and one-off purchases. Each row: account, days
     * ago (or several), the bank's description, amount, Plaid category, and
     * the merchant when Plaid named one. Both sides of a transfer between the
     * demo's accounts are listed.
     *
     * @var list<array{0: string, 1: int|list<int>, 2: string, 3: float, 4: ?string, 5?: string}>
     */
    public const array OTHER = [
        ['everyday', [12, 26, 40, 54, 68, 82], 'ONLINE TRANSFER TO CHK ...7310', 170.00, 'TRANSFER_OUT_ACCOUNT_TRANSFER'],
        ['joint', [12, 26, 40, 54, 68, 82], 'ONLINE TRANSFER FROM CHK ...4242', -170.00, 'TRANSFER_IN_ACCOUNT_TRANSFER'],
        ['everyday', [35, 65], 'ACH TRANSFER TO TATTERSALL FCU', 250.00, 'TRANSFER_OUT_SAVINGS'],
        ['savings', [35, 65], 'ACH TRANSFER FROM FIRST PLATYPUS BANK', -250.00, 'TRANSFER_IN_SAVINGS'],
        ['savings', 24, 'DIVIDEND', -4.31, 'INCOME_DIVIDENDS'],
        ['savings', 55, 'DIVIDEND', -4.12, 'INCOME_DIVIDENDS'],
        ['savings', 85, 'DIVIDEND', -3.94, 'INCOME_DIVIDENDS'],
        ['everyday', [20, 58], 'ATM WITHDRAWAL 00412', 80.00, 'TRANSFER_OUT_WITHDRAWAL'],
        // Plaid can't categorize a check. The user picked a category for one of these.
        ['everyday', 47, 'CHECK 1043', 185.00, null],
        ['everyday', 16, 'CHECK 1044', 60.00, null],
        ['visa', 41, 'DELTA AIR LINES', 318.40, 'TRAVEL_FLIGHTS', 'Delta Air Lines'],
        ['visa', 39, 'AIRBNB', 412.00, 'TRAVEL_LODGING', 'Airbnb'],
        ['visa', 22, 'REI', 129.95, 'GENERAL_MERCHANDISE_SPORTING_GOODS', 'REI'],

        ['business', [21, 52, 82], 'MONTHLY SERVICE FEE', 15.00, 'BANK_FEES_OTHER_BANK_FEES'],
        ['business', 38, 'INITECH LLC INV 2087', -3200.00, 'INCOME_OTHER'],
        ['business', 71, 'INITECH LLC INV 2061', -2400.00, 'INCOME_OTHER'],
        ['business', 44, 'BEAN AND LEDGER CPA', 350.00, 'GENERAL_SERVICES_ACCOUNTING_AND_FINANCIAL_PLANNING', 'Bean & Ledger CPA'],
        // The card's autopay isn't a recurring item yet: try "Make recurring" on one.
        ['business', 17, 'HOUNDSTOOTH CARD AUTOPAY', 1184.22, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['businessCard', 17, 'AUTOPAY PAYMENT - THANK YOU', -1184.22, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['business', 47, 'HOUNDSTOOTH CARD AUTOPAY', 1402.67, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['businessCard', 47, 'AUTOPAY PAYMENT - THANK YOU', -1402.67, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['business', 78, 'HOUNDSTOOTH CARD AUTOPAY', 986.10, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['businessCard', 78, 'AUTOPAY PAYMENT - THANK YOU', -986.10, 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
        ['businessCard', 60, 'LARACON US', 749.00, 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 'Laracon US'],
        ['businessCard', 38, 'DELTA AIR LINES', 412.30, 'TRAVEL_FLIGHTS', 'Delta Air Lines'],
        ['businessCard', 35, 'MARRIOTT', 318.76, 'TRAVEL_LODGING', 'Marriott'],
        ['businessCard', 27, 'APPLE STORE', 1299.00, 'GENERAL_MERCHANDISE_ELECTRONICS', 'Apple Store'],
    ];

    /**
     * Authorized but not posted yet. Each row: account, days ago, merchant,
     * amount, Plaid category. The daycare charge is what takes Joint Checking
     * below zero. (Comcast's pending payment is made with its bill.)
     *
     * @var list<array{string, int, string, float, string}>
     */
    public const array PENDING = [
        ['everyday', 0, 'Starbucks', 6.45, 'FOOD_AND_DRINK_COFFEE'],
        ['everyday', 1, 'CVS Pharmacy', 23.18, 'MEDICAL_PHARMACIES_AND_SUPPLEMENTS'],
        ['joint', 0, 'Little Sprouts Daycare', 85.00, 'GENERAL_SERVICES_CHILDCARE'],
        ['visa', 0, 'Amazon', 34.99, 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES'],
        ['visa', 1, 'Uber', 18.72, 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES'],
        ['business', 0, 'USPS', 18.40, 'GENERAL_SERVICES_POSTAGE_AND_SHIPPING'],
        ['business', 1, 'FedEx Office', 31.60, 'GENERAL_SERVICES_POSTAGE_AND_SHIPPING'],
        ['businessCard', 0, 'Amazon Web Services', 142.18, 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES'],
        ['businessCard', 1, 'Uber', 27.45, 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES'],
    ];

    /**
     * Categories the user picked by hand: account, the bank's description
     * (the latest transaction with it), and the category.
     *
     * @var list<array{string, string, string}>
     */
    public const array PICKED = [
        ['everyday', 'CHECK 1043', 'Maintenance'],
        ['everyday', 'VENMO', 'Gifts and donations'],
        ['visa', 'REI', 'Gifts and donations'],
        ['businessCard', 'LARACON US', 'Education and training'],
        ['businessCard', 'APPLE STORE', 'Office expense'],
    ];
}
