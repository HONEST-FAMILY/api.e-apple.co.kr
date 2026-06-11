<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class OrderProductInvoiceTemplateExport implements FromArray, WithTitle, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['출고ID', '택배사', '운송장번호'],
            [1001, 'cj', '123456789012'],
        ];
    }

    public function title(): string
    {
        return '운송장일괄등록';
    }
}
