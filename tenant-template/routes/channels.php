<?php

use App\Modules\Mpesa\Models\Transaction;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Channel authorization callbacks check if an authenticated user
| can listen to private broadcast channels.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// Private channel for a specific user / teller terminal
Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId || $user->isAdmin();
});

// Private channel for a branch
Broadcast::channel('branches.{branchId}', function ($user, $branchId) {
    return $user->isAdmin() || (string) $user->branch_id === (string) $branchId;
});

// Private channel for a specific transaction
Broadcast::channel('transactions.{transactionId}', function ($user, $transactionId) {
    if ($user->isAdmin()) {
        return true;
    }

    $transaction = Transaction::find($transactionId);
    return $transaction && (string) $transaction->branch_id === (string) $user->branch_id;
});
