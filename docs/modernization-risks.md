# SKA Modernization Risk Register

Date: 2026-02-09

Scale:
- Likelihood: Low / Medium / High
- Impact: Low / Medium / High / Critical

| ID | Risk | Likelihood | Impact | Why it matters | Mitigation | Detection | Rollback |
|---|---|---|---|---|---|---|---|
| R-01 | LDAP auth regression | Medium | Critical | Blocks all user access and admin actions | Add LDAP compatibility tests before auth refactor; keep dual-path auth adapter during transition | Failed login smoke test; increase in auth failures | Revert auth adapter PR, restore legacy auth path |
| R-02 | Sync output behavior drift | Medium | Critical | Wrong keys in `/var/local/keys-sync/` can deny or overgrant SSH access | Snapshot-based sync fixture tests; compare generated keyfile content before/after | Sync preview diff mismatch | Revert sync module changes, keep legacy writer |
| R-03 | Host verification weakening in sync chain | Medium | Critical | Could sync keys to unintended host | Preserve host-key checks; replace insecure jumphost options with explicit trust pinning plan | Host key collision/verification alarms | Re-enable prior verification behavior and block sync on uncertainty |
| R-04 | Audit/event semantics break | Medium | High | Compliance/forensics gap on key/access changes | Contract tests for event creation + fields + level | Event count/field mismatch in smoke checks | Revert event-affected model/service changes |
| R-05 | Access rule logic regression | Medium | High | Wrong entitlements or locked-out teams | Add scenario tests for user/group/server-account access cases before refactor | Access add/remove smoke failures | Revert access domain change; restore prior SQL flow |
| R-06 | Schema migration incompatibility | Low | High | Upgrade failures or data corruption | Keep migrations additive, reversible, and tested on snapshot DB | Migration apply failure or data diff anomalies | Roll back app image + DB restore from pre-phase backup |
| R-07 | Frontend workflow break during Bootstrap migration | High | Medium | UI may block key/admin operations | Migrate page-by-page behind compatibility CSS, keep route/POST contracts stable | Manual smoke on login/key/access/server pages | Revert page-specific template/CSS commit |
| R-08 | Session/auth state inconsistencies | Medium | High | Random logout/redirect loops or bypass edge cases | Centralize session policy in one auth boundary; add regression checks for login/logout flows | Login redirect loop or unauthorized access | Revert auth/session refactor and keep prior handler |
| R-09 | Hidden coupling to globals causes side effects | High | Medium | Refactors can break unrelated pages/scripts | Introduce adapters incrementally; avoid mass global removal in one PR | Unrelated smoke failures after targeted change | Revert targeted PR; split into smaller shims |
| R-10 | Secrets accidentally committed during modernization | Medium | Critical | Credential/key compromise | Enforce `.gitignore`, add secret scan in CI, keep sample configs only | Secret scanner alert / code review findings | Immediate key rotation + purge from history + incident process |
| R-11 | CI gate noise slows delivery | Medium | Medium | Excess false positives reduce trust in checks | Start with focused baseline rules and raise strictness gradually | Frequent flaky failures | Temporarily scope failing gate, then fix root cause |
| R-12 | Insufficient automated coverage before deep refactor | High | High | High regression probability | Write compatibility and smoke tests first in high-risk areas | Manual test burden grows; regressions discovered late | Pause refactor stream, add tests, then resume |

## Top Risks For Next 3 Phases
- `R-01`, `R-02`, `R-04`, `R-08`, `R-12`

## Risk Review Cadence
- Reassess register at start and end of every phase.
- Any new Critical risk blocks phase close until mitigation/rollback is documented.
