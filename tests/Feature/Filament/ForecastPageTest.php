<?php

use App\Enums\Frequency;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Widgets\Forecast\ForecastRegister;
use App\Filament\Widgets\Forecast\ForecastStats;
use App\Models\Account;
use App\Models\Book;
use App\Models\Recurring;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->account = Account::factory()->inBook(Book::factory()->for($this->user)->create())->create(['available_balance' => 1000]);
});

it('renders the forecast page', function () {
    $this->get('/forecast')->assertOk()->assertSee('Forecast');
});

it('forecasts your own account even when the filters name someone else’s', function () {
    Recurring::factory()->for($this->account)->create(['amount' => 89, 'starts_on' => '2026-10-25']);
    $theirs = Account::factory()->create(['available_balance' => 55555]);

    Livewire::test(ForecastStats::class, ['pageFilters' => ['account' => $theirs->id, 'weeks' => 4]])
        ->assertSee('$1,000.00')
        ->assertSee('$911.00')
        ->assertDontSee('$55,555.00');
});

it('links each register line to its recurring item', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-25']);
    $dueDate = $comcast->occurrences()->whereDate('due_on', '2026-10-25')->sole();

    Livewire::test(ForecastRegister::class, ['pageFilters' => ['account' => $this->account->id, 'weeks' => 4]])
        ->assertTableColumnExists('name', linksTo(RecurringResource::getUrl('view', ['record' => $comcast])), $dueDate->id);
});

it('adds a one-time expense to the register', function () {
    Livewire::test(ForecastRegister::class, ['pageFilters' => ['account' => $this->account->id, 'weeks' => 4]])
        ->callAction(TestAction::make('addOneTime')->table(), data: [
            'name' => 'Car repair',
            'direction' => 'out',
            'amount' => 800,
            'date' => '2026-10-30',
        ])
        ->assertHasNoFormErrors()
        ->assertSee('Car repair')
        ->assertSee('$200.00');

    expect(Recurring::sole())
        ->frequency->toBe(Frequency::Once)
        ->account_id->toBe($this->account->id)
        ->user_id->toBe($this->user->id);
});

it('postpones a bill from the register', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['amount' => 89, 'starts_on' => '2026-10-25']);
    $dueDate = $comcast->occurrences()->whereDate('due_on', '2026-10-25')->sole();

    Livewire::test(ForecastRegister::class, ['pageFilters' => ['account' => $this->account->id, 'weeks' => 4]])
        ->callAction(TestAction::make('postpone')->table($dueDate->id), data: ['postponed_to' => '2026-11-03'])
        ->assertHasNoFormErrors()
        ->call('refreshForecast')
        ->assertSee('Postponed from Oct 25');

    expect($dueDate->fresh()->postponed_to->toDateString())->toBe('2026-11-03');
});
