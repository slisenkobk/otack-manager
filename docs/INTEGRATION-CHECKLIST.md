# Otack API Integration Checklist

One-page sanity check before you ship an integration against `/api/v1/`. Pair
it with [`docs/API.md`](API.md) for the full reference.

## Before you start
- [ ] Confirmed your instance URL: `https://_______/api/v1`
- [ ] Confirmed who the integration will run as (recommended: a dedicated
      `employee` service-account user, NOT a personal admin)
- [ ] Service-account user has been added as a member of the projects the
      integration touches

## Get the token
- [ ] Logged in as the service-account user
- [ ] Created a token at `/profile/tokens` with a descriptive label
- [ ] Copied the `otk_…` value at the reveal screen (it cannot be retrieved
      later — only the hash is stored server-side)
- [ ] Stored token in an environment variable / secrets manager (NOT in git)

## Wire the client
- [ ] All requests send `Authorization: Bearer otk_…`
- [ ] All JSON write requests send `Content-Type: application/json`
- [ ] Multipart uploads (`POST /attachments` only) send
      `Content-Type: multipart/form-data`
- [ ] HTTPS in production (mandatory)
- [ ] Smoke test passed: `GET /me` returns `200` + identity JSON

## Hardening
- [ ] Client retries on `429` using the `Retry-After` header value
- [ ] Client maps the `error` code field to logic (NOT `message`)
- [ ] Logs redact the `Authorization` header
- [ ] You have a documented runbook for token compromise (revoke + reissue)

## Operations
- [ ] You paginate using cursors (`next_cursor` / `after`), not full lists
- [ ] You poll on intervals that stay under 60 req/min/token
- [ ] You have an alert for repeated 4xx/5xx from the API
- [ ] You have a documented integration owner (the person who rotates the
      token annually and on team changes)
