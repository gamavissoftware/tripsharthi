# Account deletion runbook

For: the TripSarthi team member who receives a request to delete a customer's account or data. Public promise (website `delete-account.html`): we confirm within **2 working days**, finish within **30 days**, and e-mail the person when it is done. Requests arrive as (a) a support request from a signed-in customer, (b) a website enquiry with the topic "Account deletion request", or (c) an e-mail to the support address.

Two kinds of request: **a whole workspace** (only the owner may ask) or **one team member's own login**.

## 1. Check who is asking (never skip)
- **Signed-in support request**: the sender is already proven. Check their role in admin app -> Customers -> the workspace.
- **Website form or e-mail**: reply asking them to send the request again from the e-mail address registered on the account (or from inside the app). If they cannot, ask one question only the owner can answer (for example the date of their last payment or their GSTIN) and compare it with admin app -> Customers / GST invoices. If you are not sure, do not delete.
- A **team member** can only ask for their own login. A request from an agent to delete the whole workspace goes to the owner for confirmation first.
- Write down the **reference**: ticket number or e-mail date. The command needs it and stores it.

## 2. Tell the owner what will happen (reply template)
> We have received your request to delete the TripSarthi workspace "<name>". This permanently removes your contacts, enquiries, quotes, bookings, travellers and passport details, documents, WhatsApp conversations, tasks, settings and connections, and signs everyone in your team out. It cannot be undone. We must keep the GST invoices and payment records TripSarthi issued to you, and our backups drop your data within 30 days.
> Before we delete: download anything you need (contacts export, reports, GST exports from Settings -> GST & Tally exports, the invoices you issued to travellers). Do you also want us to keep your own tax documents (invoices to travellers, GST filing records) instead of deleting them? Reply "delete everything" or "keep tax documents" and we will go ahead within 7 days unless you tell us to stop.

Wait for their answer (or 7 days of silence if they asked by the signed-in route). If they have an **active paid plan**, say that fees are non-refundable (terms) and that the plan ends at deletion.

## 3. Prepare
1. Make sure it is not a protected workspace: the command refuses a workspace that has a **platform admin** user (revoke with `php spark admin:grant <email> --revoke` only if the owner of the account is really leaving) or is the **TripSarthi marketing workspace** (change it in admin app -> WhatsApp -> Our marketing first).
2. Disconnect external accounts if the owner wants to be tidy (we delete our copy of the access tokens, but they stay authorised in Meta / Google until revoked): tell the owner to remove "TripSarthi" under Facebook Settings -> Business integrations and Google Account -> Security -> Third-party access. Razorpay keys they saved with us are deleted with the workspace.
3. Suspend the workspace in the admin app (reason: "deletion requested <ref>") so nothing is sent or charged while you wait.
4. Take a backup: `APP_DIR=/var/www/html/tripsarthi bash deploy/backup.sh` (kept 14 days; this is the copy that still holds the data until it rotates out).

## 4. Dry run
On the server (shared box, PHP 8.3), as root:
```bash
cd /var/www/html/tripsarthi/backend
php8.3 spark account:delete workspace <tenant_id>
```
It prints, for that workspace: every table and how many rows would be deleted, what is kept, the folders that would be removed. Read it: row counts should look like that customer (a handful of contacts for a small agency, not millions). Nothing is changed.

## 5. Delete
```bash
php8.3 spark account:delete workspace <tenant_id> --apply --confirm=<the workspace slug exactly> --ref="ticket #123"
# owner asked to keep their own tax documents:
php8.3 spark account:delete workspace <tenant_id> --apply --confirm=<slug> --ref="ticket #123" --keep-tax-docs
```
What happens, in one database transaction: all rows with that workspace id are deleted (about 120 tables, found automatically so new tables are covered) **except** TripSarthi's own business records: `subscriptions`, `platform_invoices`, `platform_credit_notes`, `coupon_redemptions`, `offer_grants`, `partner_commissions`, `subscription_reminders`, `impersonation_sessions` (the support-access log). With `--keep-tax-docs` the customer's own `invoices`, `doc_sequences`, `einvoice_settings`, `gst_*`, `tcs_challans` and `business_profiles` also stay. Then user logins, sign-in tokens, phone push tokens and uploaded files (portal documents and passports, ad images, imports, social images, issued PDFs) are removed, and the workspace row becomes the anonymous stub `Deleted workspace #<id>` (slug `deleted-<id>`, cancelled). A row is added to `account_deletions` (who, when, reference, counts; no personal data). A failed run removes nothing.

**One team member** (their own request; the owner cannot be deleted this way):
```bash
php8.3 spark account:delete user <user_id> --apply --confirm=<their e-mail> --ref="e-mail 23 Oct"
```
Removes their sign-ins, phone tokens, notifications and push settings, and anonymises the login (name "Former team member", e-mail `deleted-<id>@deleted.invalid`, phone removed, marketing consent off). Records they created (tasks, notes) stay in the workspace with only their id.

## 6. Afterwards
- Check: `SELECT * FROM account_deletions ORDER BY id DESC LIMIT 1;` and `php8.3 spark account:delete workspace <tenant_id>` again (it should say the workspace was already deleted).
- Remove the person from our other lists: admin app -> Email marketing / WhatsApp audiences refresh themselves (the login is gone); if they were in the website enquiry list, mark that row handled (`php8.3 spark contact:list --handled <id>`) and delete the enquiry text if they asked.
- Mention: **backups** keep the data until they rotate out (the nightly backup keeps 14 days at `/var/backups/tripsarthi`). **Check the hosting provider's own server snapshots** (Utho) for an older copy; if they keep any, they must also expire within 30 days. Never restore a backup taken before a deletion without re-running the deletions listed in `account_deletions` (restore, then repeat each recorded deletion).
- E-mail the person: "Your TripSarthi data has been deleted. We kept only the invoices and payment records the law requires." Keep that e-mail with the ticket.
- Close the support ticket; note the deletion date and `account_deletions.id` in it.

## 7. What we do NOT do
- No deletion without a verified request. No deletion of a workspace that still has a **running dispute or chargeback**: finish that first.
- We never delete the GST invoices TripSarthi issued: Indian GST law requires keeping them. They are kept only for that purpose.
- WhatsApp messages that were already delivered live on the customer's travellers' phones and in Meta's systems: deleting our copy does not recall them.
- If the person is a **traveller** (a customer of an agency using TripSarthi) asking us to delete THEIR data, the agency is the data fiduciary: forward the request to the agency (we process on their behalf) and tell the person you did so.
