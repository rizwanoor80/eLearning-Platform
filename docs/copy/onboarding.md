# Onboarding copy (R186) — for the UI developer's review

Every string below is what ships in cycle 14 / 14b. Tutor strings live in
`resources/js/pages/tutor/Onboarding.vue`, `resources/js/pages/tutor/Dashboard.vue`,
`resources/js/components/tutor/OnboardingChecklist.vue` and `FieldTag.vue`, and the group
titles/hints in `app/Services/Tutors/TutorOnboardingChecklist.php`. Parent strings live in
`resources/js/pages/GetStarted.vue`, `resources/js/pages/Dashboard.vue` and
`app/Services/Parents/ParentGetStarted.php`. Please mark up wording changes here; the developer
applies them in one pass.

## Field tags

Each onboarding field carries `(Required)` or `(Optional)` after its label, with an optional note
after a dash. One component (`FieldTag`) draws all of them.

| Section | Field | Tag |
|---|---|---|
| Contact details | Country | Required |
| | Phone number | Optional |
| | Timezone | Required |
| | Display name | Optional (hint: shown to parents instead of your first name; 2–30 characters; no email, phone or link) |
| Permit | Permit number | Optional — "give both the number and the expiry date, or neither" |
| | Permit expiry date | Optional — "needed with the permit number" |
| CV, LinkedIn & documents | LinkedIn profile URL | Required — "a CV or LinkedIn, one is enough" |
| | CV | Required — "or a LinkedIn profile" |
| | Any other document type | Optional, or Required where an admin has marked the type required |
| Bank details | Bank name, Account holder name, IBAN | Required — "if you add bank details" |
| | SWIFT/BIC | Optional |
| Subjects | Curriculum, Subject, From/To year group | Required (at least one subject to appear in search) |
| Hourly rate | Hourly rate (AED) | Required — "to appear in search" |
| Bio and headline | Headline, Bio, Intro video URL, Booking notice | Optional |
| Availability | Weekday, Start, End | Required (at least one weekly window to appear in search) |
| Tutor agreement | Acceptance | Required |

## Tutor checklist

Heading (full page): **Your profile checklist** · (dashboard): **Finish setting up your profile**, with "N to go".

| Group | Badge | Hint | Items |
|---|---|---|---|
| To submit for review | Required | Needed before you can send your profile to our team. | Contact details · CV or LinkedIn profile · Tutor agreement |
| To appear in search | Required | Needed before parents can find and book you. | Subjects you teach · Hourly rate · Weekly availability · *Required documents accepted* (only if an admin has marked a document type required) · *Work permit renewed* (only if the permit has lapsed) |
| Optional | Optional | Not needed to be reviewed or listed, but they help parents choose you. | Work permit · Other documents · Bank details · Bio and headline · Intro video · Booking notice (lead time) |

A group with every item done shows **All done**. The dashboard shows only the two required groups
and only what is still to do; it disappears once both are complete (and never shows while the
profile is waiting for review, or for a rejected or suspended tutor).

Where the onboarding page is locked (waiting for review, rejected, suspended; for an approved
tutor everything except availability) a checklist line is plain text, not a link, so it never
points at a page the tutor cannot open.

## Hourly rate step

- Subjects chosen: "The highest level you teach is *{Lower secondary | Exam years 1 | Exam years 2}*, so your rate must be between *X* and *Y* AED per hour."
- No subjects yet: "Your rate is set against the price band for the highest level you teach, so choose your subjects first. **Go to Subjects**" (the Save button is disabled).

## Parent "Get started" (after email verification, and linked from the dashboard banner)

- Heading: **Welcome to trusTutor** — "Your email is verified. Here is what you need to book your first lesson, and what you can leave for later."
- Checklist heading: **Your checklist**

| Group | Badge | Hint | Items |
|---|---|---|---|
| To book a lesson | Required | Lessons are booked for a learner, so add one first. A learner never needs a login of their own. | Add a learner |
| At your first booking | Required | You pay by card when you book. Cards are in test mode for now, so no real money moves. | Add a payment card (shown as plain text where test cards are unavailable) |
| Optional | Optional | Not needed to book, but they help tutors prepare. | School and notes for tutors · Add another learner |

- Buttons: **Add a learner** (until one exists), then **Find a tutor**; always **Go to my dashboard**.
- Parent dashboard banner (until a learner exists): **Add your first learner to start booking** — "Lessons are booked for a learner, so you need one before you can book a tutor." — link **See what to do first**.

## Notes for review

- "Required" on the bank fields means "needed if you add bank details"; bank details as a whole stay optional.
- The card line says "test mode" because CP5 (live payments) has not shipped; change the hint then.
- Error text for a locked or stale page (shown as a toast) is listed in `docs/reports/14b.md` §2.
