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
