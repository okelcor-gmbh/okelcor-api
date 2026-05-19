* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    color: #000000;
    background: #ffffff;
}

.page { padding: 36px 44px 36px 44px; }

/* ── Logo wordmark ─────────────────────────────────────────────────── */
.ok-logo {
    font-size: 34px;
    font-weight: 900;
    letter-spacing: 5px;
    color: #000000;
    text-transform: uppercase;
    line-height: 1;
    font-family: 'Arial Black', Arial, sans-serif;
}

/* ── Header right metadata ─────────────────────────────────────────── */
.hdr-meta-lbl {
    font-size: 9px;
    font-weight: 700;
    color: #000000;
    letter-spacing: 0.4px;
    margin-bottom: 1px;
}
.hdr-meta-val {
    font-size: 11px;
    color: #000000;
    margin-bottom: 11px;
}

/* ── Sender line above customer address ────────────────────────────── */
.sender-line {
    font-size: 9px;
    color: #555555;
    border-bottom: 1px solid #888888;
    padding-bottom: 3px;
    margin-bottom: 10px;
}

/* ── Customer address block ────────────────────────────────────────── */
.customer-address {
    font-size: 11px;
    line-height: 1.8;
    color: #000000;
}

/* ── Document title ────────────────────────────────────────────────── */
.doc-title {
    font-size: 17px;
    font-weight: 700;
    color: #000000;
    margin-bottom: 10px;
    line-height: 1.3;
}

.intro-p {
    font-size: 11px;
    color: #000000;
    line-height: 1.5;
    margin-bottom: 5px;
}

/* ── Item table ────────────────────────────────────────────────────── */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6px;
}
.items-table thead tr { background-color: #d9d9d9; }
.items-table th {
    padding: 7px 9px;
    font-size: 10px;
    font-weight: 700;
    color: #000000;
    text-align: left;
    border: 1px solid #b0b0b0;
}
.items-table th.r { text-align: right; }
.items-table th.c { text-align: center; }
.items-table td {
    padding: 8px 9px;
    font-size: 11px;
    color: #000000;
    border: 1px solid #d9d9d9;
    vertical-align: top;
}
.items-table td.r { text-align: right; }
.items-table td.c { text-align: center; }

.qty-num  { font-size: 11px; }
.qty-unit { font-size: 10px; color: #444444; }
.price-tax { font-size: 9px; color: #444444; }
.item-sub  { font-size: 10px; color: #444444; margin-top: 1px; }

/* ── Totals block ──────────────────────────────────────────────────── */
.totals-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
.totals-table td { padding: 3px 0; font-size: 11px; color: #000000; }
.totals-table .amt { text-align: right; }
.gross-row td {
    border-top: 1px solid #000000;
    padding-top: 8px;
    font-size: 13px;
    font-weight: 700;
}
.totals-divider {
    border: none;
    border-top: 1px solid #cccccc;
    margin: 14px 0 12px 0;
}

/* ── Terms / bank text ─────────────────────────────────────────────── */
.terms-p { font-size: 11px; color: #000000; line-height: 1.6; margin-bottom: 8px; }
.bank-p  { font-size: 11px; color: #000000; line-height: 1.8; margin-bottom: 3px; }

/* ── Section label ─────────────────────────────────────────────────── */
.sec-lbl {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #666666;
    margin-bottom: 4px;
}

/* ── Info bar (carrier / shipment) ────────────────────────────────── */
.info-bar { width: 100%; border-collapse: collapse; background: #f5f5f5; margin-bottom: 14px; }
.info-bar td { padding: 8px 10px; vertical-align: top; border-right: 1px solid #e0e0e0; }
.info-bar td:last-child { border-right: none; }
.info-bar .ibl { font-size: 9px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: #666666; margin-bottom: 2px; }
.info-bar .ival { font-size: 11px; font-weight: 700; color: #000000; }
.info-bar .isub { font-size: 10px; color: #444444; }

/* ── Signature boxes ───────────────────────────────────────────────── */
.sig-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.sig-table td { vertical-align: top; padding: 0; }
.sig-box { border: 1px solid #cccccc; padding: 10px 12px; height: 68px; }
.sig-lbl { font-size: 9px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: #666666; margin-bottom: 2px; }
.sig-sub { font-size: 9px; color: #aaaaaa; }
.sig-line { border-bottom: 1px solid #aaaaaa; margin-top: 38px; }
.sig-caption { font-size: 8px; color: #999999; margin-top: 3px; }

/* ── Company footer ────────────────────────────────────────────────── */
.company-footer { width: 100%; border-collapse: collapse; border-top: 1px solid #888888; margin-top: 32px; }
.company-footer td { padding-top: 8px; font-size: 9px; color: #000000; line-height: 1.7; vertical-align: top; }
