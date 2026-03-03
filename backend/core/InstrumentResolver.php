<?php

class InstrumentResolver {

    public function resolve($data) {

        // 1. Direct wallet
        if (!empty($data['wallet_id'])) {
            return $data['wallet_id'];
        }

        // 2. Voucher lookup
        if (!empty($data['voucher_number'])) {
            return $this->walletFromVoucher($data['voucher_number']);
        }

        // 3. Phone lookup
        if (!empty($data['phone'])) {
            return $this->walletFromPhone($data['phone']);
        }

        return null;
    }

    private function walletFromVoucher($voucher)
    {
        // mock DB lookup
        return 1001;
    }

    private function walletFromPhone($phone)
    {
        // mock DB lookup
        return 1001;
    }
}
