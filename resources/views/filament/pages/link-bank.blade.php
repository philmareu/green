<x-filament-panels::page>
    @if ($errorMessage)
        <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" heading="Something went wrong">
            <x-slot name="description">{{ $errorMessage }}</x-slot>
        </x-filament::callout>
    @endif

    @if ($linkToken)
        <x-filament::section icon="heroicon-o-building-library">
            <x-slot name="heading">
                {{ $itemId ? 'Sign in to your bank again' : 'Connect a bank through Plaid' }}
            </x-slot>

            <x-slot name="description">
                {{ $itemId
                    ? 'Your bank needs you to log in again before Green can keep syncing.'
                    : 'Plaid opens in a secure window. Green never sees your bank password.' }}
            </x-slot>

            <div style="display: flex; flex-direction: column; gap: 1rem;">
                @unless ($itemId)
                    <p style="font-size: 0.875rem; opacity: 0.8;">
                        Every account you choose is added to
                        <strong>{{ $this->destinationName() }}</strong>.
                        You can move individual accounts to a different Book afterwards.
                    </p>
                @endunless

                <p style="font-size: 0.875rem; opacity: 0.8;">
                    Green keeps what Plaid shares on this server and never sells or shares it.
                    <x-filament::link :href="route('privacy')" target="_blank">Privacy policy</x-filament::link>
                </p>

                <div wire:loading.remove wire:target="exchange">
                    <x-filament::button icon="heroicon-o-link" x-on:click="window.greenOpenPlaidLink ? window.greenOpenPlaidLink() : $wire.linkFailed(null, 'Plaid Link is still loading. Try again in a moment.')">
                        Continue to Plaid
                    </x-filament::button>
                </div>

                <div wire:loading.flex wire:target="exchange" style="align-items: center; gap: 0.5rem; font-size: 0.875rem; opacity: 0.8;">
                    <x-filament::loading-indicator style="width: 1.25rem; height: 1.25rem;" />
                    Importing accounts and transactions…
                </div>
            </div>
        </x-filament::section>
    @endif

    @assets
        <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
    @endassets

    @script
        <script>
            const token = $wire.linkToken
            const receivedRedirectUri = $wire.receivedRedirectUri

            if (token && ! window.Plaid) {
                $wire.linkFailed(null, 'Plaid Link could not load. Check your connection or any content blocker, then refresh the page.')
            }

            if (token && window.Plaid) {
                const config = {
                    token,
                    onSuccess: (publicToken, metadata) => {
                        $wire.exchange(
                            publicToken,
                            metadata?.institution?.institution_id ?? null,
                            metadata?.institution?.name ?? null,
                        )
                    },
                    onExit: (error) => {
                        if (error) {
                            $wire.linkFailed(error.error_code ?? null, error.display_message || error.error_message || null)
                        }
                    },
                }

                if (receivedRedirectUri) {
                    config.receivedRedirectUri = receivedRedirectUri
                }

                const handler = window.Plaid.create(config)

                window.greenOpenPlaidLink = () => handler.open()

                // Coming back from an OAuth bank, Link must reopen straight away.
                if (receivedRedirectUri) {
                    handler.open()
                }
            }
        </script>
    @endscript
</x-filament-panels::page>
