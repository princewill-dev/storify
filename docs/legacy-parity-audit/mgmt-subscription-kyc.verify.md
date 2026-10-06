# Verification pass — mgmt-subscription-kyc (audience: business)

**Verifier method:** independently re-read the domain end to end — `routes/v1/management.php` (auth/staff group 70–91, subscription + plans block 78–89, gated group 93–106) and the full `routes/v1/admin_dashboard.php` subscription/KYC block (104–117, 225–237); the five in-scope controllers in full (`Management\{SubscriptionPlan,SubscriptionPayment,SubscriptionCoupon,EarlyPass,Kyc}Controller`), `Admin\BusinessKycApplicationController`, `Admin\SubscriptionController` (34 lines, index-only), `Admin\AdminEarlyPassController`; `App\Actions\Subscriptions\{ActivateSubscriptionWithCoupon,ActivateSubscriptionWithEarlyPass,CouponActivationResult}`, `App\Services\{SubscriptionTrialSettings,StoreActivationNotifier,PaystackService}`, `App\Jobs\ProcessTrialExpirations`, `App\Http\Middleware\{CheckSubscription,RedirectIfOnboardingIncomplete}`, `App\Http\Requests\Management\SubmitKycRequest`, `App\Enums\KycStatus`, `App\Models\{Subscription,SubscriptionPlan,Payment,Coupon,EarlyPass,KycApplication,Setting,Business}` and the relevant `User` helper methods; every mailbox in the domain (`AdminKycSubmitted`, `BusinessKycSubmitted`, `KycApproved`, `CouponExhaustedMail`, `StoreActivated`, `AdminStoreCreated`, `TrialExpiredMail`, `TrialExpiryReminderMail`); a full recursive listing of `resources/views/management/**` plus every view file in scope read in full (`subscription/{plan,payment,_plan-card}.blade.php`, `kyc.blade.php`, `auth/business/{plans,_plan-card}.blade.php`) and every other view that references a domain route (dashboard banners, sidebar, `stores/success`, `stores/delivery-routes`, trial emails); the legacy feature tests (`OnboardingFlowTest`, `SubscriptionCouponTest`, `EarlyPassTest`). New stack: `routes/api/v1/{management,admin,auth,home}.php`, the whole `Api/V1/**` controller tree, `storify-management` (`router/index.ts`, `stores/auth.ts`, `api/endpoints.ts`, `layouts/AppLayout.vue`, `views/TransactionsView.vue`, all views) and `storify-admin` (`router`, views, endpoints).

**Coverage verdict:** the inventory is **complete**. Every in-scope route, controller method, view file, mailable, job, middleware, action and model maps to a feature entry; every one of the audit's "missing" claims was spot-checked against the new stack and **none hides an existing endpoint or screen** (the only two new-stack surfaces in the area — the auth `next`/`subscription` payload and the generic transactions list — are exactly the two the audit calls out). `missed_features` is therefore empty. What follows is ten concrete corrections, five of which change what a porter would build, plus smaller notes.

---

## Missed features

None. Specifics checked and confirmed covered: the three subscription view files + the one KYC view + the two onboarding plan views are enumerated file-by-file; `resources/views/management/kyc/` is an empty directory; no other management view references a subscription/KYC route (the two stores-owned exceptions, `stores/success.blade.php` and `stores/delivery-routes.blade.php`, are already inventoried in `mgmt-stores.md` §5.1/§6.1); all 13 in-scope routes appear; the early-pass endpoint genuinely has no Blade/JS caller (re-confirmed by grep over `resources/` and `public/`); `store_creation_limit` is a dead setting (never read outside admin settings) and is correctly omitted.

---

## Corrections

### 1. §2.6 — "Cancelling a subscription is, today, an admin/support action" is **false**

`Admin\SubscriptionController` is index-only (status/q filter, read-only list); no admin route or controller ever mutates a subscription, and no console command does either. A repo-wide grep for `Subscription::STATUS_CANCELLED|STATUS_SUSPENDED|STATUS_EXPIRED` returns exactly one write — the `initialize()` failure cleanup (`SubscriptionPaymentController.php:178`). So legacy has **no cancellation, suspension or expiry path at all, for anyone**: rows sit at `status = 'active'` forever after expiry and every gate is time-based (`Subscription::isActive()`, `scopeActive` on `expires_at`). Correct the recommendation: parity means "no cancel affordance anywhere"; any cancel/schedule-cancel flow is net-new product work, not a restored admin capability. (Also means `status = expired/suspended` never appear in data — don't build UI that branches on them.)

- **Field corrected:** `what_user_can_do` / `missing` (2.6).

### 2. §6.4 — the sidebar KYC badge has a **fourth** state; the audit says "no badge when absent"

`sidebar.blade.php:314–330` renders a pill in all four cases: submitted → amber **"Pending"**, approved → green **"Verified"**, rejected → red **"Rejected"**, and — when there is no application at all — an `@else` slate **"Required"** pill. The audit's "no badge when absent" would ship a nav item with no status affordance for the most common first-run state. Also: these are text pills, not "status dots", and the nav entry is wrapped in `@if(auth()->user()?->isBusinessOwner())` while the Subscription entry sits inside the Finance block guarded by `transactions view || invoices view || settings payment` — port those gates deliberately.

- **Field corrected:** `key_ui` (6.4).

### 3. §3.3 — plans-page instant activation only fires for a **plan-scoped** coupon

`SubscriptionCouponController@validateCoupon` gates the auto-activation on `$plan = $coupon->subscriptionPlan; if ($plan && $this->activator->fullyCovers($coupon, $plan))`. A generic coupon (`subscription_plan_id = null`) worth 100%/≥ amount does **not** activate on the plans page — it is stored in the session like any other coupon and only auto-activates later at `process-payment`, where `initialize()` computes `max(0, amount - discount)` and routes to the activator. Because the plans-page modal also never sends `plan_id` (§4 below), the plan-scoped branch is reachable only via the coupon's own row. The audit's "Enter a coupon worth ≥ 100% … the subscription is activated immediately" describes the checkout path, not the plans path.

- **Field corrected:** `what_user_can_do` (3.3).

### 4. §3.1/§3.2 — the UI never sends `plan_id`, and checkout **silently drops** a non-applicable coupon

The Alpine `applyCoupon()` fetch body is `JSON.stringify({ code })` — no `plan_id` — so the per-plan applicability check the audit describes ("when a `plan_id` is supplied") never runs in the legacy UI. Consequence to port deliberately: a coupon scoped to plan A can be applied while the user intends plan B; at `initialize()` the guard `$coupon->isApplicableTo($plan->id)` fails and the code does `$coupon = null; session()->forget('applied_coupon_code')` with **no user-facing message** — the shopper is charged full price with the chip still shown on the plans page. Also note `initialize()` re-validates with `isValid()`, so a coupon exhausted between apply and checkout vanishes the same silent way.

- **Field corrected:** `what_user_can_do` / `missing` (3.2); add the silent-drop behaviour to 3.1's session-handoff note.

### 5. §1.2 — the "raw status (`ucfirst`)" badge is **dead code**

`SubscriptionPlanController@index` passes `$user->business?->activeSubscription()->first()`, and `Business::activeSubscription()` is `hasOne(...)->where('status','active')->where('expires_at','>',now())->latestOfMany()`. Inside the view, `@if($subscription->isActive())` is therefore always true when `$subscription` is non-null, so the `@else` badge can never render. A business whose subscription row is pending/expired/cancelled sees the **trial-or-no-plan layout**, not a status badge. Don't port a "subscription status" state the page cannot reach.

- **Field corrected:** `what_user_can_do` (1.2).

### 6. §1.4 — the plan-grid CTA disappears as soon as `selected_plan_id` is set

`management/subscription/_plan-card.blade.php` renders the select form only under `@elseif(!$subscription && !$user->selected_plan_id)`, and the switch button only under `@if(!$isCurrent && $subscription)`. Since `select()` always writes `selected_plan_id`, **every trialing user** (the main audience of this page) sees cards with no button at all — browse-only, even for other plans. The only way onward is the trial card's "Subscribe Now". The audit's "a 'Get Started'/'Select Plan' form posting select-plan (unsubscribed users)" is true only before the first selection.

- **Field corrected:** `what_user_can_do` (1.4).

### 7. §5.3 — both document uploads are **optional**, not required

`SubmitKycRequest` has `identification_document => ['nullable','file','mimes:jpg,jpeg,png,pdf','max:5120']` and `selfie_image => ['nullable','image','mimes:jpg,jpeg,png','max:4096']`, and the modal's two file inputs carry no `required` attribute (only the text fields and the document-type select do). A KYC application with **no files at all** is accepted and becomes "Under Review". The audit's 5.3 wording ("upload an Identification Document … and a Selfie Photo") reads as mandatory — an SPA that enforces them would reject submissions legacy accepted. It also means only §5.2's text fields need the "mirrors client-side `required`" treatment.

- **Field corrected:** `what_user_can_do` (5.3).

### 8. §5.6 — rejection sends **no email**; the admin flash claiming "owner notified" is a legacy lie

`BusinessKycApplicationController@reject` updates the application + owner status and redirects with "KYC application rejected and the business owner notified." — but nothing queues a message (the only rejection-adjacent mailable is `KycApproved`, sent on approve; there is no `KycRejected`). The sole business-visible signal is the KYC page on the owner's next visit (status + `review_notes`), plus the sidebar badge. The audit's 5.6 implies a notification parity that does not exist; decide explicitly whether the port adds a rejection email (recommended) rather than assuming it.

- **Field corrected:** `side_effects` / `what_user_can_do` (5.6).

### 9. §6.1 — the exempt lists are narrower than "plans/*, profile, KYC"

`CheckSubscription`'s exempt list (18 names) contains `plans.index`, `plans.checkout`, `plans.validate-coupon` but **not** `plans.remove-coupon`; and `profile.index`, `profile.password` but **not** `profile.update`. Real consequence: for a gated (no-subscription, no-trial) owner the coupon-remove POST from the otherwise-exempt plans page is 302-redirected back to `/management/plans` and the session coupon is **never cleared** — the chip stays applied. `RedirectIfOnboardingIncomplete`'s business-setup exempt list is narrower still: `setup*`, `plans.index/checkout/validate-coupon`, `subscription.plan`, `subscription.callback`, `check-early-pass`, auth routes — `subscription.select-plan`, `subscription.payment`, `subscription.process-payment` and `kyc.*` are **not** exempt, so a user without a business is bounced to `/management/setup` from the pay page (the "plans → pay" order only works once a business exists). Port the exact lists, and fix the remove-coupon hole rather than copying it.

- **Field corrected:** `what_user_can_do` / `legacy_route` (6.1).

### 10. §2.4 — `api_status` and `spa_status` should be **`partial`**, not `missing`

The audit's own prose concedes the point ("Subscription payments *do* create `Transaction` rows that surface in the generic `GET /api/v1/management/transactions` list … `src/views/TransactionsView.vue` shows the generic list only"), but the status labels and summary table still read `missing/missing` ("0 exists / 1 partial / 29 missing"). Subscription payments are written with `reference = payment->reference`, `amount`, `currency` and a pending/confirmed status, so the new SPA already renders those rows with the **same reference, amount, status and date** the legacy Billing History table shows (order/invoice columns render "—"; the row runs pending → confirmed, or → cancelled on failure, rather than the legacy paid/pending/failed pills — though `TransactionStatus::PAID` also exists). Under the audit's vocabulary ("endpoint or screen present but missing actions/fields"), the genuinely absent parts are subscription scoping, the plan-name/`payment_type` description column and the 20-row cap — so this is `partial` on both axes. Knock-ons: the summary table becomes 2 partial, and "the single `partial` is the auth next-step signal" is no longer true.

- **Field corrected:** `api_status`, `spa_status` (2.4) + summary table.

---

## Smaller notes (no status change demanded)

- §2.1 "Paystack public key delivery" is not a real requirement: `show()` passes `paystackPublicKey` to `payment.blade.php`, but the view never uses it (no Paystack JS — the page is a plain server-side form POST). Don't port the dead variable; the public key only matters if the new checkout is client-side.
- Billing-history reuse gotcha for §2.4: the legacy transaction **detail** page 403s for a subscription payment (`TransactionController@userOwnsTransaction` falls through to `$user->business_id === null` when there is no order/invoice), so in legacy the owner can see the row in the list but never open it. The new `Api\V1\Management\TransactionController@authorizeTransaction` compares `business_id` and does not have this bug — but the new SPA's detail modal offers Confirm/Reject/Refund on those rows and `confirm()` throws "Transaction has no associated store."; gate those actions by `order`/`invoice` presence when wiring subscription references into the list.
- §1.2 "Billing cycle" cell prints `{{ $subscription->subscriptionPlan->interval }}ly` → literal "monthlyly"/"yearlyly" in legacy. Cosmetic; do not port.
- Exec-summary count "The SPA router has 21 routes": `src/router/index.ts` has 25 `path:` entries (23 named). Cosmetic; the substance ("no `/plans`, `/subscription` or `/kyc`") is correct.
- §6.5 is accurate as written, but for porting: `ProcessTrialExpirations` fetches `$trialDays` and never uses it, and `sendReminder` is keyed on `now()->diffInDays()` so the "day 1–3" window is really "any tick when ≤3 whole days remain" — the reminder-per-day cadence is a scheduling artifact, not a state machine. The `trial_reminder_day5/6/7_sent_at` columns are confirmed dead.
