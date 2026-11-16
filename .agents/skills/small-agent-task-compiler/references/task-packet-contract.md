# Task-Packet Contract

Read this contract whenever creating or reviewing a small-agent task packet.
New packets use contract V2. Packets without `contract_version` remain readable
as legacy V1 evidence but must not be used as templates.

## Canonical V2 Format

```markdown
---
task_id: G02-M1-P1-T01
contract_version: 2
worker_profile: local-8b
packet_type: mechanical
execution_mode: generator
domain: documentation
risk: low
review: peer
status: ready
base_revision: 0123456789abcdef0123456789abcdef01234567
estimated_diff_lines: 40
state_transition_count: 0
test_expectation: none
scope_hash: <replace-with-validator-output>
max_context_files: 6
max_context_tokens: 12000
max_file_changes: 1
max_commands: 2
max_runtime_minutes: 15
---

# TASK G02-M1-P1-T01 — One mechanical outcome

## Intent

Give administrators one trusted view of the current access contract.

## Objective

Generate the exact approved catalog artifact.

## Depends On

- none

## Preconditions

- `FACT-1`: Exact approved input and output are fixed.

## Contract Cases

- `CATALOG-001` `[positive]` — GIVEN the approved literal input WHEN the generator runs THEN the output bytes equal the pinned SHA-256.

## Mechanical Input

- `GENERATOR-COMMAND`: `python3 exact/generator.py --output=relative/path.ext`
- `GENERATOR-CHECK`: `python3 exact/generator.py --check=relative/path.ext`
- `EXPECTED-SHA256`: `0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`

## Pinned Inputs

- `BASE-REVISION 0123456789abcdef0123456789abcdef01234567`
- `FILE-SHA256 exact/generator.py 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`
- `FILE-SHA256 relative/reference.ext 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`

## Writable Files

- `CREATE relative/path.ext` — Exact generated output.

## Read-Only Context

- `exact/generator.py::generator` — Preserve the deterministic generator.
- `relative/reference.ext::Reference` — Preserve the accepted contract.

## Forbidden Changes

- `FORBID-OUTSIDE-WRITABLE`: Do not edit files outside Writable Files.
- `FORBID-DEPENDENCIES`: Do not change dependency manifests or lockfiles.
- `FORBID-GIT`: Do not commit, push, rebase, reset, checkout or clean.
- `FORBID-DESTRUCTIVE`: Do not delete/rename files or run broad formatting.
- `FORBID-AUTHORITATIVE-CLAIM`: Keep generated evidence classified Candidate.

## Required Changes

1. `relative/path.ext::<new-file>` — Run the pinned generator exactly once. Cases: `CATALOG-001`.

## Acceptance Checks

1. `python3 exact/generator.py --check=relative/path.ext`
   Expected: Exit 0 and report the exact expected SHA-256. Cases: `CATALOG-001`.
2. `sha256sum relative/path.ext`
   Expected: Exit 0 with the exact expected SHA-256 and path. Cases: `CATALOG-001`.

## Residual Checks

- none

## Verification Scope

- `EXPECTED-MODIFIED relative/path.ext`
- `EXPECTED-UNCHANGED exact/generator.py`
- `EXPECTED-UNCHANGED relative/reference.ext`

## Stop Conditions

- `STOP-UNEXPECTED-DIFF`: Stop if a writable file contains unrelated user changes.
- `STOP-MISSING-ANCHOR`: Stop if a required path/symbol/pin differs.
- `STOP-OUT-OF-SCOPE`: Stop before changing anything outside this packet.
- `STOP-FAILED-CHECK`: Stop and report an unexpected check result.
- `STOP-RUNTIME-LIMIT`: Stop when max_runtime_minutes is reached.

## Report Contract

Status: DONE | BLOCKED | NEEDS_CONTEXT
Scope hash: <scope_hash from frontmatter>
Base revision: <base_revision from frontmatter>
Files changed: <exact paths or none>
Files unchanged: <verified EXPECTED-UNCHANGED paths>
Contract cases: <case IDs exercised>
Checks: <command => result>
Residual checks: <command => result or none>
Commands run: <count/max_commands>
Runtime minutes: <elapsed/max_runtime_minutes>
Review required: peer | strong | architect
Concerns: <none or one concrete concern>
```

The worker report is advisory. It never completes a task. The coordinator owns
the receipt described in
[execution-receipt-contract.md](execution-receipt-contract.md).

## Contract Cases

Every case has one stable ID, one or more case classes and literal `GIVEN`,
`WHEN`, `THEN` clauses. Supported classes are `positive`, `negative`, `absent`,
`retry`, `concurrency`, `toctou`, `backfill`, `rollback` and `lineage`. Cover
positive, negative, absent-state, retry, concurrency and time-of-check/time-of-use
behavior that matters to the outcome. Each case ID must occur in:

- At least one Required Change.
- At least one Acceptance or Residual Check.

For migrations, include existing-row backfill/null semantics and rollback. For
imports, cover the Cartesian state that changes behavior: source state/hash,
canonical presence, blocker present/removed, retry and lineage. For workflows,
cover submit and terminal revalidation when external state can change.

Every packet includes `positive`; high-risk and red-test packets include
`negative`; migrations include `backfill` and `rollback`; importer work includes
`retry` and `lineage`; packets with state transitions include `toctou`.

## Pinned Inputs

V2 packets pin:

- The exact 40-character `BASE-REVISION`, equal to frontmatter.
- SHA-256 of every existing `MODIFY` and Read-Only Context file.
- One `DEPENDENCY-RECEIPT <TASK-ID> <path> <sha256>` entry for every dependency.

Do not emit a downstream ready packet until dependency receipts and output files
exist. Return `DEPENDENCY_EVIDENCE_REQUIRED` instead.

## Test Evidence

Set `test_expectation` to:

- `red`: the focused test command must exit non-zero and state exact `Passed`,
  `Failed`, `Skipped: 0`, test name and `FAILURE-FINGERPRINT` tokens.
- `green`: focused test commands state exact positive `Passed`, `Failed: 0` and
  `Skipped: 0` counts.
- `none`: no test command is required; lint, syntax or deterministic artifact
  checks remain allowed.

An exit code with skipped tests is not acceptance evidence. High-risk test
packets receive exact expected literals from the coordinator; a weak worker
must not invent payroll totals, authorization outcomes, status values or
backfill rules.

## Residual Checks

Use `- none` only when the task removes, prohibits, renames or deprecates
nothing. Otherwise add an exact command and expected zero-match/absence result.
Residual commands count toward `max_commands`.

## Scope Hash

Generate the hash with:

```bash
python3 .agents/skills/small-agent-task-compiler/scripts/validate_task_packet.py \
  <packet.md> --print-scope-hash
```

V1 hashes Writable Files, Required Changes and Acceptance Checks. V2 hashes all
behavior-bearing sections from Objective through Stop Conditions, excluding
Intent, explanatory Read-Only prose and Report Contract. Any contract mutation
invalidates the hash.

## Paths, Anchors and Scope

- Use repository-relative `/` paths without `..`, globs or directories.
- `MODIFY` and Read-Only paths must exist and match their pins.
- `CREATE` paths must not exist and their parent must exist.
- Every path anchor must occur literally in the referenced file; new files use
  `<new-file>`.
- Required Change paths must be writable.
- `EXPECTED-MODIFIED` equals Writable Files exactly.
- Every Read-Only path appears as `EXPECTED-UNCHANGED`.
- Packet files stay immutable with `status: ready`; execution state belongs to
  receipts.

## Metadata and Limits

Supported workers, domains, packet types, modes, risks and review levels remain
those documented in `SKILL.md`. V2 adds:

- `contract_version: 2`
- `base_revision`: lowercase 40-character Git SHA.
- `estimated_diff_lines`: non-negative integer.
- `state_transition_count`: non-negative integer.
- `test_expectation`: `red`, `green` or `none`.

Structural ceilings remain four writable files, four Required Changes and three
total Acceptance plus Residual commands. Context limits remain 8 files/16,000
estimated tokens for `local-8b` and `qwen-8b`, or 12 files/64,000 tokens for
explicitly selected `luna` and `gemini`.

| Profile               | Contract cases | Existing target size | Estimated diff | State transitions |
| --------------------- | -------------: | -------------------: | -------------: | ----------------: |
| `local-8b`, `qwen-8b` |              2 |       600 lines/file |      150 lines |                 1 |
| `luna`, `gemini`      |              4 |     1,500 lines/file |      400 lines |                 3 |

Structural excess returns `SPLIT_REQUIRED`. High-risk semantic excess returns
`STRONG_MODEL_REQUIRED`. A coupled transaction/state machine is escalated rather
than split across its invariant boundary. Small-profile migration packets over
one contract case or 80 estimated diff lines also escalate.

## Risk and Review

Authentication, authorization, employee PII, private files, payroll/money,
migrations, configuration, CI and deployment are at least high risk. High-risk
packets require `STRONG_REVIEW_REQUIRED` and `review: strong|architect`.
Critical work and unresolved business-rule interpretation always return
`STRONG_MODEL_REQUIRED`.

## Worker Instruction

The worker reads the packet first, edits only Writable Files, runs only declared
checks and returns only the Report Contract. It stops on any Stop Condition. It
never edits packet status, invents expected business values, creates its own
receipt, commits, broadens scope or repairs unrelated state.
