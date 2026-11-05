# Manual DigiMaint handover

- Version: 1.1
- Recorded: 5 November 2026
- Process owners: Stores and Logistics

## Agreed system boundary

DigiMaint remains the source of truth for stock. Aero PO manages procurement,
approvals, payment workflow, shipping, and the receiving and closure workflow.
Information passes between the two systems through a documented manual process.
There is no automatic stock synchronization in this phase.

The procedure below defines the operating records and application controls.
Aero PO now records line-level manual handovers and requires independent
verification. It does not log into DigiMaint or perform its stock transactions.

## Responsibilities

| Role | Responsibility |
| --- | --- |
| Requester | Supplies the part requirement and confirms the intended end user. |
| Store staff or Store Supervisor | Checks DigiMaint stock and records the result; performs receiving or stock issue under the company's existing Stores procedure. |
| Authorized DigiMaint operator, normally Stores | Posts the receipt or issue in the correct company's DigiMaint records and captures the resulting reference. |
| Store Supervisor | Verifies the manual handover against DigiMaint and the supporting records; records discrepancies and confirms reconciliation. |
| Logistics Officer | Coordinates the procurement record, follows up unresolved handovers, records Cardex completion, and closes the order after the applicable checks. |
| Tenant administrator | Maintains access to the tenant's records and assigns the people carrying out these responsibilities. |

Record the identity of the person performing each action. The Store Supervisor
records verification separately from the original entry.

## Handover record

Use the Stores and manual DigiMaint handover section on the order as the
application register. Upload DigiMaint evidence through the order's Documents
section first. The blank [register template](templates/digimaint-manual-register.csv)
is available for controlled offline work or additional company records. Keep
completed external registers tenant-restricted and outside the source-code
repository, and reconcile offline work into the application when available.

Each row represents one event for one requisition or receipt line. Use the same
handover reference for related events and a new event ID for each update. Append
verification and correction events instead of overwriting the original row.

Record:

- Tenant, platform requisition/PO reference, line reference, and GRN when applicable.
- Activity: `stock_check`, `receipt`, `stock_issue`, or `correction`.
- Part number, quantity and unit, requested condition, and serial/batch when applicable.
- DigiMaint record or transaction reference, the person who checked or posted it,
  and the actual check or posting time.
- Evidence reference, verification state, verifier, verification time, and any
  related event or discrepancy reference.

Use timestamps with an explicit UTC offset, for example
`2026-11-05T09:00:00+01:00`. Keep the time of the original action separate from
the time its record was entered in the register. For fields that do not apply,
record `N/A`; leave unknown facts unconfirmed and explain them in the notes.

Verification states are:

| State | Meaning |
| --- | --- |
| `pending` | An external register state for work still required or unconfirmed; do not record a completed application handover yet. |
| `recorded` | The operator reports completion and has supplied a DigiMaint reference and supporting evidence. |
| `verified` | The Store Supervisor has checked the reference, line identity, quantities, and evidence against DigiMaint. |
| `exception` | A discrepancy, unavailable system, or uncertain transaction needs resolution. |
| `superseded` | A later check or correction replaces this entry; its original evidence and audit history remain available. |

## 1. Stock check before sourcing

1. Stores opens the requisition and checks the corresponding company and stock
   records in DigiMaint.
2. For each line, record the requested quantity, available quantity, unit,
   condition, DigiMaint record reference, operator, and check time. Follow the
   company's existing Stores rules when determining whether stock can satisfy
   the requirement; an availability check does not reserve or issue stock.
3. Record a `stock_check` handover in Aero PO for each line, including the actual
   available quantity, DigiMaint reference, completed action time and evidence.
   A different Store Supervisor verifies the entry. Record `available` on the
   stock-check form only when the verified quantity covers the whole line;
   otherwise mark the line short. The application checks the form against the
   latest verified handover for every line.
4. Where every line can be supplied from stock, follow the stock-issue procedure
   below. Where any line is short, Logistics starts sourcing according to the
   approved purchasing workflow.
5. Recheck availability before the actual issue if the stock position may have
   changed. Preserve the earlier check and append the new result.

The application retains quantities in handovers and available/short decisions
on requisition lines. Before sourcing or an actual stock issue, a new check can
replace an earlier check while retaining its history. A new recorded check must
be verified before the stock decision can be confirmed. Mixed fulfilment and
stock reservations are outside this release: an order is fulfilled entirely
from existing stock or sourced for its requested lines. Reconcile existing
allocations before sourcing to avoid buying quantities already issued.

## 2. Receipt from a supplier

1. Stores confirms the delivery against the PO, the required receiving documents,
   and the company's existing receiving procedure. Record the quantity actually
   received for each line and any discrepancy.
2. Record each actual receipt in Aero PO, with its GRN and received quantities.
   Leave a line at zero if none arrived. For net-terms orders, receipt of all
   ordered quantities makes the order eligible for finance; receipt does not
   itself approve or confirm payment.
3. Assign the GRN and cross-reference the tenant, PO, delivery/AWB, and receipt
   lines in the handover register.
4. Before posting in DigiMaint, search for an existing receipt using the GRN,
   PO, part/line, and delivery references. If a matching receipt already exists,
   verify and reference it instead of creating another receipt.
5. The authorized operator posts the actual receipt in DigiMaint, using the
   applicable company stock records. Capture the resulting transaction reference,
   quantity, unit, operator, posting time, and evidence. Mark the handover
   `recorded`.
6. The Store Supervisor verifies the DigiMaint entry against the GRN and the
   actual receipt. Append a `verified` event, or an `exception` event describing
   what needs correction.
7. Record the serviceable-label action under the company's existing authorized
   procedure, then record Cardex completion and delivery to the end user.
8. Logistics completes the closure checks below.

Partial receipts have separate GRNs and leave the order at `delivered` until all
quantities arrive. The application prevents receipt beyond the outstanding
ordered quantity. Record damaged, excess or mismatched deliveries in discrepancy
notes and handle them under the company's receiving procedure; do not confirm
them as accepted quantities. Keep the order open until outstanding quantities
and handover exceptions are resolved.

## 3. Fulfilment from existing stock

1. Stores confirms that the requirement is still available and performs the
   authorized issue to the end user under the existing Stores procedure.
2. The authorized operator records or locates the corresponding stock issue in
   DigiMaint. Capture the issue reference, requisition and line references,
   quantities, operator, time, and evidence.
3. The Store Supervisor verifies the issue and the handover to the end user.
   Append the verification event to the register.
4. Logistics closes the stock-fulfilled requisition after all requested lines
   have verified issue evidence and all discrepancies are resolved.

This path records an issue of existing stock. It must not create a new stock
receipt. The application's `fulfilled_from_stock` status follows its availability
check and is not, by itself, evidence that the issue took place.

## 4. Discrepancies and interrupted work

| Situation | Required action |
| --- | --- |
| DigiMaint is unavailable | Record pending work and its owner in the controlled register. Do not invent a transaction reference. An existing recorded handover can be marked as an exception. Keep the order open. |
| Posting outcome is uncertain | Search DigiMaint for the transaction before attempting another entry. Record the result and prevent duplicate receipt or issue entries. |
| Part, quantity, unit, condition, or company does not match | Record the discrepancy and refer it to the Store Supervisor. Leave verification incomplete until reconciled. |
| A confirmed entry needs correction | Follow the authorized DigiMaint correction procedure. Append the correction and its references, retain the original evidence, and verify the resulting stock position. |
| An error is discovered after order closure | Preserve the closed history, open a linked discrepancy record, and have Stores and Logistics coordinate the correction. The current app has no reopen action. |

For a handover marked as an exception, record a corrected action with a new
reference and evidence. The application links it to the superseded entry and
requires another verification. It rejects duplicate receipt or issue references
for the same line. Repeat stock checks may use the same DigiMaint stock-record
reference, with separate action times and evidence.

Stores and Logistics review unresolved register entries during each working day
and before closing the related order. Each exception needs an owner, a next
action, and a follow-up date. Specific escalation targets remain tenant policy.

## 5. Closure checks

For an externally procured order, Logistics confirms that:

- Receiving documents, actual received quantities, and the GRN reconcile.
- Every required DigiMaint receipt has a transaction reference, evidence, and
  recorded Store Supervisor verification.
- Required serviceable-label and Cardex actions have been recorded.
- Outstanding quantities and handover exceptions have been resolved.
- All applicable approval and closure rules have been met. CIA payment precedes
  shipping; net-terms finance follows receipt and can remain pending after the
  procurement order closes. Finance staff complete those invoices separately.

For an order fulfilled from stock, use verified DigiMaint issue evidence and
end-user handover confirmation in place of supplier receipt and shipping records.

Closing the order records completion of these checks. It does not perform a
DigiMaint transaction.

## Current application support

| Area | Implemented control |
| --- | --- |
| Stock check | Per-line quantities, actual action time, DigiMaint reference, private evidence and separate supervisor verification gate the available/short decision. |
| Receipt | Receiving documents, positive actual quantities and outstanding-quantity checks are enforced. Partial receipts remain open. |
| GRN | Each receipt has a tenant-unique GRN, line quantities and linked receipt handovers. |
| Evidence | Stores can upload dedicated DigiMaint evidence. Downloads enforce tenant and order authorization; referenced evidence cannot be deleted. |
| Verification | A different Store Supervisor records `verified_by` and `verified_at` per handover, with a mandatory note. Self-verification is refused. |
| DigiMaint completion | `digimaint_synced_at` is set after all applicable manual receipts or issues are verified. Closure checks the handover records rather than trusting the timestamp. |
| Exceptions | Verification can flag exceptions. Corrections supersede earlier entries, retain audit history and require fresh verification. Unresolved exceptions prevent closure. Closed orders remain closed. |

Action times must include a UTC offset, for example
`2026-11-05T09:00:00+01:00`; the application stores the actual instant in UTC.
Evidence, references and entries remain human attestations and must be checked
against DigiMaint. Preserve legacy `digimaint_synced_at` values as historical
application data; they do not establish that old orders have been reconciled.
