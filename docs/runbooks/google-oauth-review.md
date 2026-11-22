# Google OAuth launch checklist

Before enabling Google integrations for real users (spec §deployment: "Google OAuth review checklist").

## Google Cloud project
- [ ] Dedicated production project; billing account and owners documented (≥ 2 owners).
- [ ] APIs enabled: Google Sheets API, Google Drive API. Quotas reviewed (Sheets: per-minute read/write per project and per user).
- [ ] OAuth client type **Web application**; authorized JavaScript origin = studio origin; redirect URI = `<studio origin>/api/v1/integrations/google/callback` (shown in Admin → ตั้งค่าระบบ).
- [ ] Client secret stored only in the platform settings (encrypted) or deployment secrets (`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`); never in git, tickets or chat. Rotate by creating a new secret, saving it, then deleting the old one in Google.

## Consent screen and verification
- [ ] App name, logo, support e-mail, home page, privacy policy and terms URLs on the verified studio domain.
- [ ] Scopes declared exactly: `openid`, `email`, `https://www.googleapis.com/auth/spreadsheets` (sensitive), `https://www.googleapis.com/auth/drive.file` (non-sensitive).
- [ ] Sensitive-scope verification submitted (justification: users link specific spreadsheets by URL to sync app records; demo video of the J4 flow). Until verified, the app is limited to test users / shows the unverified warning.
- [ ] Privacy policy explains: what is read/written, that tokens are encrypted, how to disconnect (Integrations page and myaccount.google.com/permissions), retention and deletion.

## Platform configuration
- [ ] `GOOGLE_FAKE_BASE` unset (the app refuses it in production).
- [ ] Queue workers consume `google` and `webhooks`; the scheduler runs (`studio:sheets:schedule`).
- [ ] `STUDIO_EGRESS_ALLOW_PRIVATE=false`.
- [ ] Alerting on `integration_sync_jobs.status = dead` and on Google 429 rates.

## Verification in staging (real Google)
- [ ] J4: connect → link sheet → preview → dry run → import → export → two-way conflict → disconnect; confirm the grant disappears at myaccount.google.com/permissions.
- [ ] J5: connect Drive → create folder → upload → download → delete in Drive → app shows "missing" → revoke in Google → app shows "access lost".
- [ ] Token refresh after > 1 hour; revoked refresh token marks the connection revoked.
