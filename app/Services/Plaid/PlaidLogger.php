<?php

namespace App\Services\Plaid;

use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Enums\PlaidLogTrigger;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\User;
use Closure;
use Throwable;

/**
 * Writes the Plaid activity log: one `PlaidLog` entry per operation, with the
 * requests `PlaidClient` made during it.
 *
 * The entry is saved when the operation starts, so a run that never ends (a
 * job killed at its timeout) still shows as unfinished, and again when it
 * ends. Failing to write the log is reported, never thrown, so it can't stop
 * a sync or a balance refresh.
 */
class PlaidLogger
{
    public function __construct(private readonly PlaidClient $plaid) {}

    /**
     * Run an operation and log it. The operation receives the entry to add
     * details to, such as a sync's counts. Its exceptions are logged as a
     * failure and rethrown.
     *
     * @template TResult
     *
     * @param  PlaidItem|User  $for  The connection, or for a link its user until the connection exists.
     * @param  Closure(PlaidLog): TResult  $operation
     * @return TResult
     */
    public function record(
        PlaidLogKind $kind,
        PlaidItem|User $for,
        Closure $operation,
        PlaidLogTrigger $trigger = PlaidLogTrigger::Manual,
    ): mixed {
        $log = new PlaidLog([
            'kind' => $kind,
            'triggered_by' => $trigger,
            'result' => PlaidLogResult::Unfinished,
            'started_at' => now(),
        ]);

        if ($for instanceof PlaidItem) {
            $log->forItem($for);
        } else {
            $log->user_id = $for->getKey();
        }

        $this->save($log);

        $startedAt = hrtime(true);
        $this->plaid->startCapturing();

        try {
            $result = $operation($log);
            $log->result = PlaidLogResult::Succeeded;

            return $result;
        } catch (Throwable $e) {
            $log->fill(['result' => PlaidLogResult::Failed, ...$this->describe($e)]);

            throw $e;
        } finally {
            $log->requests = $this->plaid->stopCapturing();
            $log->duration_ms = intdiv(hrtime(true) - $startedAt, 1_000_000);
            $log->pages = $kind === PlaidLogKind::Sync ? $log->countPages() : null;

            // A removed connection is gone; its entry keeps only the bank's name.
            if ($for instanceof PlaidItem && ! $for->exists) {
                $log->plaid_item_id = null;
            }

            $this->save($log);
        }
    }

    /**
     * Plaid's error code and message. Other errors keep only their class: their
     * messages can quote SQL with transaction details, and they are reported
     * in full to the application log anyway.
     *
     * @return array{error_code: ?string, error_message: string}
     */
    protected function describe(Throwable $e): array
    {
        if ($e instanceof PlaidException) {
            return ['error_code' => $e->errorCode, 'error_message' => $e->getMessage()];
        }

        return [
            'error_code' => null,
            'error_message' => 'Unexpected '.class_basename($e).'. Details are in the application log.',
        ];
    }

    protected function save(PlaidLog $log): void
    {
        try {
            $log->save();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
