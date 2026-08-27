# MCP bridge for Otack Manager — intent, not a build order

This document exists so an idea doesn't get lost between now and whenever
someone has time to build it. It describes what a native
[MCP](https://modelcontextprotocol.io/) (Model Context Protocol) server for
Otack Manager should probably look like, and — more importantly — the
condition under which it's safe to start building it.

**This is not a spec, not a task list, and not a commitment to a shape.**
Nothing here is load-bearing. If you're picking this up, re-derive the tool
list from how the CLI actually got used, not from the list below.

---

## 1. Why this exists at all

The REST API (`docs/API.md`, `docs/openapi.yaml`) is the one durable
contract — any client can be built against it, in any language, at any
time. An MCP server is not a new capability on top of that API; it's a
*packaging* of it, aimed at one specific consumer: an LLM agent (Claude
Code, or a general MCP-speaking client) that wants to drive Otack Manager
conversationally instead of shelling out to `curl`.

The REST API has 48 distinct (method, path) endpoints. A naive MCP bridge —
one tool per route — would hand the model 48 thin, REST-shaped tools and let
it improvise the workflow
(fetch task → fetch project → fetch comments → decide → patch → comment →
move). That's how you get an agent that technically has access to
everything and reliably does the wrong sequence of things with it.

The premise of this document is the opposite: **fewer, thicker,
task-shaped tools** that encode the workflow an agent actually needs
(`list_my_tasks`, not `GET /tasks` with five query parameters the model has
to remember to set correctly every time), so the model's job is picking the
right tool and filling in a couple of arguments, not reconstructing a REST
call from a written contract on every turn.

## 2. Relationship to the rest of the agent-bridge work

This server-side plan (`.superpowers/sdd/2026-08-27-agent-bridge-server/`)
ends with the REST API described in `docs/API.md`: cross-project task
listing, the `agent_state` execution-phase column, project↔repository
fields (`repo_url`, `default_branch`, `dev_branch`, `dev_url`,
`agent_instructions`), and the `from_form` safety flag. That work is
self-contained and ships independently of everything below.

A second, client-side plan (not yet written when this document was drafted)
covers the pieces that actually run the agent day to day: a `skill/` for
Claude Code, a small PHP CLI (`bin/otack`) that the skill shells out to for
JSON, and a lock-holding wrapper that runs one session at a time. That CLI
is the thing that will actually get used, iterated on, and rewritten a few
times as the real workflow (research → approval → implement → review →
rework) turns out to need slightly different primitives than anyone
guessed up front.

**The MCP server is downstream of that CLI, not a parallel effort.** See
§5.

## 3. Sketch: a dozen tools, not thirty-five

Rough shape, almost certainly wrong in the details, but useful as a
starting point for whoever revisits this:

| Tool | Roughly maps to | Why it's a tool and not left as raw REST |
|---|---|---|
| `whoami` | `GET /me` | Cheap sanity check the agent should be able to run unprompted before trusting its own token/config. |
| `list_projects` | `GET /projects` | Discovery — which projects can this token even see. |
| `list_my_tasks` | `GET /tasks?assignee_id=…&agent_state=…` | The "what should I work on" entry point. Bakes in the caller-defaults-to-self behavior and the `agent_state` filter so the model doesn't have to assemble query strings. |
| `get_task` | `GET /tasks/{id}` | Full detail — description, tags, links, `from_form`, current `agent_state`. |
| `get_project_context` | `GET /projects/{id}` | `repo_url`, branches, `dev_url`, `agent_instructions`, columns (with `is_done`/`is_backlog`) — everything the agent needs to decide where code goes and how to report back. |
| `set_agent_state` | `PATCH /tasks/{id}` (agent_state only) | Narrower than a general update tool on purpose — this is the one write an agent should be making constantly (researching → awaiting_approval → implementing → review → blocked), and it should be impossible to accidentally also blank out the title while doing it. |
| `update_task` | `PATCH /tasks/{id}` (other fields) | Everything else mutable — title, description, column, assignee, priority, due date, sub_status. Kept separate from `set_agent_state` for the same reason. |
| `move_task` | `POST /tasks/{id}/move` | Column + position is a distinct operation from a field patch in the underlying data model (position math, `sub_status` reset side effects) — worth keeping distinct here too. |
| `add_comment` | `POST /comments` | Post progress notes, questions, findings. |
| `reply_comment` | `POST /comments` (with `parent_id`) | Threaded replies — separated from `add_comment` because the agent needs a different mental model ("I'm answering X" vs "I'm starting a new thread") and different required arguments. |
| `new_comments` | `GET /tasks/{id}/comments`, filtered | "What's been said since my last comment" — the primitive the design spec's §4.1 watermark logic depends on. Not a raw passthrough: the filtering-since-last-own-comment logic belongs in the tool, not repeated in every prompt. |

That's eleven. The "roughly a dozen" framing in earlier notes leaves room
for one more if real usage exposes a gap (a dedicated `list_columns` or
`link_task` candidate, most likely) — the point isn't to hit an exact
number, it's to stay in "curated handful" territory instead of drifting
back to one tool per route.

## 4. Transport & configuration

- **Transport:** stdio. This is a local tool a coding agent launches as a
  subprocess, not a network service — no HTTP/SSE transport needed for the
  MCP layer itself (it calls the REST API over HTTP internally, same as the
  CLI does).
- **Configuration:** two environment variables, mirroring the CLI/skill
  convention already used elsewhere in this repo's docs:
  - `OTACK_API_URL` — base URL of the target instance's `/api/v1`.
  - `OTACK_API_TOKEN` — a Bearer token from `/profile/tokens` (see
    `docs/API.md` §2.2–2.3 for token provisioning and storage guidance,
    which applies unchanged here).
- No new auth model, no scopes beyond what the REST API already enforces
  (`docs/API.md` §8.2 — v1 has no scopes; the token's owning user's role and
  project memberships are the only access boundary, and that's still true
  through this bridge).

## 5. The gate: build this only after the CLI has been used for real

**Do not start this before the skill-plus-CLI client (§2 above) has been
exercised on real tasks.** The tool table in §3 is a guess made by someone
who has read the REST API but has not yet watched an agent actually work a
task end to end through research → approval → implementation → review.

Guesses made at this distance from real usage are reliably wrong in ways
that only show up once something has to use them under real conditions:
argument shapes that turn out to need one more field, a "thick" tool that
turns out to hide something the model needed to see, a workflow step that
turns out to need two tool calls where one was assumed. The CLI is cheap to
reshape — it's a PHP script, not a published interface anything external
depends on. An MCP tool surface is the thing other integrations (and other
people's prompts) start depending on the moment it ships, so it's worth
paying the cost of getting it from observed usage rather than from a table
written before anyone had run the thing once.

Concretely: this document should be revisited once the CLI + skill has run
against a real backlog for a while and someone can say, with the scars to
prove it, which of the eleven tools above survived unchanged, which need
splitting or merging, and what got missed.
