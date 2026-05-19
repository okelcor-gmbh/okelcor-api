{{-- Dynamic page number via DomPDF page_script (enable_php: true required) --}}
<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
            $font = $fontMetrics->getFont('Helvetica', 'normal');
            $canvas->text(535, 808, "$pageNumber | $pageCount", $font, 8, [0, 0, 0]);
        });
    }
</script>

<table class="company-footer">
    <tr>
        <td style="width:26%;">
            {{ config('company.name') }}<br>
            {{ config('company.name') }}<br>
            {{ config('company.address') }},<br>
            {{ config('company.city') }}<br>
            {{ config('company.country') }}
        </td>
        <td style="width:24%;">
            Tel.: {{ config('company.tel') }}<br>
            Fax.: {{ config('company.fax') }}<br>
            Email: {{ config('company.email') }}<br>
            Web: {{ config('company.web') }}
        </td>
        <td style="width:26%;">
            {{ config('company.city_court') }}<br>
            HR-Nr.: {{ config('company.hr_nr') }}<br>
            VAT.-ID: {{ config('company.vat_id') }}<br>
            Tax-No.: {{ config('company.tax_no') }}<br>
            CEO: {{ config('company.ceo') }}
        </td>
        <td style="width:24%;text-align:right;">
            {{ config('company.footer_bank_name') }}<br>
            IBAN: {{ config('company.footer_bank_iban') }}<br>
            BIC: {{ config('company.footer_bank_bic') }}
        </td>
    </tr>
</table>
