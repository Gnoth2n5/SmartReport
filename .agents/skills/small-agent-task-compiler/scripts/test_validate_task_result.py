#!/usr/bin/env python3
"""Regression checks for task-result and phase-closure validation."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT_DIR = Path(__file__).resolve().parent
PACKET_VALIDATOR = SCRIPT_DIR / "validate_task_packet.py"
RESULT_VALIDATOR = SCRIPT_DIR / "validate_task_result.py"
ZERO_HASH = "0" * 64


class TaskResultValidatorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.root = Path(self.temp_dir.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        (self.repo / "docs").mkdir()
        (self.repo / "docs/catalog.md").write_text("# Catalog\n", encoding="utf-8")
        (self.repo / "docs/reference.md").write_text("# Reference\n", encoding="utf-8")
        subprocess.run(["git", "init", "-q"], cwd=self.repo, check=True)
        subprocess.run(["git", "config", "user.email", "test@example.test"], cwd=self.repo, check=True)
        subprocess.run(["git", "config", "user.name", "Result Validator Test"], cwd=self.repo, check=True)
        subprocess.run(["git", "add", "."], cwd=self.repo, check=True)
        subprocess.run(["git", "commit", "-qm", "fixture"], cwd=self.repo, check=True)
        self.head = self.git("rev-parse", "HEAD").decode().strip()
        self.packet_path = self.root / "packet.md"
        self.receipt_path = self.root / "receipt.json"
        self.command = (
            "python3 -c 'import json; print(json.dumps({\"passed\": 1, "
            "\"failed\": 0, \"skipped\": 0}))' test"
        )
        self.packet_path.write_text(self.sign(self.packet()), encoding="utf-8")
        (self.repo / "docs/catalog.md").write_text("# Catalog\n\nAccepted.\n", encoding="utf-8")

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def git(self, *args: str) -> bytes:
        return subprocess.run(
            ["git", *args], cwd=self.repo, capture_output=True, check=True
        ).stdout

    def file_hash(self, path: Path) -> str:
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def packet(self) -> str:
        catalog_hash = self.file_hash(self.repo / "docs/catalog.md")
        reference_hash = self.file_hash(self.repo / "docs/reference.md")
        return f"""---
task_id: RESULT-T01
contract_version: 2
worker_profile: local-8b
packet_type: refactor
execution_mode: direct
domain: documentation
risk: low
review: strong
status: ready
base_revision: {self.head}
estimated_diff_lines: 3
state_transition_count: 0
test_expectation: green
scope_hash: {ZERO_HASH}
max_context_files: 4
max_context_tokens: 12000
max_file_changes: 1
max_commands: 2
max_runtime_minutes: 10
---

# TASK RESULT-T01 — Preserve one result

## Intent

Keep one coordinator result deterministic for integration.

## Objective

Add the exact accepted catalog marker.

## Depends On

- none

## Preconditions

- `FACT-1`: The marker is approved.

## Contract Cases

- `RESULT-001` `[positive]` — GIVEN the baseline catalog WHEN the marker is added THEN the focused check passes without skips.

## Mechanical Input

- none

## Pinned Inputs

- `BASE-REVISION {self.head}`
- `FILE-SHA256 docs/catalog.md {catalog_hash}`
- `FILE-SHA256 docs/reference.md {reference_hash}`

## Writable Files

- `MODIFY docs/catalog.md` — Add the approved marker.

## Read-Only Context

- `docs/reference.md::Reference` — Preserve the reference.

## Forbidden Changes

- `FORBID-OUTSIDE-WRITABLE`: Do not edit outside scope.
- `FORBID-DEPENDENCIES`: Do not change dependencies.
- `FORBID-GIT`: Do not commit or rewrite history.
- `FORBID-DESTRUCTIVE`: Do not delete files.
- `FORBID-AUTHORITATIVE-CLAIM`: Keep evidence Candidate.

## Required Changes

1. `docs/catalog.md::Catalog` — Add the approved marker. Cases: `RESULT-001`.

## Acceptance Checks

1. `{self.command}`
   Expected: Exit 0; Passed: 1; Failed: 0; Skipped: 0; Cases: `RESULT-001`.

## Residual Checks

- none

## Verification Scope

- `EXPECTED-MODIFIED docs/catalog.md`
- `EXPECTED-UNCHANGED docs/reference.md`

## Stop Conditions

- `STOP-UNEXPECTED-DIFF`: Stop on unrelated changes.
- `STOP-MISSING-ANCHOR`: Stop on missing pins.
- `STOP-OUT-OF-SCOPE`: Stop before widening scope.
- `STOP-FAILED-CHECK`: Stop on unexpected evidence.
- `STOP-RUNTIME-LIMIT`: Stop at the runtime limit.

## Report Contract

Status: DONE | BLOCKED | NEEDS_CONTEXT
Scope hash: <scope_hash from frontmatter>
Base revision: <base_revision from frontmatter>
Files changed: <exact paths or none>
Files unchanged: <verified paths>
Contract cases: <case IDs>
Checks: <command => result>
Residual checks: <command => result or none>
Commands run: <count/max_commands>
Runtime minutes: <elapsed/max_runtime_minutes>
Review required: peer | strong | architect
Concerns: <none or one concrete concern>
"""

    def sign(self, packet: str) -> str:
        result = subprocess.run(
            [sys.executable, str(PACKET_VALIDATOR), "-", "--print-scope-hash"],
            input=packet,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return re.sub(r"scope_hash: [a-f0-9]{64}", f"scope_hash: {result.stdout.strip()}", packet)

    def diff_hash(self) -> str:
        payload = self.git("diff", "--binary", self.head, "--")
        return hashlib.sha256(payload).hexdigest()

    def receipt(self, **overrides) -> dict:
        scope_hash = re.search(
            r"(?m)^scope_hash: ([a-f0-9]{64})$", self.packet_path.read_text(encoding="utf-8")
        ).group(1)
        data = {
            "contract_version": 2,
            "task_id": "RESULT-T01",
            "scope_hash": scope_hash,
            "base_revision": self.head,
            "candidate_revision": self.head,
            "status": "DONE",
            "diff_sha256": self.diff_hash(),
            "files_changed": ["docs/catalog.md"],
            "file_sha256": {
                "docs/catalog.md": self.file_hash(self.repo / "docs/catalog.md")
            },
            "contract_cases": ["RESULT-001"],
            "checks_replayed": True,
            "checks": [
                {
                    "command": self.command,
                    "exit_code": 0,
                    "passed": 1,
                    "failed": 0,
                    "skipped": 0,
                    "fingerprint_matched": None,
                }
            ],
            "residual_checks": [],
            "review": {
                "required": "strong",
                "decision": "accepted",
                "reviewer": "coordinator",
            },
            "concerns": [],
        }
        data.update(overrides)
        return data

    def validate_receipt(self, *, run_checks: bool = True) -> subprocess.CompletedProcess[str]:
        args = [
            sys.executable,
            str(RESULT_VALIDATOR),
            str(self.packet_path),
            str(self.receipt_path),
            "--repo-root",
            str(self.repo),
        ]
        if run_checks:
            args.append("--run-checks")
        return subprocess.run(args, text=True, capture_output=True, check=False)

    def test_valid_receipt_replays_checks_and_diff(self) -> None:
        self.receipt_path.write_text(json.dumps(self.receipt()), encoding="utf-8")
        result = self.validate_receipt()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_receipt_rejects_undeclared_changed_file(self) -> None:
        (self.repo / "docs/reference.md").write_text("# Reference\n\nChanged.\n", encoding="utf-8")
        self.receipt_path.write_text(json.dumps(self.receipt()), encoding="utf-8")
        result = self.validate_receipt()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(
            "FILE-SHA256 mismatch" in result.stderr
            or "differ from Writable Files" in result.stderr
        )

    def test_receipt_rejects_wrong_diff_hash(self) -> None:
        self.receipt_path.write_text(
            json.dumps(self.receipt(diff_sha256="0" * 64)), encoding="utf-8"
        )
        result = self.validate_receipt()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("diff_sha256", result.stderr)

    def test_receipt_replay_rejects_false_skip_evidence(self) -> None:
        receipt = self.receipt()
        receipt["checks"][0]["skipped"] = 1
        self.receipt_path.write_text(json.dumps(receipt), encoding="utf-8")
        result = self.validate_receipt()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("replay mismatch", result.stderr)

    def test_phase_manifest_requires_zero_skip_gate_receipts(self) -> None:
        results_dir = self.repo / ".agents/task-results/test"
        results_dir.mkdir(parents=True)
        receipt_path = results_dir / "RESULT-T01.json"
        receipt = self.receipt(files_changed=[], file_sha256={}, diff_sha256="0" * 64)
        receipt["checks"][0]["skipped"] = 1
        receipt_path.write_text(json.dumps(receipt), encoding="utf-8")
        manifest = {
            "contract_version": 2,
            "phase_id": "RESULT-P1",
            "candidate_revision": self.head,
            "required_tasks": ["RESULT-T01"],
            "required_cases": ["RESULT-001"],
            "gate_tasks": ["RESULT-T01"],
            "receipts": [
                {
                    "path": ".agents/task-results/test/RESULT-T01.json",
                    "sha256": self.file_hash(receipt_path),
                }
            ],
        }
        manifest_path = self.root / "manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        result = subprocess.run(
            [
                sys.executable,
                str(RESULT_VALIDATOR),
                "--phase-manifest",
                str(manifest_path),
                "--repo-root",
                str(self.repo),
            ],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("skipped gate evidence", result.stderr)

    def test_valid_phase_manifest_closes_exact_tasks_and_cases(self) -> None:
        results_dir = self.repo / ".agents/task-results/test"
        results_dir.mkdir(parents=True)
        receipt_path = results_dir / "RESULT-T01.json"
        receipt = self.receipt(files_changed=[], file_sha256={}, diff_sha256="0" * 64)
        receipt_path.write_text(json.dumps(receipt), encoding="utf-8")
        manifest = {
            "contract_version": 2,
            "phase_id": "RESULT-P1",
            "candidate_revision": self.head,
            "required_tasks": ["RESULT-T01"],
            "required_cases": ["RESULT-001"],
            "gate_tasks": ["RESULT-T01"],
            "receipts": [
                {
                    "path": ".agents/task-results/test/RESULT-T01.json",
                    "sha256": self.file_hash(receipt_path),
                }
            ],
        }
        manifest_path = self.root / "manifest-valid.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        result = subprocess.run(
            [
                sys.executable,
                str(RESULT_VALIDATOR),
                "--phase-manifest",
                str(manifest_path),
                "--repo-root",
                str(self.repo),
            ],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
