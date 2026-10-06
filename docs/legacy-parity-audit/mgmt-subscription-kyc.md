# mgmt-subscription-kyc — Legacy → New Stack Feature Inventory

**Domain:** Subscription, billing, coupons, early-access passes and KYC (audience: business / management dashboard)
**Legacy source of truth:** `storify-api`
- Routes: `routes/v1/management.php` (lines 78–89, 93–106; middleware groups at 70/93)
- Controllers: `app/Http/Controllers/Management/{SubscriptionPlanController,SubscriptionPaymentController,SubscriptionCouponController,EarlyPassController,KycController}.php`
- Views: `resources/views/management/subscription/**`, `resources/views/management/kyc.blade.php`, `resources/views/auth/business/plans.blade.php`, `resources/views/management/dashboard.blade.php` (banners), `resources/views/management/components/sidebar.blade.php` (nav)
- Supporting: `app/Http/Middleware/{CheckSubscription,RedirectIfOnboardingIncomplete}.php`, `app/Http/Requests/Management/SubmitKycRequest.php`, `app/Actions/Subscriptions/*`, `app/Jobs/ProcessTrialExpirations.php`, `app/Services/SubscriptionTrialSettings.php`, `app/Mail/*`

**New API:** `storify-api` — `routes/api/v1/management.php`, `routes/api/v1/auth.php`, `app/Http/Controllers/Api/V1/Management/**`, `app/Http/Controllers/Api/V1/Auth/**`
**New SPA:** `storify-management` — `src/router/index.ts`, `src/views/**`, `src/layouts/AppLayout.vue`, `src/api/endpoints.ts`
**Status vocabulary:** `exists` = fully usable; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

**The entire business-facing subscription/billing/coupon/early-pass/KYC domain is absent from the new stack.** The new management API (`routes/api/v1/management.php`, 79 route registrations) contains **zero** subscription, plan, coupon-redemption, early-pass or KYC endpoints — the only traces are (a) the auth payload's `subscription` state + a `next` value that can be `"plans"` (`Api/V1/Auth/Concerns/BuildsAuthResponses.php`), and (b) an admin-only `coupon-plans` helper. The management SPA has **no route, view, endpoint or sidebar item** for any of it: `AppLayout.vue` lists only Dashboard/Stores/Catalog/Sales/Team/Accounting, and the router (`src/router/index.ts`) has no `/plans`, `/subscription`, `/kyc` or `/billing` entry. The SPA stores the auth `next` value (`src/stores/auth.ts:11,35,47`) but **never routes on it**, so a business without a subscription or KYC is dropped straight onto the dashboard.

Legacy was also the thing that *enforced* the commercial gate: `CheckSubscription` blocked every module except an exempt list until a plan was paid, `StoreLifecycleController::activate` refused to activate a store unless KYC was approved, and the dashboard/sidebar surfaced trial, payment and KYC state. None of that exists in the new stack either.

| Area | Features | exists | partial | missing |
|---|---|---|---|---|
| Plan browsing & selection (incl. onboarding) | 7 | 0 | 0 | 7 |
| Billing & payment (checkout, Paystack, history, renewal) | 6 | 0 | 0 | 6 |
| Coupons | 3 | 0 | 0 | 3 |
| Early-access pass | 1 | 0 | 0 | 1 |
| KYC application & review outcome | 6 | 0 | 0 | 6 |
| Gating, banners, navigation, trial lifecycle | 7 | 0 | 1 | 6 |
| **Total** | **30** | **0** | **1** | **29** |

The single `partial` is the auth `next`-step signal: the API computes `plans` but there is no plans destination to send the user to.

**Headline:** every route under `/management/subscription`, `/management/plans`, `/management/kyc` and their supporting middleware/jobs/emails must be ported into `routes/api/v1/management.php` + `Api/V1/Management/**`, and `/plans`, `/subscription`, `/kyc` screens built into `storify-management`. Until then a newly registered business can only reach the dashboard shell — there is literally no way in the new stack to select a plan, pay for it, enter a coupon, redeem an early pass, or submit KYC. The admin side of the same domain (KYC review, plans, early passes) is also unbuilt in `storify-admin` — only coupons exist — so the business KYC flow has no one to approve it even if the submission screen were built.

---

## 1. Plan browsing and selection

### 1.1 Onboarding plan-selection page (full-page plan grid) — `missing` / `missing`
- **What the user could do (legacy):** After email verification and before/around business setup, open a standalone "Choose your plan" page showing all active non-trial plans as cards: name, description (fallback "All the essentials to get started."), `₦` price, `/interval`, "Billed annually" note for yearly plans, feature checklist (`features` array), a "Recommended" ribbon on the default plan, and a "7-day free trial · Cancel anytime" notice when trials are enabled platform-wide (`Setting.trial_enabled` / `trial_days`). When monthly and yearly plans both exist, a Monthly/Yearly tab control is shown with a "Save N%" badge computed from the cheapest monthly ×12 vs cheapest yearly (`SubscriptionPlanController::yearlySavings`). Each card's "Get Started" button posts the plan to the select-plan endpoint. Empty state: "No plans available / contact support".
- **Legacy route:** `GET /management/plans` (`management.plans.index`)
- **Legacy controller:** `Management\SubscriptionPlanController@onboarding`
- **Legacy views:** `resources/views/auth/business/plans.blade.php`, `resources/views/auth/business/_plan-card.blade.php`
- **New API:** none. Auth `me`/`login`/`verify-otp` only return `next: "plans"` (`Api/V1/Auth/Concerns/BuildsAuthResponses.php:135-160`).
- **New SPA:** none — no `/plans` route; the stored `next` value is never acted on (`src/stores/auth.ts`, `src/views/auth/LoginView.vue:31`).
- **Missing:** the whole page: plan listing endpoint (id, name, description, amount, currency, interval, interval_count, features, is_default), monthly/yearly grouping + tab state, savings computation, recommended ribbon, trial notice driven by platform settings, plan select CTA, empty state, unauthenticated-safe layout, and the route-guard redirect for `next === 'plans'` (see 6.1/6.6).
- **Side effects:** none on view.
- **Effort:** M — **Priority:** P0

### 1.2 In-dashboard subscription page — current plan summary card — `missing` / `missing`
- **What the user could do (legacy):** Open `/management/subscription` and see the current plan: plan name, Active/other status badge (`subscription->isActive()`), price per interval (`₦…/month`), and a 4-cell summary grid — Started (`starts_at`), Renews (`expires_at`), Billing cycle, Next Amount. If the user had a subscription row that is not active, the badge showed the raw status (`ucfirst`).
- **Legacy route:** `GET /management/subscription` (`management.subscription.plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@index`
- **Legacy views:** `resources/views/management/subscription/plan.blade.php` (lines 8–51)
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint returning current subscription (plan name/amount/interval, status, starts_at/expires_at, next amount) and the card itself.
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 1.3 In-dashboard subscription page — trial status card — `missing` / `missing`
- **What the user could do (legacy):** When on a trial and without a subscription, see a "Free Trial · Active" card: `₦0.00 for {{trial_days}} days`, "N days remaining in your trial", trial end date, and a "Subscribe Now" button linking to the payment page.
- **Legacy route:** `GET /management/subscription` (`management.subscription.plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@index` (`$user->isOnTrial()`, `daysLeftOnTrial()`)
- **Legacy views:** `resources/views/management/subscription/plan.blade.php` (lines 53–81)
- **New API:** the auth payload exposes `subscription.state = "trial"` + `trial_ends_at` (`BuildsAuthResponses::subscriptionPayload`), but there is no subscription-page endpoint.
- **New SPA:** none.
- **Missing:** trial card, days-remaining computation, Subscribe-Now deep link.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 1.4 In-dashboard subscription page — available-plans grid with switch buttons — `missing` / `missing`
- **What the user could do (legacy):** Browse all active non-trial plans beneath the current subscription (or as the primary content when unsubscribed) in a Monthly/Yearly tabbed grid, with a "Save N%" badge on the Yearly tab, current plan card highlighted with a "Current Plan" pill and de-emphasised, default plan labelled "Popular", each card showing features and description, and a "Switch to {name}" button opening the change-plan modal (subscribed users) or a "Get Started"/"Select Plan" form posting select-plan (unsubscribed users). Plans with other intervals (daily/weekly) are rendered in a separate "Other Billing Cycles" section below the tabs. Empty states per tab.
- **Legacy route:** `GET /management/subscription` (`management.subscription.plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@index` (`$monthlyPlans/$yearlyPlans/$otherPlans`, `$yearlySavingsPercent`)
- **Legacy views:** `resources/views/management/subscription/plan.blade.php` (lines 83–143), `resources/views/management/subscription/_plan-card.blade.php`
- **New API:** none (the public marketing site gets plans from `Api\V1\Home\HomeController::plans`, cached, but that endpoint is for the anonymous marketing page, has no auth/team context and no selection actions).
- **New SPA:** none.
- **Missing:** entire grid + tabs + savings badge + current/popular states + the two CTAs.
- **Side effects:** none.
- **Effort:** M — **Priority:** P0

### 1.5 Change plan (upgrade / downgrade) — `missing` / `missing`
- **What the user could do (legacy):** With an active subscription, click "Switch to {plan}", get a confirmation modal ("You are switching to {plan} — ₦{price}/{interval}. Your current billing period will remain unchanged. The new plan price will take effect on your next renewal date."), and confirm. The plan is swapped immediately on the subscription row; no proration, no charge today; success flash "The new billing amount will apply on your next renewal." Validation: plan must exist, be active, non-trial, and different from current; otherwise "Invalid plan selection." An audit log line `subscription.plan_changed` records old/new plan ids.
- **Legacy route:** `POST /management/subscription/change-plan` (`management.subscription.change-plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@change`
- **Legacy views:** `resources/views/management/subscription/plan.blade.php` (changePlanModal, lines 202–245)
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint + modal + validation + confirmation messaging + log.
- **Side effects:** subscription row mutated (plan id); `Log::info('subscription.plan_changed')`.
- **Effort:** M — **Priority:** P1

### 1.6 Select plan / start free trial — `missing` / `missing`
- **What the user could do (legacy):** Choose a plan while unsubscribed. Server behaviour: reject if an active subscription already exists ("You already have an active subscription."); validate plan active/non-trial; set `selected_plan_id`, force `is_verified = true`; if the platform trial is enabled set `trial_ends_at = now + trial_days` and land on the dashboard with "Your N-day free trial has started!"; otherwise redirect to the payment page with "Please complete payment to activate your subscription."
- **Legacy route:** `POST /management/subscription/select-plan` (`management.subscription.select-plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@select`
- **Legacy views:** used from `auth/business/_plan-card.blade.php` and `management/subscription/_plan-card.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint + trial-start side effects + both redirect branches + guard against double selection.
- **Side effects:** user row (`selected_plan_id`, `trial_ends_at`, `is_verified`).
- **Effort:** S — **Priority:** P0

### 1.7 Per-plan checkout deep link — `missing` / `missing` (legacy stub, recommend skipping)
- **What the user could do (legacy):** Nothing useful — `GET /management/plans/checkout/{plan}` always redirects back to the subscription page with "Please select a plan to start your free trial." It is a dead-end legacy route kept only for URL compatibility (`plan_code` route key).
- **Legacy route:** `GET /management/plans/checkout/{plan}` (`management.plans.checkout`)
- **Legacy controller:** `Management\SubscriptionPlanController@checkout`
- **Legacy views:** none.
- **New API / SPA:** none.
- **Missing:** intentionally nothing — **judgement: obsolete, do not port.** The real flows are 1.6 (select → trial/payment) and 4.5 (change).
- **Effort:** S — **Priority:** P2

---

## 2. Billing, payment and renewal

### 2.1 Payment / checkout page — `missing` / `missing`
- **What the user could do (legacy):** After selecting a plan, open a checkout page with: a trial warning banner when on trial ("Your trial ends in N days — Pay now to keep your stores active without interruption."), a plan summary card (name, interval chip, feature list), a price breakdown (Plan Price → Total, `₦`), and a single "Pay with Paystack · ₦{amount}" submit button carrying a fresh UUID idempotency key, plus a "Secure payment powered by Paystack" note. Guards: active subscription → redirect to dashboard "You already have an active subscription."; no `selected_plan_id` → redirect to plan page "Please select a plan first."; selected plan deleted/inactive → redirect with "Selected plan is no longer available."
- **Legacy route:** `GET /management/subscription/payment` (`management.subscription.payment`)
- **Legacy controller:** `Management\SubscriptionPaymentController@show`
- **Legacy views:** `resources/views/management/subscription/payment.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** entire page + guard redirects + Paystack public key delivery + idempotency-key generation.
- **Side effects:** none on view. (Note: legacy does **not** show the coupon-discounted amount on this page even when a coupon is in session — the discount is applied server-side at initialize. Worth fixing while porting, not a parity requirement.)
- **Effort:** M — **Priority:** P0

### 2.2 Paystack payment initialization (idempotent) — `missing` / `missing`
- **What the user could do (legacy):** Submit the pay button and be redirected to Paystack's hosted authorization URL. Server behaviour: block if already subscribed; resolve `selected_plan_id` (or default plan); look up the coupon from session, validate it against the plan and compute the discounted amount (`max(0, amount - discount)`); if a fully-covering coupon reduces the total to ≤ 0, activate the subscription immediately with no payment (see 3.3); otherwise check the `idempotency_key` against `payments` — an existing attempt re-redirects to its stored `authorization_url` rather than creating a second charge; create `Subscription` (pending) + `Payment` (pending, type `subscription`, ip, metadata plan/coupon) + `Transaction` (pending) atomically; call Paystack initialize with email/amount-in-kobo/currency/reference/callback; on failure mark payment failed, subscription cancelled, transaction canceled with an error flash; on success store the gateway response and redirect away.
- **Legacy route:** `POST /management/subscription/process-payment` (`management.subscription.process-payment`)
- **Legacy controller:** `Management\SubscriptionPaymentController@initialize`
- **Legacy views:** form on `resources/views/management/subscription/payment.blade.php`
- **New API:** none. (`StoreBankController`/POS Paystack integrations exist elsewhere; no subscription payment endpoint.)
- **New SPA:** none.
- **Missing:** endpoint, `PaystackService` port for subscription context, idempotency-key contract, atomic pending rows, coupon-aware amount, redirect to gateway, failure rollback.
- **Side effects:** creates `subscriptions`, `payments`, `transactions` rows; external Paystack API call; session coupon cleared on full-cover path.
- **Effort:** L — **Priority:** P0

### 2.3 Payment callback, gateway verification and subscription activation — `missing` / `missing`
- **What the user could do (legacy):** Return from Paystack to the callback route and have the payment verified and the account activated. Server behaviour: find payment by reference for the authenticated user (else "Payment record not found."); short-circuit if already successful ("Payment already processed successfully."); double-verify with Paystack and require `status=success`, exact amount match (kobo) and currency match — else mark payment failed "Gateway verification mismatch." and flash "Payment verification failed."; in a locked transaction mark payment success (gateway ref, full response, `paid_at`), mark transaction confirmed, activate subscription (status active, `starts_at=now`, `expires_at=+1 month`/`+1 year` by interval), clear user trial, set user active/verified, activate the business's pending/suspended stores, increment coupon usage (and deactivate an exhausted coupon); then queue the activation notification (StoreActivated email to the owner + AdminStoreCreated to superadmins), post the subscription payment to the ledger (`LedgerPostingService::postSubscriptionPayment`), clear session keys and land on the dashboard with "Subscription payment successful."
- **Legacy route:** `GET /management/subscription/callback` (`management.subscription.callback`)
- **Legacy controller:** `Management\SubscriptionPaymentController@callback`
- **Legacy views:** none (redirect-only).
- **New API:** none. This is the money path — it is the single most important missing endpoint in the domain.
- **New SPA:** none (the SPA would return to a callback URL or poll; no handling).
- **Missing:** callback endpoint, double verification, amount/currency checks, idempotent activation, store activation, coupon usage increment/exhaustion, notification emails, ledger posting, user feedback messages.
- **Side effects:** payment/transaction/subscription/store/user/coupon mutations; StoreActivated + AdminStoreCreated emails; ledger entries; logs.
- **Effort:** L — **Priority:** P0

### 2.4 Billing history table — `missing` / `missing`
- **What the user could do (legacy):** See the last 20 payments for the current subscription under the plan grid: date + time, description (plan name from payment metadata, plus payment type), truncated reference (monospace), amount `₦`, and a status pill (Paid / Pending / Failed). Table is hidden entirely when there are no payments, and is empty while the user has no active subscription (the controller only loads payments for the active subscription).
- **Legacy route:** `GET /management/subscription` (`management.subscription.plan`)
- **Legacy controller:** `Management\SubscriptionPlanController@index` (`Payment::where('subscription_id', …)->latest()->take(20)`)
- **Legacy views:** `resources/views/management/subscription/plan.blade.php` (lines 145–192)
- **New API:** none for billing history. Subscription payments *do* create `Transaction` rows that surface in the generic `GET /api/v1/management/transactions` list (`Api\V1\Management\TransactionController@index` filters only by status + reference), but there is no plan-name/type column and no subscription scoping.
- **New SPA:** none; `src/views/TransactionsView.vue` shows the generic list only.
- **Missing:** billing-history payload (or a subscription filter on transactions) + the table with date/description/reference/amount/status and the 20-row cap.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 2.5 Renewal — `missing` / `missing`
- **What the user could do (legacy):** There is **no self-service renewal button and no auto-charge**: when `expires_at` lapses, `CheckSubscription` treats the business as needing a subscription and bounces every non-exempt route to `/management/plans` with "Please select a plan to continue."; the user picks a plan again and pays again, creating a *new* `Subscription` row (payment type would be `renewal` by enum but no code path sets it). The dashboard banner says stores are paused and offers "Subscribe Now". There is no proration and no scheduled renewal job in the legacy codebase.
- **Legacy route:** `GET /management/plans` redirect via `CheckSubscription` (`app/Http/Middleware/CheckSubscription.php`)
- **Legacy controller:** `Management\SubscriptionPlanController@onboarding` + `SubscriptionPaymentController@initialize`
- **Legacy views:** `resources/views/auth/business/plans.blade.php`
- **New API:** none (auth `next` returns `plans` when no active subscription and no future trial — the only renewal-adjacent logic in the new stack).
- **New SPA:** none.
- **Missing:** the expiry → plans redirect experience and the re-purchase flow (1.1/1.6/2.1/2.2/2.3). Note for the port: consider an explicit "Renew now" affordance and `payment_type = renewal`, which legacy never delivered.
- **Side effects:** same as 2.2/2.3 on re-purchase.
- **Effort:** M (mostly covered by 1.1/2.2/2.3; this entry is the gate + messaging) — **Priority:** P1

### 2.6 Cancellation — `missing` / `missing` (never existed in legacy)
- **What the user could do (legacy):** **Nothing — there is no business-facing cancellation anywhere in legacy**: no route, no controller method, no button. `Subscription::STATUS_CANCELLED` is only written as cleanup when Paystack initialization fails. Cancelling a subscription is, today, an admin/support action. This entry records the absence so it is a conscious decision, not an oversight.
- **Legacy route:** none.
- **Legacy controller:** none.
- **Legacy views:** none.
- **New API / SPA:** none.
- **Missing:** product decision — either keep cancellation admin-only (recommended for parity) or add a business cancel/schedule-cancel flow with end-of-period semantics.
- **Effort:** S (if parity: document only) — **Priority:** P2

---

## 3. Coupons

### 3.1 Coupon code entry, applied chip and removal — `missing` / `missing`
- **What the user could do (legacy):** On the plans page, click "Have a coupon code?", enter an uppercase code in a modal, press Apply (or Enter), see an inline success/error message, and — on success — a green chip showing the code with "applied" and an × to remove it. Applied state survives page loads because the code is kept in the session (`applied_coupon_code`); removal clears the session. While loading the button shows "Applying…".
- **Legacy route:** `POST /management/plans/validate-coupon` (`management.plans.validate-coupon`), `POST /management/plans/remove-coupon` (`management.plans.remove-coupon`)
- **Legacy controller:** `Management\SubscriptionCouponController@validateCoupon`, `@remove`
- **Legacy views:** `resources/views/auth/business/plans.blade.php` (coupon section + modal, Alpine `plansPage`, lines 103–151 and 158–216)
- **New API:** none.
- **New SPA:** none.
- **Missing:** both endpoints, the modal, chip, session persistence semantics (or a token/state equivalent in the SPA), error/success messaging.
- **Side effects:** session key only.
- **Effort:** M — **Priority:** P1

### 3.2 Coupon validation semantics — `missing` / `missing`
- **What the user could do (legacy):** Have the server validate a code with these exact rules and messages: unknown/inactive/expired means "Invalid or expired coupon code."; when a `plan_id` is supplied and the coupon is scoped to another plan, "This coupon only applies to {plan}."; otherwise return `valid`, the normalised code, `discount_type`, `discount_value`, plan name, and a human description — "✓ 20% off on {plan}" / "✓ ₦5,000.00 off on any plan" — and stash the code in the session so `initialize` picks it up at checkout. The discount is only actually applied on the payment-initialization step.
- **Legacy route:** `POST /management/plans/validate-coupon` (`management.plans.validate-coupon`)
- **Legacy controller:** `Management\SubscriptionCouponController@validateCoupon`; model rules in `App\Models\Coupon::isValid()/isApplicableTo()/calculateDiscount()`
- **Legacy views:** plans modal (see 3.1)
- **New API:** none. (Admin-side coupon CRUD exists: `Api\V1\Admin\CouponController` + `api.admin.coupons.*` — the business redemption side is absent.)
- **New SPA:** none.
- **Missing:** validation endpoint with per-plan applicability + discount description + session/state handoff to checkout.
- **Side effects:** session key.
- **Effort:** S — **Priority:** P1

### 3.3 Fully-covering coupon instant activation (and exhausted-coupon admin alert) — `missing` / `missing`
- **What the user could do (legacy):** Enter a coupon worth ≥ 100% (percentage) or ≥ plan amount (fixed): the subscription is activated immediately with **no payment step**, the response is `{valid: true, activated: true, message: "✓ {plan} activated! Taking you to your dashboard…", redirect_url}`, the browser forwards to the dashboard after ~2 s, stores are activated, the coupon's `uses_count` is incremented, and if that exhausts it, `is_active` is set false and a `CouponExhaustedMail` is queued to the configured admin address. The same automatic-activation path also exists inside `SubscriptionPaymentController@initialize` when the session coupon reduces the total to ≤ 0.
- **Legacy route:** `POST /management/plans/validate-coupon` (`management.plans.validate-coupon`); fallback inside `POST /management/subscription/process-payment`
- **Legacy controller:** `Management\SubscriptionCouponController@validateCoupon` + `App\Actions\Subscriptions\ActivateSubscriptionWithCoupon`
- **Legacy views:** plans modal (auto-redirect branch)
- **New API:** none.
- **New SPA:** none.
- **Missing:** the `ActivateSubscriptionWithCoupon` action port (locks, business check, coupon validity/applicability/full-cover test, subscription creation with metadata coupon_code/id, store activation, coupon increment/exhaustion), the admin exhaustion email, and the auto-redirect UX.
- **Side effects:** subscription active (coupon metadata), stores activated, user active/verified, coupon uses_count++/is_active=false, `CouponExhaustedMail` to admin, activation notification emails.
- **Effort:** M — **Priority:** P1

---

## 4. Early-access pass

### 4.1 Early-access pass redemption — `missing` / `missing`
- **What the user could do (legacy):** POST a pass code and receive JSON. Server behaviour: look up `early_passes.code` (trimmed), take the active default plan; invalid code → "Invalid code. Please check and try again."; no plan → "No subscription plan available."; otherwise the `ActivateSubscriptionWithEarlyPass` action locks user/pass/business, requires a business (else "Complete business setup before using an early pass."), requires the pass to be available (`is_active`, uses under `max_uses`) else "This code is not valid or has reached its usage limit.", forbids reuse by the same user ("You have already used this code.") and forbids stacking on an active subscription ("You already have an active subscription."); on success it creates a 1-year active subscription with metadata (`early_pass_id`, `early_pass_code`, `payment_skipped`), clears trial, activates the user and pending/suspended stores, writes an `EarlyPassUsage` row and deactivates the pass at its max uses; then queues the activation emails and returns `{success, "Early access activated! Redirecting...", redirect_url}`.
- **Legacy route:** `POST /management/subscription/check-early-pass` (`management.subscription.check-early-pass`)
- **Legacy controller:** `Management\EarlyPassController@apply`
- **Legacy views:** **none found** — grepping all Blade/JS for the route or an "early access" form shows the route is only referenced by middleware exempt lists and `tests/Feature/Management/EarlyPassTest.php`. The redemption UI appears to have been removed/orphaned in legacy while the endpoint was kept.
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint + action + JSON contract. **Judgement:** the endpoint is genuinely used by tests and is referenced in middleware exemptions, so it should be ported; the missing UI means the new SPA should decide where the code-entry field belongs (e.g. beside the coupon modal on the plans page).
- **Side effects:** subscription (active, 1 year, metadata), stores activated, user trial cleared/active, `early_pass_usages` row, pass deactivated at limit, activation notification emails, logs.
- **Effort:** M — **Priority:** P1

---

## 5. KYC application

### 5.1 KYC status page (four states + resubmission entry point) — `missing` / `missing`
- **What the user could do (legacy):** Open "KYC Verification" and see one of: **Not Verified** (grey pill, "Identity verification required — submit your KYC documents to unlock store activation") with a "Submit KYC Documents" button; **Under Review** (amber pill, "Submitted {relative time}", "We'll notify you once your documents have been verified… 1–2 business days"); **Verified** (green pill, "Approved {relative time}", "You can now activate your stores and start selling"); or **Rejected** (red pill, "{rejected_at} ago", "Your KYC application was not approved" plus **Reason: {review_notes}** when present) with a "Resubmit KYC" button. States are driven by `kycApplication` (latest application only — `User::kycApplication()` is `hasOne(...)->latestOfMany()`).
- **Legacy route:** `GET /management/kyc` (`management.kyc.show`)
- **Legacy controller:** `Management\KycController@show` (loads active `KycDocumentType` list too)
- **Legacy views:** `resources/views/management/kyc.blade.php` (lines 7–54)
- **New API:** none.
- **New SPA:** none.
- **Missing:** status payload (status, submitted_at, approved_at, rejected_at, review_notes) + document-type list + all four state cards + resubmit entry.
- **Side effects:** none.
- **Effort:** M — **Priority:** P0

### 5.2 KYC submission modal — identity & address fields — `missing` / `missing`
- **What the user could do (legacy):** Submit a KYC application through a modal form with these required fields: Full Legal Name (prefilled with account name, "As on your ID"), Phone Number (prefilled), Date of Birth (date input), Address line, City / State / Country (Country prefilled "Nigeria"). Validation (`SubmitKycRequest`): strings with max lengths (255/50/500/120/120/120), `date_of_birth` required, a date, and `before:-18 years` with the message "You must be at least 18 years old to onboard as a business owner." Native `required` attributes mirror this client-side; the modal reopens automatically when server validation fails so the user sees field errors.
- **Legacy route:** `POST /management/kyc` (`management.kyc.submit`)
- **Legacy controller:** `Management\KycController@submit`; `App\Http\Requests\Management\SubmitKycRequest`
- **Legacy views:** `resources/views/management/kyc.blade.php` (modal, lines 56–145)
- **New API:** none.
- **New SPA:** none.
- **Missing:** multipart form endpoint + validation (including the 18-year rule) + modal field groups + error surfacing.
- **Side effects:** part of 5.4.
- **Effort:** M — **Priority:** P0

### 5.3 KYC submission modal — document type, ID number and file uploads — `missing` / `missing`
- **What the user could do (legacy):** Select a **Document Type** from the active `kyc_document_types` list and enter the **Document ID Number** ("NIN, BVN, Passport number"); upload an **Identification Document** (accept `.jpg,.jpeg,.png,.pdf`, max 5 MB, message "Identification must be a JPG, PNG, or PDF file." / "…cannot exceed 5MB.") and a **Selfie Photo** (accept `.jpg,.jpeg,.png`, max 4 MB, image mime, messages "Selfie must be a JPG or PNG file." / "…cannot exceed 4MB."). Files are stored on the `public` disk under `kyc/documents` and `kyc/selfies`. The submit handler also captures device type (mobile/tablet/desktop/unknown via user-agent), browser string (UA truncated to 255) and IP address on the application row.
- **Legacy route:** `POST /management/kyc` (`management.kyc.submit`)
- **Legacy controller:** `Management\KycController@submit`, `@detectDeviceType`
- **Legacy views:** `resources/views/management/kyc.blade.php` (upload fields, lines 121–135)
- **New API:** none.
- **New SPA:** none.
- **Missing:** document-type lookup, upload handling + validation + storage paths, metadata capture. (Legacy detail to note: old-file deletion code exists but never fires because a fresh model instance is created; resubmissions orphan previous files — fix while porting.)
- **Side effects:** files written to `storage/app/public/kyc/{documents,selfies}`; paths on the application.
- **Effort:** M — **Priority:** P0

### 5.4 KYC submission / resubmission state machine and guards — `missing` / `missing`
- **What the user could do (legacy):** Submit KYC only when allowed. Guards: unverified email redirects to OTP with "Verify your email before submitting your KYC information."; a `submitted` application blocks with "Your KYC is currently under review."; an `approved` application blocks with "Your KYC has already been approved."; a `rejected` (or absent) application may submit/resubmit. On success a new `KycApplication` row is written with status `submitted`, `submitted_at = now`, and `approved_at/rejected_at/review_notes` nulled, the user's status is forced to `pending`, and both an info log `business.kyc.submitted` and a submission log with the full payload are written; the user lands back on the KYC page with "KYC submitted successfully! We'll review your documents and notify you within 1-2 business days."
- **Legacy route:** `POST /management/kyc` (`management.kyc.submit`)
- **Legacy controller:** `Management\KycController@submit`
- **Legacy views:** `resources/views/management/kyc.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** status transitions, guards, new-row-per-submission semantics (history retained in DB, latest shown), user status side effect, flash messaging.
- **Side effects:** `kyc_applications` row; user `status = pending`; logs.
- **Effort:** M — **Priority:** P0

### 5.5 KYC submission notification emails — `missing` / `missing`
- **What the user could do (legacy):** Nothing directly, but the system queues two emails on submission: `BusinessKycSubmitted` to the owner ("we received your documents") and `AdminKycSubmitted` to every superadmin email (fallback: `mail.from.address`), each logged; failures are caught and logged without breaking the request (`business.kyc.business_mail_queued` / `business.kyc.admin_mail_queued` / `…_failed`). Email templates: `resources/views/emails/business/kyc-submitted` (via mailable) and `resources/views/emails/admin/kyc-submitted.blade.php`.
- **Legacy route:** `POST /management/kyc` (side effects)
- **Legacy controller:** `Management\KycController@queueBusinessNotification`, `@queueAdminNotification`, `@adminRecipients`
- **Legacy views:** mailables `App\Mail\{BusinessKycSubmitted,AdminKycSubmitted}`
- **New API:** none.
- **New SPA:** none.
- **Missing:** both mailables + queueing + recipient resolution + failure isolation.
- **Side effects:** queued mail.
- **Effort:** S — **Priority:** P0

### 5.6 KYC approval / rejection outcome visible to the business — `missing` / `missing`
- **What the user could do (legacy):** Receive the outcome of admin review. On **approve** the admin screen sets application status `approved`, `approved_at = now`, reviewer id, optional review notes, sets the owner's user status `active`, and queues `KycApproved` to the owner. On **reject** it sets status `rejected`, `rejected_at`, **required** reviewer notes, and sets user status `pending` — the notes then appear on the business KYC page as the rejection reason (5.1). Store activation is only possible after approval (5.7). The business-facing surface is the KYC page + dashboard banners; the queueing and status semantics must be reproduced, but the **admin review screens themselves (`admin.business-kyc.*`) are outside this audit's audience scope** — note that `storify-admin` has no KYC view/route/endpoint at all, only a `kyc_pending` count on its dashboard.
- **Legacy route:** admin-side `GET/POST /office/business-kyc-applications*` (`admin.business-kyc.index/show/approve/reject`) feeding the business-visible status
- **Legacy controller:** `Admin\BusinessKycApplicationController@approve/@reject`; mailables `App\Mail\KycApproved`
- **Legacy views:** `resources/views/admin/businesses/kyc/{index,show}.blade.php` (admin), reflected in `resources/views/management/kyc.blade.php`
- **New API:** none (admin `Api\V1\Admin\BusinessController` exposes only a `kyc_pending` count on the dashboard payload; no applications endpoint).
- **New SPA (business):** none; (admin SPA has no KYC screen).
- **Missing:** approve/reject transitions with notes, `KycApproved` email, user status flip, and the business-visible status/notes rendering.
- **Side effects:** application + user status; `KycApproved` email; admin logs `admin.business_kyc.approved/rejected`.
- **Effort:** M — **Priority:** P0

### 5.7 KYC gate on store activation — `missing` / `missing`
- **What the user could do (legacy):** Activate a suspended store only if KYC is approved; otherwise the activate endpoint refuses (the store stays unusable). Conversely, the dashboard shows a purple banner "Complete KYC to publish your store(s)" with a count of pending/inactive stores and a "Complete KYC" button when no application (or only rejected/draft) exists, or an amber "KYC under review — stores will be activatable once approved" banner with "View Status" when submitted. These KYC banners are business-owner only and only shown when there are pending stores.
- **Legacy route:** `PATCH /management/stores/{store}/activate` (`management.stores.activate`) + banner on `GET /management/` (`management.dashboard`)
- **Legacy controller:** `Management\StoreLifecycleController@activate` (`$kyc->status !== KycApplication::STATUS_APPROVED` check); `Management\DashboardController@index` + view
- **Legacy views:** `resources/views/management/dashboard.blade.php` (lines 76–98)
- **New API:** none — the new management API has no store lifecycle endpoints yet (only `GET stores`), so the gate has no home.
- **New SPA:** none — no dashboard banner; `DashboardView.vue` has no KYC state.
- **Missing:** the activation guard (belongs to the stores domain but is enforced by KYC state) + the two dashboard banners with pending-store counts.
- **Side effects:** store status transitions.
- **Effort:** S (banners) / M (guard, with stores domain) — **Priority:** P0

---

## 6. Subscription gating, status surfaces and trial lifecycle

### 6.1 Subscription gate middleware + plans redirect — `missing` / `missing`
- **What the user could do (legacy):** Be prevented from using the product without paying: `CheckSubscription` passes staff and users on trial, but for a business owner with no active subscription it redirects every route **except an exempt list** (dashboard, setup, plans/*, subscription/*, check-early-pass, profile, KYC) to `/management/plans` with "Please select a plan to continue." Unverified users are first redirected to OTP ("Please verify your email to continue."). Its sibling `RedirectIfOnboardingIncomplete` enforces force-password-change → verify-email → business-setup ordering, also exempting plans/subscription/early-pass/KYC routes so onboarding can proceed.
- **Legacy route:** middleware on `POST/GET` group `routes/v1/management.php:93`; configured as `management.subscription` / `management.onboarding`
- **Legacy controller:** `App\Http\Middleware\{CheckSubscription,RedirectIfOnboardingIncomplete}`
- **Legacy views:** redirects to `resources/views/auth/business/plans.blade.php`
- **New API:** none — the management API has no subscription-gate middleware; endpoints are protected only by permissions. The auth payload's `next` value (`plans`) is the only signal (`BuildsAuthResponses::nextStep`).
- **New SPA:** none — the router guard checks only `hasSession()`/`fetchMe()`; `next` is stored but never used (`src/stores/auth.ts:11,35,47`; `src/router/index.ts:69-90`).
- **Missing:** server-side gate (403/redirect contract for API consumers) and SPA guard that redirects `next === 'plans'` to a plans screen; exempt-route list semantics.
- **Side effects:** none.
- **Effort:** M — **Priority:** P0

### 6.2 Auth `next`-step gating (plans state) — `partial` / `missing`
- **What the user could do (legacy):** After login/OTP, be sent onward by the onboarding state machine (verify email → setup → plans → pay → dashboard).
- **Legacy route:** n/a (middleware-driven redirects, see 6.1)
- **Legacy controller:** `App\Http\Middleware\{RedirectIfOnboardingIncomplete,CheckSubscription}`
- **Legacy views:** n/a
- **New API:** `POST /api/v1/management/auth/{login,verify-otp}` and `GET …/auth/me` return `next` ∈ {`change_password`,`dashboard`,`verify_email`,`setup`,`plans`} and a `subscription` payload (`{state: active|trial|none, plan, expires_at, trial_ends_at}`) — `BuildsAuthResponses.php:32-63,135-160`. This is real, useful groundwork.
- **New SPA:** `auth.next` is captured but **never consumed**; no plans route exists, so `plans` falls through to the dashboard.
- **Missing (SPA side only):** consuming `next` in login/OTP/fetchMe flows; a `/plans` route to land on; SPA-side rendering of `subscription` state (trial/past-due affordances).
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 6.3 Dashboard subscription banners — `missing` / `missing`
- **What the user could do (legacy):** See one of four mutually exclusive banners on the dashboard when there is no active subscription: (a) trial ending within 2 days — blue, "Your free trial ends in N day(s)", "Upgrade Now" → payment page; (b) trial expired — red, "Your stores are paused. Subscribe to reactivate them.", "Subscribe Now"; (c) plan selected but unpaid — amber, "You chose the **{plan}** plan. Pay now to activate your stores.", "Pay Now"; (d) no plan at all — amber, "Select a plan to activate your stores and start selling.", "Choose Plan" → subscription page.
- **Legacy route:** `GET /management/` (`management.dashboard`)
- **Legacy controller:** `Management\DashboardController@index`
- **Legacy views:** `resources/views/management/dashboard.blade.php` (lines 39–74)
- **New API:** `GET /api/v1/management/dashboard` returns metrics only; the auth `me` subscription payload has the raw state but not `selected_plan` name, and the dashboard payload carries no banner data.
- **New SPA:** `src/views/DashboardView.vue` has no banner; `src/layouts/AppLayout.vue` shows no plan/trial indicator.
- **Missing:** banner data (subscription state, days left, expired flag, selected plan name) + the four banner variants with their CTAs.
- **Side effects:** none.
- **Effort:** M — **Priority:** P0

### 6.4 Sidebar navigation — Subscription and KYC entries with live badges — `missing` / `missing`
- **What the user could do (legacy):** Navigate from the sidebar to "Subscription" (highlighted when on `management.subscription.*`) and "KYC Verification" with a status dot/badge derived from the latest application: submitted = amber "Under review"-style indicator, approved = green, rejected = red (no badge when absent).
- **Legacy route:** n/a (sidebar partial rendered on every management page)
- **Legacy controller:** view composer / `resources/views/management/components/sidebar.blade.php` (lines 239–242, 314–326)
- **Legacy views:** `resources/views/management/components/sidebar.blade.php`
- **New API:** none (auth `me` returns no KYC state at all).
- **New SPA:** `src/layouts/AppLayout.vue` nav has no Subscription or KYC items; no KYC state is fetched anywhere.
- **Missing:** both nav entries, KYC status in the user payload, badge rendering.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 6.5 Trial lifecycle emails and auto-expiry (scheduled job) — `missing` / `missing`
- **What the user could do (legacy):** Receive trial reminder emails while ≤3 days remain (one per day: days 1–3), a day-0 "expired" reminder, and — 3 days after expiry — a `TrialExpiredMail` while all of the user's **active stores are set back to `pending`** so the storefront goes dark; the whole job is skipped when the platform trial setting is disabled. Scheduled from `routes/console.php` via `Schedule::job(new ProcessTrialExpirations)`. Template views: `resources/views/emails/trial_expiry_reminder.blade.php`, `resources/views/emails/trial_expired.blade.php`. Users with an active subscription are excluded. (The `Subscription` model also carries legacy `trial_reminder_day5/6/7_sent_at` / `trial_expired_sent_at` columns, but the shipped job does not use them — it re-sends per run; fix while porting to avoid duplicate emails.)
- **Legacy route:** scheduled job (no HTTP route)
- **Legacy controller:** `App\Jobs\ProcessTrialExpirations`; mailables `TrialExpiryReminderMail`, `TrialExpiredMail`
- **Legacy views:** `resources/views/emails/trial_*.blade.php`
- **New API:** none; no trial-related job exists in the new stack (console commands are only `BackfillStockLocationBusinessId`, `ReconcileLedger`, `SetupLedger`, `StoreWipe`, `SyncPermissions`, `WarehouseDelete`, `WarehouseWipe`).
- **New SPA:** none (receives emails only).
- **Missing:** job + scheduler entry + both mailables + store pausing; idempotency markers.
- **Side effects:** queued emails; stores `active → pending`.
- **Effort:** M — **Priority:** P1

### 6.6 Subscription settings surface (trial enable/days) — `missing` / `missing`
- **What the user could do (legacy):** Business users never edited these; the platform admin controlled `Setting.trial_enabled` / `trial_days`, and `SubscriptionTrialSettings` fed the plans page, trial notices and job. Flagged here because the plans page and job depend on it and the new stack has no settings endpoint for it.
- **Legacy route:** n/a (admin settings)
- **Legacy controller:** `App\Services\SubscriptionTrialSettings`; admin settings screens outside scope
- **Legacy views:** n/a
- **New API:** none.
- **New SPA:** none.
- **Missing:** a settings read path for plan/trial copy (can be folded into the plans endpoint).
- **Effort:** S — **Priority:** P2

---

## Gaps worth calling out

1. **The whole domain is a greenfield port, not a gap-fill.** 29 of 30 features are `missing`; nothing here is a thin screen — there is no screen. `routes/api/v1/management.php` has 79 route registrations and not one is subscription/plan/coupon-redemption/early-pass/KYC. The SPA router has 21 routes and none is `/plans`, `/subscription` or `/kyc`.
2. **Onboarding is dead-ended in the new stack.** The API already tells the SPA `next: "plans"` (`BuildsAuthResponses::nextStep`) and the SPA already stores it — but nothing consumes it, and there is no plans destination. A newly registered business owner today reaches a dashboard whose API calls the subscription-gate was supposed to block. Building 1.1 + 6.2 is the minimum to unblock first-run onboarding; 2.1–2.3 is the minimum to take money.
3. **The payment path is the highest-risk port.** `SubscriptionPaymentController@initialize/callback` (~283 lines) encodes idempotency keys, a pending `Subscription`+`Payment`+`Transaction` triple, Paystack double-verification with exact kobo/currency matching, transactional activation of subscription/user/stores, coupon-use increment, activation emails, and a ledger posting in `LedgerPostingService::postSubscriptionPayment`. Nothing equivalent exists in the new API; this should be ported with tests, not re-invented.
4. **KYC has no reviewer in the new stack either.** The business submission side is missing (5.1–5.5) *and* the admin review side is missing: `storify-admin` has no KYC route/view/endpoint (`src/views/` has Businesses/Stores/Coupons/Users/Transactions/Dashboard only; the admin API exposes just a `kyc_pending` dashboard count). Building business KYC submission without admin approve/reject means submissions can never leave "under review". The store-activation gate (`StoreLifecycleController@activate` requires approved KYC) also has no new-stack home yet because no store lifecycle endpoints exist in the management API.
5. **Coupon redemption is the one place where half the plumbing exists.** Admin coupon CRUD is built (`Api\V1\Admin\CouponController`, admin SPA `CouponsView.vue`), but the business side (validate/apply/remove, session or state handoff to checkout, fully-covering instant activation) is entirely absent — coupons cannot currently be redeemed by a business at all.
6. **Early pass has an endpoint but no legacy UI.** `POST /management/subscription/check-early-pass` plus `ActivateSubscriptionWithEarlyPass` and a feature test exist, but no Blade/JS ever posts to it. Port the endpoint for parity; when building the SPA, add the code-entry affordance deliberately (e.g. alongside the coupon modal) rather than replicating the orphan.
7. **Things legacy never had — decide consciously:** no self-service cancel (2.6), no auto-renewal/auto-charge (renewal is "expire → gate → buy again"), no downloadable subscription receipt or invoice (only the 20-row billing-history table), no proration or billing-date preview on plan change, no "view my uploaded documents" screen, and no multi-application KYC history view (latest only, though rows accumulate). All are reasonable to leave out for parity but should be listed as product decisions rather than forgotten.
8. **Small legacy defects to fix while porting:** the coupon discount is invisible on the checkout page though applied at initialize; KYC resubmission never deletes old files (the deletion branch checks a fresh model); `ProcessTrialExpirations` ignores its own `trial_reminder_day5/6/7_sent_at` stamps and can re-send reminders on every scheduler tick within a window; the billing-history query is scoped to the *active* subscription so historical payments disappear after expiry.
9. **Effort concentration.** The recommended build order: 1.1 + 1.6 + 6.2 (unblock onboarding) → 2.1–2.3 (take payment) → 6.1/6.3 (re-instate the gate and banners) → 5.1–5.5 + admin review (KYC) → 1.2–1.5, 2.4, 3.x, 4.1, 6.4, 6.5. Roughly: planning/selection M×3 + S×3, payments L×2 + M×1, KYC M×5 + S×1, coupons M×2 + S×1, early pass M×1, surfaces M×4 + S×2 — a multi-week, multi-engineer domain.
