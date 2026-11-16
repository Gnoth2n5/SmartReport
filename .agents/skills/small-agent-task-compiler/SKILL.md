---
name: small-agent-task-compiler
description: Compile approved implementation work into invariant-traceable Markdown packets for small/local coding agents, and validate coordinator-owned execution receipts before integration. Use for detailed worker tasks, safe weak-model handoffs, worker-result verification or phase evidence closure. Do not use for roadmap or milestone design, or to dispatch workers.
---

# Small-Agent Task Compiler

Produce task packets that a small coding model can execute without making
product, architecture, security or scope decisions. V2 separates two gates:

1. Compile an immutable packet whose business invariants, inputs and expected
   evidence validate before dispatch.
2. Validate a coordinator-owned execution receipt against the actual diff,
   command evidence and reviewer decision before integration.

This skill does not dispatch workers or authorize implementation.

## Required Inputs

Before compiling packets, obtain:

- An approved implementation source: active phase, accepted issue or explicit
  implementation specification.
- Repository instructions and the current Goal/Plan/phase status.
- The exact affected implementation, direct callers and smallest relevant
  checks.
- All decisions that would otherwise require the worker to choose an approach.
- Stable contract-case IDs for every positive, negative, absent-state, retry,
  concurrency and time-of-check/time-of-use outcome in scope.
- Accepted dependency receipts for every upstream packet whose output the new
  packet consumes.

If the source is only a roadmap, milestone or draft phase, return
`DESIGN_REQUIRED`. Do not convert unapproved scope into ready work.

If an upstream output does not yet exist, return `DEPENDENCY_EVIDENCE_REQUIRED`.
Do not compile a downstream packet against an imagined future file or an
unpinned dependency result.

## Worker Profiles

Default to `local-8b`; also accept `qwen-8b`. Use `luna` or `gemini` only when
the user explicitly requests a larger-context worker or a required existing
file cannot fit a small-worker packet.

For `local-8b` and `qwen-8b`, one packet may contain at most:

- One observable outcome.
- One declared domain and one implementation layer.
- Four exact writable files.
- Four ordered change steps.
- Three verification commands.
- Eight context files and an estimated 16,000 context tokens.
- Twenty minutes of execution time.

For `luna` and `gemini`, retain the same one-outcome, one-domain, four-file,
four-step, three-command and twenty-minute limits, but allow up to twelve
context files and 64,000 estimated context tokens. A larger window permits
necessary context; it does not permit more work or a longer execution window.

Split again when any limit is exceeded. A later packet may depend on an earlier
one; never hide multiple outcomes inside one packet.

File count and token count are not semantic complexity measures. For V2 direct
packets, also apply the contract-case, target-file-size, estimated-diff and
state-transition ceilings enforced by the validator. A coupled transaction or
state machine that cannot be split without weakening its invariant returns
`STRONG_MODEL_REQUIRED`, not several unsafe small packets.

## Decision Gate

Resolve every choice before emitting a `ready` packet. Reject wording such as
“as needed”, “if appropriate”, “use best judgment”, “consider”, “etc.” or
“follow the existing pattern” without naming the exact reference.

Return one of these instead of a ready packet when necessary:

- `DESIGN_REQUIRED`: behavior, ownership, interface or acceptance is not fixed.
- `SPLIT_REQUIRED`: the work exceeds the selected worker limits.
- `STRONG_MODEL_REQUIRED`: correctness depends on repository-wide reasoning or
  judgment that cannot be removed from the packet.

## Tool-First Gate

Before delegating, ask whether a deterministic program can produce the exact
output from structured repository data. If yes, use or create that extractor;
do not ask a weak model to transcribe, gather, infer, derive or enumerate the
same data.

A `mechanical` packet is ready only when its input is one of:

- `generator`: one exact generator command, one exact check command, one output
  path and one expected SHA-256.
- `literal`: one immutable coordinator-prepared artifact with an exact
  SHA-256.

If neither exists, return `STRONG_MODEL_REQUIRED` or compile the deterministic
tool first. A reviewer comparing every generated row is not a substitute for
mechanical input.

Authentication, authorization, employee PII, private files, payroll, money,
database migrations, dependencies, CI and deployment are high-risk. Send them
to a stronger model unless the approved source makes the change transcription-
level, the packet names exact behavior and negative checks, and a stronger
reviewer is assigned.

High-risk test packets must receive exact expected literals from the
coordinator. A weak worker must not invent payroll totals, status values,
security outcomes or migration backfills.

## Compile Workflow

1. Read the approved source and repository instructions.
2. Inspect the exact affected symbols, direct callers and checks.
3. List unresolved decisions. Resolve them in the coordinator or return a
   decision-gate status; never pass them to the worker.
4. Build a contract-case matrix and map every case to one change step plus one
   acceptance or residual check.
5. Split or escalate until the packet fits both the structural and semantic
   worker limits.
6. Pin the base revision, every existing writable/read-only file and every
   accepted dependency receipt.
7. Write each packet using
   [the task-packet contract](references/task-packet-contract.md).
8. Generate the canonical scope hash, insert it in frontmatter, then validate:

   ```bash
   python3 .agents/skills/small-agent-task-compiler/scripts/validate_task_packet.py \
     <packet.md> --print-scope-hash

   python3 .agents/skills/small-agent-task-compiler/scripts/validate_task_packet.py \
     <packet.md> --repo-root .
   ```

9. Report packet paths, dependency order and any held work. Do not dispatch
   workers unless the user separately requests execution.

After a worker returns, the coordinator—not the worker—creates and validates an
execution receipt using
[the execution-receipt contract](references/execution-receipt-contract.md):

```bash
python3 .agents/skills/small-agent-task-compiler/scripts/validate_task_result.py \
  <packet.md> <receipt.json> --repo-root . --run-checks
```

For phase closure, validate the receipt manifest with the same script's
`--phase-manifest` mode. Never infer task or phase completion from packet
frontmatter, a worker's prose report, an exit code with skipped tests, or a
green aggregate suite alone.

After changing this skill or validator, run its retained regression check:

```bash
python3 .agents/skills/small-agent-task-compiler/scripts/test_validate_task_packet.py
python3 .agents/skills/small-agent-task-compiler/scripts/test_validate_task_result.py
```

## Safety Rules

- Writable scope is an allowlist. Every other file is read-only.
- Use exact repository-relative paths and `path::symbol` anchors. Never use
  globs, directory-wide scope or “nearby files”.
- State exact edits in execution order. The worker must not discover or select
  an implementation pattern.
- Declare exactly one `domain`, one `packet_type`, one risk level and one review
  requirement. Cross-domain packets must split or escalate.
- Declare `execution_mode` and include the Mechanical Input section. Mechanical
  packets cannot use unconstrained source transcription.
- Treat `max_context_tokens` as a conservative size estimate, not an exact
  tokenizer count. The validator counts unique existing context files and uses
  `ceil(characters / 4)`.
- `scope_hash` must match the canonical Writable Files, Required Changes and
  Acceptance Checks content for legacy packets. V2 hashes every behavior-bearing
  section, including contract cases, pins, forbidden/residual checks and stop
  conditions. Revalidate after any change.
- V2 packet files remain immutable with `status: ready`; execution state lives
  only in coordinator-owned receipts.
- Any requested removal, prohibition, rename or deprecated shortcut requires a
  zero-match Residual Check.
- Red-test packets require the exact failing test, exit code, failed/skipped
  counts and failure fingerprint. Green test evidence requires exact pass/fail/
  skip counts; a skipped gate is not a pass.
- Read-only anchors and SHA-256 pins must resolve before dispatch. Dependency
  receipt hashes must match accepted upstream evidence.
- Give each verification command an expected result. A command without an
  expected result is not an acceptance check.
- Always include the mandatory stop conditions and Verification Scope from the
  contract.
- Never ask the worker to commit, push, rebase, reset, clean, install/update
  dependencies, delete/rename files or run broad auto-formatting.
- Do not assign two concurrent packets overlapping the same writable file.
- Existing user changes are immutable. A worker stops on unexpected diffs
  rather than normalizing or reverting them.
- Candidate inventories must include `FORBID-AUTHORITATIVE-CLAIM`; weak workers
  cannot promote evidence to an authoritative contract.

## Coordinator Acceptance Gate

A packet is ready only when:

- Its objective describes one observable result.
- Its Intent is one sentence explaining business purpose without adding scope.
- Preconditions are facts, not work for the worker to discover.
- Mechanical Input is generator-backed or hash-pinned literal input.
- Every change step targets one allowlisted file and exact anchor.
- All values, signatures, names, states and expected errors are literal.
- The packet fits the worker limits and contains no unresolved choice.
- Domain, packet type, risk and review level are consistent with the actual
  work, and the expected modified set equals the writable allowlist.
- Scope hash, context budget and execution budget validate.
- Focused checks fail before the change when applicable and have explicit
  expected outcomes, case IDs, exact counts and failure fingerprints.
- Generator-backed outputs have byte-for-byte `--check` evidence and the
  expected SHA-256; count-only checks do not establish content correctness.
- A completed generator packet may be revalidated with its `CREATE` output
  present; the validator accepts it only when the existing file matches the
  declared generator SHA-256. `MODIFY` packets keep pre-dispatch semantics
  and are not final-output hash checked.
- The validator passes with the current repository root.
- Every V2 contract case maps to both a Required Change and an Acceptance or
  Residual Check.
- Semantic budgets fit the selected worker or the packet returns
  `STRONG_MODEL_REQUIRED`/`SPLIT_REQUIRED`.

For high-risk packets, a validator pass is necessary but not sufficient. Keep
the packet `ready` only when a stronger reviewer is named in Preconditions.
Critical work is never `ready` for these weak-worker profiles; return
`STRONG_MODEL_REQUIRED`.

Integration requires a validated receipt whose actual diff equals the writable
allowlist, pinned inputs remain unchanged, required checks were independently
replayed, contract cases are covered, skip counts are allowed explicitly and
the named reviewer accepted the exact candidate.

## Output

When repository writes are authorized, place packets under
`.agents/tasks/<source-id>/`. Otherwise return them inline. Use stable IDs and
encode dependencies in each packet; do not create an extra index unless the
user requests one.
