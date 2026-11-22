# Billing reconciliation checklist (monthly)

Billing runs in **test mode** until a real provider adapter is implemented and activation is approved.

- [ ] Every `paid` invoice has exactly one `succeeded` payment with the same amount/currency; every provider settlement line maps to a payment (`provider_ref`).
- [ ] No `open` invoice older than 30 days without a dunning outcome; `past_due` subscriptions have `next_retry_at`.
- [ ] `billing_webhook_events` with status `rejected` (signature OK but amount/currency mismatch) are investigated.
- [ ] Payments with `failure_reason = paid_void_invoice_refund_required` are refunded at the provider.
- [ ] Tax invoice numbers are continuous per year (`INV-YYYY-NNNNNN`, no gaps) and match the accounting system; seller details (`BILLING_SELLER_*`) correct.
- [ ] VAT report (7%) totals = sum of `vat_minor` for invoices paid in the month.
- [ ] Organizations on paid plans without an `active`/`trialing`/`past_due` subscription (admin comps) are listed with their audit reason.
