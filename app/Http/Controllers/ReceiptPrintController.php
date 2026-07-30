<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use Illuminate\Http\Request;

class ReceiptPrintController extends Controller
{
    public function __invoke(Request $request, Receipt $receipt)
    {
        return view('finance.receipt', ['receipt' => $receipt->load('payment.member')]);
    }
}
