<?php
// backend/core/HoldService.php

class HoldService
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    private function getTargetTable($assetType)
    {
        switch ($assetType) {
            case 'ACCOUNT':
                return ['table' => 'accounts', 'balance' => 'available_balance', 'id' => 'account_id'];
            case 'EWALLET':
            case 'WALLET':
                // Changed idColumn to phone_number for wallet lookups
                return ['table' => 'wallets', 'balance' => 'balance', 'id' => 'phone'];
            case 'CARD':
                return ['table' => 'cards', 'balance' => 'available_balance', 'id' => 'card_id'];
            default:
                throw new Exception("Unsupported asset type: $assetType");
        }
    }

    public function hold($assetType, $assetId, $amount)
    {
        if ($amount <= 0) throw new Exception("Amount must be greater than zero");

        $config = $this->getTargetTable($assetType);
        $table = $config['table'];
        $balanceColumn = $config['balance'];
        $idColumn = $config['id'];

        // Atomically move funds to held_balance
        $stmt = $this->pdo->prepare("
            UPDATE $table 
            SET held_balance = held_balance + ?, 
                $balanceColumn = $balanceColumn - ?
            WHERE $idColumn = ? AND $balanceColumn >= ?
            RETURNING $balanceColumn
        ");
        
        $stmt->execute([$amount, $amount, $assetId, $amount]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception("Insufficient funds or $assetType ($assetId) not found");
        }

        return $result[$balanceColumn];
    }

    public function release($assetType, $assetId, $amount)
    {
        if ($amount <= 0) throw new Exception("Amount must be greater than zero");

        $config = $this->getTargetTable($assetType);
        $table = $config['table'];
        $balanceColumn = $config['balance'];
        $idColumn = $config['id'];

        $stmt = $this->pdo->prepare("
            UPDATE $table 
            SET held_balance = held_balance - ?, 
                $balanceColumn = $balanceColumn + ?
            WHERE $idColumn = ? AND held_balance >= ?
            RETURNING $balanceColumn
        ");
        
        $stmt->execute([$amount, $amount, $assetId, $amount]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception("Hold not found or insufficient held balance for $assetId");
        }

        return $result[$balanceColumn];
    }
}
