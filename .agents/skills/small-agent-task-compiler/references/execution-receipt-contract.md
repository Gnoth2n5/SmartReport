# Execution-Receipt Contract

The coordinator creates receipts after a worker returns. Workers never create,
edit or approve receipts. Store retained receipts under
`.agents/task-results/<source-id>/<task-id>.json` when repository evidence is
authorized; otherwise validate a temporary receipt outside the repository.

## Receipt Schema

```json
{
  "contract_version": 2,
  "task_id": "G02-M1-P1-T01",
  "scope_hash": "64 lowercase hex characters",
  "base_revision": "40 lowercase hex characters",
  "candidate_revision": "40 lowercase hex characters",
  "status": "DONE",
  "diff_sha256": "64 lowercase hex characters",
  "files_changed": ["relative/path.ext"],
  "file_sha256": {
    "relative/path.ext": "64 lowercase hex characters"
  },
  "contract_cases": ["CATALOG-001"],
  "checks_replayed": true,
  "checks": [
    {
      "command": "python3 exact/generator.py --check=relative/path.ext",
      "exit_code": 0,
      "passed": null,
      "failed": null,
      "skipped": null,
      "fingerprint_matched": null
    }
  ],
  "residual_checks": [],
  "review": {
    "required": "peer",
    "decision": "accepted",
    "reviewer": "coordinator"
  },
  "concerns": []
}
```

`BLOCKED` and `NEEDS_CONTEXT` receipts may cover a subset of writable files and
need not replay checks. `DONE` receipts require all fields above, exact writable
scope, every case, independently replayed checks and the required review.

## Diff Evidence

The validator calculates the actual candidate diff from `base_revision` and
requires:

- Changed paths equal Writable Files exactly for `DONE`.
- No Read-Only or undeclared path changed.
- `diff_sha256` equals SHA-256 of the binary Git diff.
- Every final writable file hash equals `file_sha256`.
- All pinned files and dependency receipts still match the packet.

The worker's prose `Files changed` value is not evidence.

## Check Evidence

Receipt commands must equal packet Acceptance and Residual commands in order.
With `--run-checks`, the coordinator replays them and verifies:

- Expected exit code or expected non-zero result.
- Exact pass/fail/skip counts when declared.
- `Skipped: 0` for release, concurrency, security and other gated tests unless
  the packet explicitly declares a non-gating skip allowance.
- Every `FAILURE-FINGERPRINT` token for red tests.
- Residual zero-match/absence checks.

The replayed output, not the worker report, determines acceptance.

## Review Evidence

For `risk: high`, `review.decision` must be `accepted`, `review.required` must
match packet metadata and `reviewer` must be non-empty. A worker cannot satisfy
its own strong/architect review.

## Phase Closure Manifest

```json
{
  "contract_version": 2,
  "phase_id": "G02-M1-P1",
  "candidate_revision": "40 lowercase hex characters",
  "required_tasks": ["G02-M1-P1-T01"],
  "required_cases": ["CATALOG-001"],
  "gate_tasks": ["G02-M1-P1-T01"],
  "receipts": [
    {
      "path": ".agents/task-results/g02-m1-p1/G02-M1-P1-T01.json",
      "sha256": "64 lowercase hex characters"
    }
  ]
}
```

Phase closure passes only when:

- Every required task has one hash-pinned `DONE` receipt.
- Every required case is covered.
- Every receipt has `checks_replayed: true`, zero unapproved skips and accepted
  review.
- Every gate receipt names the manifest candidate revision.
- The manifest candidate revision equals repository `HEAD`.
- No receipt reports concerns.

Validate with:

```bash
python3 .agents/skills/small-agent-task-compiler/scripts/validate_task_result.py \
  --phase-manifest path/to/manifest.json --repo-root .
```
