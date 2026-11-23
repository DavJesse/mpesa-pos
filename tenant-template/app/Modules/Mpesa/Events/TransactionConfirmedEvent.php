<?php

namespace App\Modules\Mpesa\Events;

use App\Modules\Mpesa\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionConfirmedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Transaction $transaction;

    /**
     * Create a new event instance.
     *
     * @param Transaction $transaction
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('branches.' . $this->transaction->branch_id),
            new PrivateChannel('transactions.' . $this->transaction->id),
        ];

        if (!empty($this->transaction->user_id)) {
            $channels[] = new PrivateChannel('users.' . $this->transaction->user_id);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'transaction.confirmed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'branch_id' => $this->transaction->branch_id,
            'user_id' => $this->transaction->user_id,
            'contact_id' => $this->transaction->contact_id,
            'amount' => (float) $this->transaction->amount,
            'payment_method' => $this->transaction->payment_method,
            'status' => $this->transaction->status,
            'mpesa_receipt_number' => $this->transaction->mpesa_receipt_number,
            'mpesa_result_code' => $this->transaction->mpesa_result_code,
            'mpesa_result_desc' => $this->transaction->mpesa_result_desc,
            'paid_at' => $this->transaction->paid_at?->toIso8601String(),
            'updated_at' => $this->transaction->updated_at?->toIso8601String(),
        ];
    }
}
