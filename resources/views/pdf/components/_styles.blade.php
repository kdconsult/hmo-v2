<style>
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 12px;
        color: #1a1a1a;
        margin: 0;
        padding: 0;
    }
    .page { padding: 40px 48px; }

    /* ── Header ── */
    .header {
        width: 100%;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 20px;
        margin-bottom: 32px;
    }
    .header table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
    .header-left { vertical-align: top; }
    .header-right { vertical-align: top; text-align: right; }
    .company-name { font-size: 20px; font-weight: bold; color: #111827; }
    .document-title { font-size: 18px; font-weight: bold; color: #374151; }
    .document-meta { font-size: 11px; color: #6b7280; margin-top: 4px; }
    .company-detail { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.6; }

    /* ── Parties ── */
    .parties { width: 100%; margin-bottom: 32px; }
    .parties table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
    .party-cell { vertical-align: top; width: 50%; }
    .party-cell-right { vertical-align: top; width: 50%; text-align: right; }
    .party-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.05em; margin-bottom: 6px; }
    .party-name { font-size: 14px; font-weight: bold; color: #111827; margin-bottom: 4px; }
    .party-detail { font-size: 11px; color: #4b5563; line-height: 1.6; }

    /* ── Meta box ── */
    .meta-box {
        background-color: #f9fafb;
        border: 1px solid #e5e7eb;
        padding: 12px 16px;
        margin-bottom: 24px;
    }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 0; }
    table.meta td { padding: 3px 0; font-size: 11px; border-bottom: none; }
    .meta-label { width: 160px; color: #6b7280; }
    .meta-value { color: #111827; font-weight: bold; }

    /* ── Line items ── */
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    table.items thead th {
        background-color: #f3f4f6;
        padding: 8px 12px;
        text-align: left;
        font-size: 11px;
        text-transform: uppercase;
        color: #6b7280;
        letter-spacing: 0.05em;
    }
    table.items tbody td {
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 12px;
        color: #374151;
    }
    .text-right { text-align: right; }

    /* ── Totals ── */
    .totals-wrapper { width: 100%; margin-bottom: 32px; }
    table.totals { width: 300px; margin-left: auto; border-collapse: collapse; }
    table.totals td { padding: 4px 8px; font-size: 12px; }
    .totals-label { color: #6b7280; text-align: right; }
    .totals-value { text-align: right; font-weight: bold; color: #111827; }
    .totals-grand td { border-top: 2px solid #111827; font-size: 14px; font-weight: bold; }
    .totals-due td { color: #dc2626; font-size: 14px; font-weight: bold; }

    /* ── Footer ── */
    .footer {
        margin-top: 40px;
        border-top: 1px solid #e5e7eb;
        padding-top: 16px;
        font-size: 10px;
        color: #9ca3af;
        text-align: center;
    }
</style>
