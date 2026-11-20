# Findings ledger

Every finding from `/code-review`, `/security-review`, `decomplex` or a manual pass that is **not fixed in the same session** is recorded here.

## Severity

| Level  | Meaning                                                                                                                                   | Blocks milestone close |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| **P0** | A wrong or lost invoice (issued twice, wrong amounts or tax), a webhook signature bypass, or a token or secret leaked (logs, `about`, exceptions) | **Yes**                |
| **P1** | Broken behaviour an app hits on a normal path, or a plan "Done when" not met                                                              | **Yes**                |
| **P2** | Degraded behaviour, missing edge case, meaningful complexity or duplication                                                               | No (carried)           |
| **P3** | Cosmetic, ergonomic, or a note for later                                                                                                  | No (carried)           |

## Status

`open` → not addressed · `fixed` → changed but not independently confirmed · `verified` → confirmed by a reviewer or test · `accepted` → deliberately left, the owner agreed · `wontfix` → rejected with a reason

## The gate

`/package-close` refuses a milestone while any **P0 or P1** is `open` or `fixed`. It must reach `verified`, or `accepted` with the owner's agreement in Notes.

## Recording rule

Append; never delete a row. One row per finding, with a stable ID (`F001`, `F002`, …). Never name the company's apps.

---

| ID  | Sev | Found | Milestone | Area | Finding | Status | Notes |
| --- | --- | ----- | --------- | ---- | ------- | ------ | ----- |
| F001 | P3 | 2026-11-19 | 0.1.0 | Issuing | Two databases on one token both start at `invoice:1` (two developers, or `migrate:fresh`), so `IssueInvoice`'s `409 external_reference_already_used` recovery stores the other database's invoice and fires `InvoiceIssued`. | open | T4 review. Fix in T10: the README tells apps to give each database its own app and token on the service. |
| F002 | P3 | 2026-11-19 | 0.1.0 | Batches | If applying a batch throws halfway (an `InvoiceIssued` listener, or the database), the job fails; when the batch is applied again (T9's `refresh-pending`), its failed items fire `InvoiceNotIssued` a second time. Issued items are skipped by `isOnVerifactu()`. | open | T6 review (both reviewers). The comment in `ApplyInvoiceBatch` now says what it covers. Decide in T10: note it in the README (listeners of `InvoiceNotIssued` should be idempotent) or move the dispatches after the loop. |
| F003 | P3 | 2026-11-19 | 0.1.0 | Install | `verifactu:install` reads the columns on the invoice model's connection, but the migration it writes runs `Schema::table()` on the default connection, so a model on another connection would get its columns added to the wrong database. | open | T9 review. Every app invoices as one company on one database (plan, Out of scope), so not fixed in code. Fix in T10: the README says the wizard's migration runs on the default connection. |
| F004 | P2 | 2026-11-19 | 0.1.0 | Tax lines | An `E3` line (article 22, operations treated as exports) goes with the default regime key 01, which AEAT refuses for E2 and E3 ("Validaciones y errores" v1.2.2, §15.5). E2 now sends 02, as the service's example does; neither AEAT's document nor the service's docs name the key for E3. | accepted | Sweep review (T3). Ask the owner, or the service's docs, which regime key E3 takes; until then an E3 invoice would be refused. The apps use E1 only. 2026-11-20: the owner chose to refuse E3 locally (the apps sell education only, E1): `TaxLine::exempt()` throws before the invoice gets a number. Reopen if an app ever needs E3 and the regime key is confirmed. |
| F005 | P3 | 2026-11-19 | 0.1.0 | Issuing | On the `409 external_reference_already_used` path, an invoice already decided by AEAT fires its status event (`InvoiceAccepted`…) before `InvoiceIssued`; if that listener throws, `InvoiceIssued` never fires. | open | Sweep review (T4). Fix in T10: the README states the order. |
| F006 | P3 | 2026-11-19 | 0.1.0 | Tax-ID check | `KnownToAeat` reads the name field with `Arr::get`, so a wildcard name field (`customers.*.legal_name`) checks nothing; it calls the service even when other rules on the form failed; the `similar` message shows on the name field but `$validator->failed()` lists it under the tax ID. | open | Sweep review (T7). Fix in T10: the README says top-level or fixed dotted name fields only, and to put the rule last, after `bail`. |
| F007 | P3 | 2026-11-19 | 0.1.0 | Tax lines | A tiny negative base on a correction for the difference (-1 or -2 cents at 21%) has a tax of 0.00. AEAT's same-sign rule doesn't apply to corrections for the difference (§15.7), but whether the service's own `tax_amount_sign_differs_from_base` accepts 0 is undocumented. | open | Sweep review (T3). Check in T11 against the service in Test. |
| F008 | P3 | 2026-11-19 | 0.1.0 | Composer | `composer.json` requires `illuminate/support ^13.0`, but the code uses `Illuminate\Foundation` (`AboutCommand`, `Queueable`, `Dispatchable`), which only `laravel/framework` ships. Every real app has it, so nothing breaks today. | fixed | Sweep review (T1). The owner approved on 2026-11-20: `composer.json` requires `laravel/framework ^13.0` in place of `illuminate/support`; `composer validate --strict` valid. |
| F009 | P3 | 2026-11-19 | 0.1.0 | Client | `Retry-After` now waits at least a second, but has no ceiling: a direct call (fix, cancel, a command) waits whatever the service asks, up to twice. Queued jobs release instead of waiting. | fixed | Sweep review (T2). 2026-11-20, the owner approved after research (Forge, Laravel, Stripe, AWS, Saloon, Octokit; plan Decision on the client): a direct call waits out at most 5 s (`Client::LongestWait`); above that it throws at once with `retryAfter`, as Forge's SDK does. Tested in `ClientTest`. |
| F010 | P3 | 2026-11-19 | 0.1.0 | Issuing | A database failure while storing a `201` releases `IssueInvoice` (P1 fix). If the failure lasts 24 hours (a misconfigured column), the job then fails and fires `InvoiceNotIssued` for an invoice AEAT has. On the `sync` queue, a release does nothing, so the answer is dropped without an event. | open | Sweep, second round. The README (T10) says a column must exist before issuing, and that the package's jobs need a real queue. |
| F011 | P3 | 2026-11-19 | 0.1.0 | Batches | If an invoice of a batch changes between a lost `202` and the retry, the service refuses the key (`422 idempotency_key_reused`): the batch is issued but never stored, so never applied; `InvoiceNotIssued` fires for its invoices. Sending them again heals it (their items fail with `external_reference_already_used` and are read back). A single invoice reads itself back instead. | open | Sweep, second round. A batch can't be looked up by reference; the README (T10) says not to change invoices while their batch is being sent. |
