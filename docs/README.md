# TravelPilot — Documentation index

Project docs live here. Start with the area you care about.

## CRM module
- **[CRM Capabilities (as-built)](CRM/CRM_CAPABILITIES.md)** — a complete inventory of what the CRM does today (data model, features, APIs, screens, WhatsApp integration) **plus a gaps & enhancement-opportunities section**. Use this to compare against requirements and scope the next phase.
- **[CRM Architecture](CRM/CRM_ARCHITECTURE.md)** — the design/intent: domain model, phase roadmap (A–F, all shipped), and the rationale behind key decisions.

The CRM is a WhatsApp-native, multi-industry system covering sales (deals/kanban/quotes), service (tickets/SLA), automation (CRM events ⇄ WhatsApp via flows), and per-vertical custom objects — all multi-tenant, with a reporting dashboard. 413 backend tests green.

## Platform / integrations
- **[Meta App Review](META_APP_REVIEW.md)** — the Facebook/Instagram Lead Ads `leads_retrieval` approval process and requirements.

## Setup, cron, deploy
See the root **[README.md](../README.md)** for local setup (clone → migrate → seed → run), the `flow:work` cron config, `APP_MODE` (saas/self_hosted), and the Hostinger deploy runbook.
