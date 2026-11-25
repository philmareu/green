<?php

use App\Models\Account;
use App\Models\Book;
use App\Models\CategorySet;
use App\Models\Recurring;

/*
 * The forms already refuse another user's records. These rules sit in the
 * models, behind the forms, because several queries find rows through an
 * account or a Book alone.
 */

beforeEach(function () {
    $this->mine = Book::factory()->create();
    $this->theirs = Book::factory()->create();
});

it('refuses a recurring item on another user’s account', function () {
    $theirAccount = Account::factory()->inBook($this->theirs)->create();

    expect(fn () => Recurring::factory()->for($theirAccount)->for($this->mine->user)->create())
        ->toThrow(InvalidArgumentException::class, 'That account belongs to another user.');

    $myItem = Recurring::factory()->for(Account::factory()->inBook($this->mine)->create())->create();

    expect(fn () => $myItem->update(['account_id' => $theirAccount->id]))
        ->toThrow(InvalidArgumentException::class)
        ->and(Recurring::count())->toBe(1);
});

it('refuses to put an account in another user’s Book', function () {
    $myAccount = Account::factory()->inBook($this->mine)->create();

    expect(fn () => $myAccount->update(['book_id' => $this->theirs->id]))
        ->toThrow(InvalidArgumentException::class, 'That Book belongs to another user.')
        ->and($myAccount->fresh()->book_id)->toBe($this->mine->id);

    $myAccount->update(['book_id' => null]);

    expect($myAccount->fresh()->book_id)->toBeNull();
});

it('refuses another user’s category set on a Book', function () {
    $theirSet = CategorySet::factory()->for($this->theirs->user)->create();
    $mySet = CategorySet::factory()->for($this->mine->user)->create();

    expect(fn () => $this->mine->update(['category_set_id' => $theirSet->id]))
        ->toThrow(InvalidArgumentException::class, 'That category set belongs to another user.');

    $this->mine->update(['category_set_id' => $mySet->id]);

    expect($this->mine->fresh()->category_set_id)->toBe($mySet->id);
});
