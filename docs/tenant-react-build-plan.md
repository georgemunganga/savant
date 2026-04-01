# Tenant React Build Plan

Generated: 2026-03-04
Depends on: `docs/tenant-role-audit.md`

## 1) Goal
Build a standalone tenant React app that replicates monolith tenant behavior, using monolith APIs.

## 2) Delivery Strategy
Implement in vertical slices by page group, starting with auth shell + highest-traffic pages.

## 3) Proposed Tech Baseline
- React + TypeScript
- React Router
- TanStack Query (API caching/fetching)
- Formik or React Hook Form + Zod/Yup
- Axios API client with auth interceptor
- Data grid: AG Grid or TanStack Table (for datatable pages)
- Upload support: multipart/form-data with progress

## 4) App Structure (Recommended)

```txt
src/
  app/
    router/
    providers/
  core/
    api/
      client.ts
      tenant.ts
      profile.ts
      payment.ts
    auth/
    types/
    utils/
  features/
    dashboard/
    notifications/
    notices/
    invoices/
    information/
    documents/
    tickets/
    maintenance/
    chats/
    profile/
    account/
  layout/
    TenantShell/
      Sidebar.tsx
      Topbar.tsx
```

## 5) React Route Tree

```txt
/tenant/dashboard
/tenant/notifications
/tenant/notices
/tenant/invoices
/tenant/invoices/:id/pay
/tenant/information
/tenant/documents
/tenant/tickets
/tenant/tickets/:id
/tenant/maintenance-requests
/tenant/chats
/profile
/change-password
```

## 6) API Readiness Checklist (Backend)

Before full frontend build, confirm/add APIs for missing web-only features:
1. Maintenance CRUD list/detail APIs (tenant-scoped)
2. Chat list/thread/send APIs
3. Notifications list + mark seen API
4. Notices list API
5. Profile get/update API
6. Change password API
7. Delete account API
8. Payment checkout + verify APIs suitable for SPA

## 7) Phased Implementation Plan

## Phase 0: Foundation
- Create React app skeleton and CI/lint/test baseline.
- Implement auth token handling and protected routing.
- Build tenant shell layout (sidebar/topbar/language/avatar/notification badge slot).
- Build shared primitives:
  - API client
  - response/error normalizer
  - toast/alert system
  - file upload helper
  - reusable modal component

Exit criteria:
- Tenant user can sign in and open empty shell routes.

## Phase 1: Core Read Pages
Pages:
1. Dashboard
2. Invoices list
3. Invoice detail modal/page
4. Information list + detail modal

API usage:
- `/api/tenant/dashboard`
- `/api/tenant/invoices`
- `/api/tenant/invoice-details/{id}`
- `/api/tenant/information`
- `/api/tenant/information-details`

Exit criteria:
- Tenant can browse core data with parity UI blocks and statuses.

## Phase 2: Invoice Payment Flow
Pages:
1. Invoice pay page (`/tenant/invoices/:id/pay`)

API usage:
- `/api/tenant/invoice-pay/{id}`
- `/api/tenant/invoice-currency-by-gateway`
- plus checkout endpoint compatibility

Work:
- gateway picker
- currency conversion display
- bank slip upload
- redirect/callback handling

Exit criteria:
- Payment request can be initiated from React for all active gateways.

## Phase 3: Documents (KYC)
Pages:
1. Documents page with upload/edit/delete

API usage:
- `/api/tenant/documents`
- `/api/tenant/document-configs`
- `/api/tenant/document-config-info`
- `/api/tenant/document-details`
- `/api/tenant/document-store`
- `/api/tenant/document-delete/{id}`

Work:
- dual-side config handling (`is_both`)
- file preview/download
- rejected-reason display

Exit criteria:
- Full KYC lifecycle works with validation parity.

## Phase 4: Tickets
Pages:
1. Tickets list
2. Ticket details thread

API usage:
- `/api/tenant/tickets`
- `/api/tenant/ticket-topics`
- `/api/tenant/ticket-store`
- `/api/tenant/ticket-details/{id}`
- `/api/tenant/ticket-reply`
- `/api/tenant/ticket-status-change`
- `/api/tenant/ticket-delete/{id}`

Work:
- list filter/search
- create/edit/delete
- status transitions
- threaded replies + attachments

Exit criteria:
- Ticket flows match monolith behavior.

## Phase 5: Maintenance Requests
Pages:
1. Maintenance request list + add/edit

Backend note:
- Add tenant maintenance APIs first (web currently uses datatable AJAX endpoint from Blade context).

Expected APIs:
- `GET /api/tenant/maintenance-requests`
- `GET /api/tenant/maintenance-requests/{id}`
- `POST /api/tenant/maintenance-requests`
- `PUT /api/tenant/maintenance-requests/{id}`
- `DELETE /api/tenant/maintenance-requests/{id}`
- `GET /api/tenant/maintenance-issues`
- `GET /api/tenant/unit-assignments`

Exit criteria:
- Tenant can manage only scoped requests (assignment-pair enforcement).

## Phase 6: Chats
Pages:
1. Chat inbox page

Backend note:
- Existing tenant chat endpoints are web-style and return HTML fragments.
- Add JSON APIs for list/thread/send.

Expected APIs:
- `GET /api/tenant/chats/users`
- `GET /api/tenant/chats/messages?receiver_id=...`
- `POST /api/tenant/chats/messages`
- `GET /api/tenant/chats/unseen-count`

Exit criteria:
- Real-time-like chat UX (polling or websockets) working without Blade HTML.

## Phase 7: Notifications + Notices
Pages:
1. Notifications list
2. Notices list

Expected APIs:
- `GET /api/tenant/notifications`
- `PATCH /api/tenant/notifications/{id}/seen`
- `PATCH /api/tenant/notifications/seen-all`
- `GET /api/tenant/notices`

Exit criteria:
- Topbar dropdown and pages are API-driven with seen-state sync.

## Phase 8: Profile + Account
Pages:
1. My profile
2. Change password
3. Delete account flow

Expected APIs:
- `GET /api/me`
- `PUT /api/me`
- `PUT /api/me/password`
- `POST /api/me/delete-account`

Exit criteria:
- Profile parity complete.

## Phase 9: Hardening and Cutover
- Resolve backend security/consistency items:
  - enforce tenant-scoped invoice detail endpoints (view/print parity)
  - standardize API response format
  - normalize HTTP verbs (`DELETE` vs `GET` destructive calls)
- Add telemetry and error tracing.
- Run UAT against parity checklist.
- Gradual rollout + fallback.

## 8) Detailed Page-by-Page Acceptance Checklist

Use this for QA sign-off.

## Dashboard
- Property/unit/current rent/ticket count render correctly
- Paid/unpaid invoice sections match statuses
- Notice board visibility respects package/addon flags

## Invoices
- List columns and overdue badge match monolith
- View details includes items, totals, transactions
- Pay page supports gateway/currency/bank slip requirements

## Information
- Card layout fields match
- Modal detail fields match

## Documents
- Config prompts visible
- Upload/edit/delete actions work by status rules
- `is_both` config enforces back-side behavior

## Tickets
- Create/edit/delete and status transitions match
- Search and filter behavior match
- Details thread with replies/attachments works

## Maintenance
- Add/edit/delete available only for allowed statuses
- Assignment-based scoping enforced server-side

## Chats
- Conversation list + unread counts correct
- Message send and refresh behavior reliable

## Notifications/Notices
- Notifications page and topbar badge are synced
- Seen-state update is accurate

## Profile/Account
- Tenant-only fields editable
- Password change and delete account flows work end-to-end

## 9) Risk Register
1. Web endpoints returning Blade HTML are not suitable for React (chat, some search flows).
2. Mixed API response contracts will slow frontend implementation.
3. Authorization gaps in invoice detail endpoints must be fixed before public SPA release.
4. Addon-dependent features may differ by tenant package; frontend must handle feature flags dynamically.

## 10) Suggested Build Order (Shortest Path to Value)
1. Phase 0-1 (shell + dashboard + invoices + information)
2. Phase 2 (invoice pay)
3. Phase 3 (documents)
4. Phase 4 (tickets)
5. Phase 5-8 (maintenance, chats, notifications/notices, profile)
6. Phase 9 (hardening/cutover)

## 11) Immediate Next Engineering Tasks
1. Create backend ticket for missing tenant APIs (maintenance/chat/notifications/profile).
2. Create backend ticket to fix invoice detail tenant scoping.
3. Scaffold React app with route tree and feature folders.
4. Implement dashboard + invoice list first and validate with real tenant account.
