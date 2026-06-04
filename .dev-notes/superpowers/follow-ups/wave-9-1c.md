# Wave 9.1c — known follow-ups

Items audited or partially completed during the polish wave that were
intentionally deferred. Tracked here so they don't get lost.

## CSS-4 audit — `!important` declarations (deferred removal)

Inventoried 21 `!important` declarations across four CSS files. The plan
called for dropping the "now-redundant" subset after the **CSS-5 inline-style
sweep** lands. CSS-5 was on the Wave-9.1b shortlist but did not ship — see
`wave-9-1a.md` "S-6 CSP unsafe-inline removal". With ~348 `style="…"`
attributes still in views, most of the 21 hits remain load-bearing.

Concrete examples checked:

| Location | Verdict |
|----------|---------|
| `kanban.css:95,97` `.task-title.is-editable:hover/:focus` | **Keep.** Views still ship `<h1 class="task-title" style="border-bottom:1px dashed transparent;…">`; without `!important` the `:hover`/`:focus` colour swap loses to the inline `border-bottom` shorthand. |
| `kanban.css:307–316` `.kanban-card.is-dragging-*` | **Keep.** Fighting SortableJS's inline `style="transform/opacity/…"`. |
| `kanban.css:388, 768`, `utilities.css:113` `[hidden]` / `.hidden` | **Keep.** Standard hidden-utility pattern. |
| `cards-panels.css:539` `div[style*="margin-top"]` | **Keep.** Selector explicitly targets inline-styled siblings; rule is dead the day CSS-5 ships and can be deleted then. |
| `utilities.css:538–575` media-query overrides | **Keep.** Mobile responsive overrides of desktop-only properties. |
| `cards-panels.css:117` `.profile-avatar__pic { width/height/font-size … !important }` | **Keep.** Fighting baseline `.user-avatar` rule. Could be refactored once `.user-avatar--lg` exists. |

Action when CSS-5 ships: re-run `grep -n '!important' public/assets/css/*.css`
and drop any declaration whose neighbouring inline-style attribute is gone.
Estimated yield after CSS-5: ~6 of 21 declarations become removable.

## Items also carried forward from earlier waves

See `wave-9-1a.md` for:
- CSS-5 inline-style sweep (blocks CSP `style-src 'unsafe-inline'` removal — S-6).
- 17 silent-catch sites pending `withButtonBusy` migration.

## Stats at end of Wave 9.1c

(Filled in at wave close.)
