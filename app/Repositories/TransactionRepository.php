<?php

namespace App\Repositories;

use App\Models\Transaction;

class TransactionRepository
{
    public function log(
        ?int $senderId,
        ?int $receiverId,
        float $amount,
        float $taxDeducted,
        string $type,
        string $description,
        ?float $senderBalanceAfter,
        ?float $receiverBalanceAfter
    ): Transaction {
        return Transaction::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'amount' => $amount,
            'tax_deducted' => $taxDeducted,
            'type' => $type,
            'description' => $description,
            'sender_balance_after' => $senderBalanceAfter,
            'receiver_balance_after' => $receiverBalanceAfter,
        ]);
    }
}
