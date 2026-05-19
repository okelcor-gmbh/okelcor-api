<p class="bank-p">
    <strong>Account Name:</strong> {{ config('payment.bank_transfer.account_name') }}
    &nbsp; IBAN: {{ config('payment.bank_transfer.iban') }} :
    <strong>SWIFT/BIC</strong>: {{ config('payment.bank_transfer.swift_bic') }}
    &nbsp;:Bank: {{ config('payment.bank_transfer.bank_name') }}
</p>
<p class="bank-p">
    <strong>Bank Address:</strong> {{ config('payment.bank_transfer.bank_address') }}
</p>
@if (isset($order) && $order->ref)
<p class="bank-p" style="margin-top:3px;font-size:10px;color:#444;">
    Payment reference: <strong>{{ $order->ref }}</strong>
</p>
@endif
