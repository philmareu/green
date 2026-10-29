<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Page header pieces shared by several screens.
 */
class PageActions
{
    /**
     * The "Actions" dropdown at the end of a page header. It holds actions that
     * are rare, risky or cost money, so they take a deliberate second click.
     * Everyday actions stay as buttons beside it.
     *
     * @param  array<Action|ActionGroup>  $actions
     */
    public static function group(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->label('Actions')
            ->icon(Heroicon::ChevronDown)
            ->iconPosition(IconPosition::After)
            ->color('gray')
            ->button();
    }
}
