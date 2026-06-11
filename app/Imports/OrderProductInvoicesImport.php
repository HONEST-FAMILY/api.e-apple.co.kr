<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithStartRow;

class OrderProductInvoicesImport implements WithStartRow
{
    /**
     * 1행은 헤더(출고ID, 택배사, 운송장번호)
     */
    public function startRow(): int
    {
        return 2;
    }
}
