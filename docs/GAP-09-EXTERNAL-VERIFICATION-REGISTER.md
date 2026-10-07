# FF Arena — External verification register (GAP-09 / GAP-10 E-items)

**Status of this document: nothing in it is verified.** Every item below needs a
human with access to a third-party system — a registrar, a payment provider's
merchant portal, an app store console, a signing HSM, a live production host.
None of that access exists in a coding session, so none of it is claimed.

The rule this file exists to enforce: **an external item is never marked
`VERIFIED` because the code for it is ready.** Code readiness and external
verification are different things, and conflating them is how a launch ships a
payment gateway that has never taken a payment, or an App Link that resolves
nowhere.

`tests/Static/test_gap10_identity_floor.py` parses this file and fails when:

* any `E01`–`E27` row is missing,
* a row's status is outside `PENDING | VERIFIED`,
* a row is `VERIFIED` without an `evidence:` URL that starts with `https://`
  **and** an `at:` ISO-8601 date,
* a `PENDING` row carries evidence as if it had been verified.

Marking a row `VERIFIED` therefore requires a real, resolvable artefact. A
reviewer who wants to move a row must supply that artefact; the floor test is
there to make the shortcut impossible.

## Machine-readable rows

Format: `E<nn> | <STATUS> | <item> | owner: <who> | evidence: <what proves it>`

```
E01 | PENDING | Production domain registration and DNS (A/AAAA + CNAME for CDN) | owner: ops | evidence: registrar record for the production host plus `dig` output from two networks
E02 | PENDING | TLS certificate issuance and renewal automation for the production origin | owner: ops | evidence: certificate chain read from the live origin plus a renewal dry-run log
E03 | PENDING | Production PostgreSQL cluster provisioned, tuned and backed up | owner: ops | evidence: managed-database instance id, connection string reference in the secret store, first successful automated backup
E04 | PENDING | Managed Redis provisioned with persistence and eviction policy | owner: ops | evidence: instance id and `CONFIG GET maxmemory-policy` output
E05 | PENDING | Object storage bucket for offsite backups, with lifecycle and versioning | owner: ops | evidence: bucket ARN/name, versioning status, and BACKUP_OFFSITE_KEY holder confirmation
E06 | PENDING | Backups proven restorable by an untimed restore drill | owner: ops | evidence: drill report following docs/GAP-06-DISASTER-RECOVERY-RUNBOOK.md with measured RPO/RTO
E07 | PENDING | WAL archiving enabled and point-in-time recovery rehearsed | owner: ops | evidence: archive_command in the running configuration plus a PITR restore to a chosen timestamp
E08 | PENDING | Payment provider merchant accounts approved (bKash, Nagad, Rocket, card, SSLCommerz) | owner: finance | evidence: merchant portal ids per provider, in live mode
E09 | PENDING | Per-provider webhook secrets issued in the provider portals and loaded into the secret store | owner: finance | evidence: portal screenshot per provider showing the endpoint URL and secret, plus a signed test delivery
E10 | PENDING | A real end-to-end payment taken and refunded in live mode | owner: finance | evidence: provider settlement report matching the platform ledger for the same transaction ids
E11 | PENDING | Payout channel verified against a real bank/bKash disbursement | owner: finance | evidence: bank/bKash statement line matching the reviewed reference recorded on the payout
E12 | PENDING | KYC/AML obligations reviewed with counsel for the operating jurisdiction | owner: legal | evidence: signed counsel memo or regulatory filing reference
E13 | PENDING | Terms of Service, Privacy Policy and Refund Policy published and versioned | owner: legal | evidence: live URLs plus the version identifiers shown in-app
E14 | PENDING | Data-processing agreements signed with every third-party processor | owner: legal | evidence: executed DPA per processor
E15 | PENDING | Email/SMS delivery provider configured with DKIM, SPF and DMARC | owner: ops | evidence: DNS records plus a delivered message with passing authentication headers
E16 | PENDING | Push credentials registered (FCM service account, APNs key) and loaded into the secret store | owner: mobile | evidence: console ids plus a real delivery receipt on a device
E17 | PENDING | Google Play developer account approved and the app created | owner: mobile | evidence: console listing id for `com.ffarena.ffarena_mobile`
E18 | PENDING | Apple Developer account and App Store Connect app created | owner: mobile | evidence: bundle id registration and App Store Connect app id
E19 | PENDING | Release signing key generated, stored in an HSM/KMS and backed up | owner: mobile | evidence: key fingerprint, keeper confirmation, and a successful signed upload
E20 | PENDING | Android App Links verified against the production origin | owner: mobile | evidence: `/.well-known/assetlinks.json` on the live host plus a Play Console verification pass
E21 | PENDING | iOS Universal Links verified against the production origin | owner: mobile | evidence: `apple-app-site-association` on the live host plus an on-device link open
E22 | PENDING | Store listings, screenshots and content ratings submitted and approved | owner: mobile | evidence: review approval status per store
E23 | PENDING | Production secrets generated, stored and rotated at least once | owner: ops | evidence: secret-store version history showing a rotation, never the secret values
E24 | PENDING | Error tracking and alert routing connected to an on-call destination | owner: ops | evidence: a test alert reaching the paging destination
E25 | PENDING | Prometheus scrape target live and alert rules loaded | owner: ops | evidence: `up == 1` for the production target and the loaded rule names
E26 | PENDING | Load test executed against production-like infrastructure | owner: ops | evidence: report showing p95 latency and error rate at the target concurrency
E27 | PENDING | Incident response contact tree and escalation rota agreed | owner: ops | evidence: published rota with an out-of-hours contact path
```

## Why each row is PENDING

Every row needs a credential, an account, a signature or a person that this
environment does not have. The code and configuration those rows depend on are
in place and tested (see `docs/GAP-10-FINDINGS-REGISTER.md` for what is code-complete
versus externally blocked), but a passing test suite cannot issue a TLS
certificate, approve a merchant account, or open a deep link on a physical
phone. Claiming otherwise would be fabrication, which the specification
explicitly forbids.

## What "done" looks like

A row moves to `VERIFIED` only when its evidence line contains a real
`https://` artefact and an `at:` date, and the reviewer has opened that artefact.
Until then the correct reading of this repository is:

> the software is ready for these verifications to be performed — the
> verifications themselves have not been performed.
