#!/usr/bin/env python3
"""Regression checks for validate_task_packet.py using an isolated repository."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


VALIDATOR = Path(__file__).with_name("validate_task_packet.py")
ZERO_HASH = "0" * 64


class TaskPacketValidatorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp_dir.name)
        (self.repo / "docs").mkdir()
        (self.repo / "docs/catalog.md").write_text("# Catalog\n", encoding="utf-8")
        (self.repo / "docs/reference.md").write_text("# Reference\n", encoding="utf-8")
        (self.repo / "tools").mkdir()
        (self.repo / "tools/generate.py").write_text("# deterministic generator\n", encoding="utf-8")
        (self.repo / "database/migrations").mkdir(parents=True)
        (self.repo / "database/migrations/0001_test.php").write_text(
            "<?php function up(): void {}\n", encoding="utf-8"
        )
        subprocess.run(["git", "init", "-q"], cwd=self.repo, check=True)
        subprocess.run(["git", "config", "user.email", "test@example.test"], cwd=self.repo, check=True)
        subprocess.run(["git", "config", "user.name", "Task Compiler Test"], cwd=self.repo, check=True)
        subprocess.run(["git", "add", "."], cwd=self.repo, check=True)
        subprocess.run(["git", "commit", "-qm", "fixture"], cwd=self.repo, check=True)
        self.head = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=self.repo,
            text=True,
            capture_output=True,
            check=True,
        ).stdout.strip()

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def packet(
        self,
        *,
        packet_type: str = "mechanical",
        worker_profile: str = "local-8b",
        domain: str = "documentation",
        risk: str = "low",
        review: str = "peer",
        max_context_tokens: int = 12000,
        max_file_changes: int = 1,
        max_runtime_minutes: int = 10,
        intent: str = "Keep the project catalog understandable for coordinators.",
        strong_review: bool = False,
        migration: bool = False,
        review_only: bool = False,
        writable_action: str = "MODIFY",
    ) -> str:
        writable_path = "database/migrations/0001_test.php" if migration else "docs/catalog.md"
        execution_mode = "generator" if packet_type == "mechanical" else "direct"
        expected_sha = hashlib.sha256((self.repo / "docs/catalog.md").read_bytes()).hexdigest()
        generator_command = f"python3 tools/generate.py --output={writable_path}"
        generator_check = f"python3 tools/generate.py --check={writable_path}"
        writable = (
            "- none"
            if review_only
            else f"- `{writable_action} {writable_path}` — Preserve the exact approved behavior."
        )
        change_symbol = "<new-file>" if writable_action == "CREATE" else "up"
        required_changes = (
            "- none"
            if review_only
            else (
                f"1. `{writable_path}::{change_symbol}` — Run `{generator_command}` exactly once."
                if packet_type == "mechanical"
                else f"1. `{writable_path}::{change_symbol}` — Preserve the exact approved behavior."
            )
        )
        verification_modified = (
            "- `EXPECTED-MODIFIED none`"
            if review_only
            else f"- `EXPECTED-MODIFIED {writable_path}`"
        )
        strong_precondition = (
            "\n- `STRONG_REVIEW_REQUIRED`: A stronger reviewer is assigned before integration."
            if strong_review
            else ""
        )
        return f"""---
task_id: TEST-T01
worker_profile: {worker_profile}
packet_type: {packet_type}
execution_mode: {execution_mode}
domain: {domain}
risk: {risk}
review: {review}
status: ready
scope_hash: {ZERO_HASH}
max_context_files: 4
max_context_tokens: {max_context_tokens}
max_file_changes: {max_file_changes}
max_commands: 2
max_runtime_minutes: {max_runtime_minutes}
---

# TASK TEST-T01 — Preserve one approved behavior

## Intent

{intent}

## Objective

Preserve one exact catalog behavior.

## Depends On

- none

## Preconditions

- `FACT-1`: The approved reference exists.{strong_precondition}

## Mechanical Input

{f'''- `GENERATOR-COMMAND`: `{generator_command}`
- `GENERATOR-CHECK`: `{generator_check}`
- `EXPECTED-SHA256`: `{expected_sha}`''' if packet_type == 'mechanical' else '- none'}

## Writable Files

{writable}

## Read-Only Context

- `docs/reference.md::Reference` — Preserve this accepted contract.
{f'- `tools/generate.py::generator` — Preserve the deterministic generator.' if packet_type == 'mechanical' else ''}

## Forbidden Changes

- `FORBID-OUTSIDE-WRITABLE`: Do not edit files outside Writable Files.
- `FORBID-DEPENDENCIES`: Do not change dependency manifests or lockfiles.
- `FORBID-GIT`: Do not commit, push, rebase, reset, checkout or clean.
- `FORBID-DESTRUCTIVE`: Do not delete/rename files or run broad formatting.
- `FORBID-AUTHORITATIVE-CLAIM`: Keep generated evidence classified Candidate.

## Required Changes

{required_changes}

## Acceptance Checks

{f'''1. `{generator_check}`
   Expected: Exit 0 and report SHA-256 {expected_sha}.
2. `sha256sum {writable_path}`
   Expected: {expected_sha}  {writable_path}''' if packet_type == 'mechanical' else '''1. `rg -n 'Catalog|Reference' docs`
   Expected: Exit 0 with the approved heading present.'''}

## Verification Scope

{verification_modified}
- `EXPECTED-UNCHANGED docs/reference.md`
{f'- `EXPECTED-UNCHANGED tools/generate.py`' if packet_type == 'mechanical' else ''}

## Stop Conditions

- `STOP-UNEXPECTED-DIFF`: Stop on unrelated user changes.
- `STOP-MISSING-ANCHOR`: Stop when an exact anchor is missing.
- `STOP-OUT-OF-SCOPE`: Stop before changing anything outside the packet.
- `STOP-FAILED-CHECK`: Stop when failure originates outside writable scope.
- `STOP-RUNTIME-LIMIT`: Stop when max_runtime_minutes is reached.

## Report Contract

Status: DONE | BLOCKED | NEEDS_CONTEXT
Scope hash: <scope_hash from frontmatter>
Files changed: <exact paths or none>
Files unchanged: <verified EXPECTED-UNCHANGED paths>
Checks: <command => result>
Commands run: <count/max_commands>
Runtime minutes: <elapsed/max_runtime_minutes>
Review required: peer | strong | architect
Concerns: <none or one concrete concern>
"""

    def v2_packet(
        self,
        *,
        risk: str = "low",
        review: str = "strong",
        estimated_diff_lines: int = 10,
        state_transition_count: int = 0,
        test_expectation: str = "none",
        required_change: str | None = None,
        acceptance_expected: str | None = None,
        residual: str = "- none",
        depends_on: str = "- none",
        dependency_pin: str = "",
        read_only_anchor: str = "Reference",
    ) -> str:
        catalog_hash = hashlib.sha256((self.repo / "docs/catalog.md").read_bytes()).hexdigest()
        reference_hash = hashlib.sha256((self.repo / "docs/reference.md").read_bytes()).hexdigest()
        required_change = required_change or (
            "1. `docs/catalog.md::Catalog` — Preserve the exact approved value. "
            "Cases: `CATALOG-001`."
        )
        acceptance_expected = acceptance_expected or (
            "Exit 0 with the approved heading. Cases: `CATALOG-001`."
        )
        return f"""---
task_id: TEST-V2-T01
contract_version: 2
worker_profile: local-8b
packet_type: refactor
execution_mode: direct
domain: documentation
risk: {risk}
review: {review}
status: ready
base_revision: {self.head}
estimated_diff_lines: {estimated_diff_lines}
state_transition_count: {state_transition_count}
test_expectation: {test_expectation}
scope_hash: {ZERO_HASH}
max_context_files: 4
max_context_tokens: 12000
max_file_changes: 1
max_commands: 3
max_runtime_minutes: 10
---

# TASK TEST-V2-T01 — Preserve one approved behavior

## Intent

Keep one catalog outcome deterministic for coordinators.

## Objective

Preserve the exact approved catalog behavior.

## Depends On

{depends_on}

## Preconditions

- `FACT-1`: The expected catalog value is fixed.
{f'- `STRONG_REVIEW_REQUIRED`: A stronger reviewer is assigned before integration.' if risk == 'high' else ''}

## Contract Cases

- `CATALOG-001` `[positive]` — GIVEN the approved catalog WHEN the worker edits it THEN the exact heading remains present.

## Mechanical Input

- none

## Pinned Inputs

- `BASE-REVISION {self.head}`
- `FILE-SHA256 docs/catalog.md {catalog_hash}`
- `FILE-SHA256 docs/reference.md {reference_hash}`
{dependency_pin}

## Writable Files

- `MODIFY docs/catalog.md` — Preserve the exact approved behavior.

## Read-Only Context

- `docs/reference.md::{read_only_anchor}` — Preserve the accepted reference.

## Forbidden Changes

- `FORBID-OUTSIDE-WRITABLE`: Do not edit files outside Writable Files.
- `FORBID-DEPENDENCIES`: Do not change dependency manifests or lockfiles.
- `FORBID-GIT`: Do not commit, push, rebase, reset, checkout or clean.
- `FORBID-DESTRUCTIVE`: Do not delete or rename files.
- `FORBID-AUTHORITATIVE-CLAIM`: Keep evidence classified Candidate.

## Required Changes

{required_change}

## Acceptance Checks

1. `test -f docs/catalog.md && rg -n 'Catalog' docs/catalog.md`
   Expected: {acceptance_expected}

## Residual Checks

{residual}

## Verification Scope

- `EXPECTED-MODIFIED docs/catalog.md`
- `EXPECTED-UNCHANGED docs/reference.md`

## Stop Conditions

- `STOP-UNEXPECTED-DIFF`: Stop on unrelated changes.
- `STOP-MISSING-ANCHOR`: Stop when an anchor or pin differs.
- `STOP-OUT-OF-SCOPE`: Stop before changing other files.
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

    def run_validator(self, packet: str, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(VALIDATOR), "-", *args],
            input=packet,
            text=True,
            capture_output=True,
            check=False,
        )

    def sign(self, packet: str) -> str:
        result = self.run_validator(packet, "--print-scope-hash")
        self.assertEqual(result.returncode, 0, result.stderr)
        return re.sub(r"scope_hash: [a-f0-9]{64}", f"scope_hash: {result.stdout.strip()}", packet)

    def validate(self, packet: str) -> subprocess.CompletedProcess[str]:
        return self.run_validator(packet, "--repo-root", str(self.repo))

    def test_valid_packet_and_scope_mutation(self) -> None:
        signed = self.sign(self.packet())
        self.assertEqual(self.validate(signed).returncode, 0)

        mutated = signed.replace(
            "Run `python3 tools/generate.py --output=docs/catalog.md` exactly once.",
            "Run `python3 tools/generate.py --output=docs/catalog.md` twice.",
            1,
        )
        result = self.validate(mutated)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("scope_hash mismatch", result.stderr)

    def test_existing_generator_create_artifact_is_revalidated(self) -> None:
        signed = self.sign(self.packet(writable_action="CREATE"))
        self.assertEqual(self.validate(signed).returncode, 0)

        (self.repo / "docs/catalog.md").write_text("# Changed\n", encoding="utf-8")
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("existing generator artifact hash mismatch", result.stderr)

    def test_existing_generator_modify_artifact_is_not_final_hash_checked(self) -> None:
        signed = self.sign(self.packet())

        (self.repo / "docs/catalog.md").write_text("# Changed\n", encoding="utf-8")
        self.assertEqual(self.validate(signed).returncode, 0)

    def test_mechanical_packet_rejects_discovery_directive(self) -> None:
        signed = self.sign(
            self.packet().replace(
                "Preserve one exact catalog behavior.",
                "Transcribe repository catalog behavior.",
            )
        )
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STRONG_MODEL_REQUIRED", result.stderr)

    def test_supported_worker_profiles(self) -> None:
        for profile in ("local-8b", "qwen-8b", "luna", "gemini"):
            with self.subTest(profile=profile):
                signed = self.sign(self.packet(worker_profile=profile))
                self.assertEqual(self.validate(signed).returncode, 0)

    def test_small_context_and_runtime_budgets_split(self) -> None:
        signed = self.sign(self.packet(max_context_tokens=1, max_runtime_minutes=21))
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SPLIT_REQUIRED", result.stderr)

    def test_large_context_profiles_accept_larger_context_only(self) -> None:
        for profile in ("luna", "gemini"):
            with self.subTest(profile=profile):
                signed = self.sign(
                    self.packet(
                        worker_profile=profile,
                        max_context_tokens=64_000,
                        max_runtime_minutes=20,
                    )
                )
                self.assertEqual(self.validate(signed).returncode, 0)

    def test_large_context_profiles_keep_ceiling(self) -> None:
        signed = self.sign(
            self.packet(
                worker_profile="gemini",
                max_context_tokens=64_001,
                max_runtime_minutes=21,
            )
        )
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SPLIT_REQUIRED", result.stderr)

    def test_five_change_steps_require_split(self) -> None:
        packet = self.packet()
        original = "1. `docs/catalog.md::up` — Run `python3 tools/generate.py --output=docs/catalog.md` exactly once."
        replacement = "\n".join(
            f"{number}. `docs/catalog.md::up` — Run `python3 tools/generate.py --output=docs/catalog.md` exactly once."
            for number in range(1, 6)
        )
        signed = self.sign(packet.replace(original, replacement))
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SPLIT_REQUIRED: Required Changes must contain 1-4 steps", result.stderr)

    def test_critical_packet_escalates(self) -> None:
        signed = self.sign(self.packet(risk="critical", review="architect"))
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STRONG_MODEL_REQUIRED", result.stderr)

    def test_review_packet_is_read_only(self) -> None:
        signed = self.sign(
            self.packet(packet_type="review", review_only=True, max_file_changes=0)
        )
        self.assertEqual(self.validate(signed).returncode, 0)

    def test_verification_scope_rejects_unintended_files(self) -> None:
        signed = self.sign(self.packet())
        mismatched = signed.replace(
            "EXPECTED-MODIFIED docs/catalog.md",
            "EXPECTED-MODIFIED docs/reference.md",
        )
        result = self.validate(mismatched)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("EXPECTED-MODIFIED must equal Writable Files exactly", result.stderr)

    def test_migration_packet_requires_type_specific_controls(self) -> None:
        signed = self.sign(
            self.packet(packet_type="migration", domain="backend-api", migration=True)
        )
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("migration packets require domain: database", result.stderr)
        self.assertIn("migration packets require risk: high", result.stderr)
        self.assertIn("migration packets require review: architect", result.stderr)

    def test_intent_and_domain_are_single_and_valid(self) -> None:
        signed = self.sign(
            self.packet(
                domain="backend-api,frontend-ui",
                intent="Explain one purpose. Add another purpose.",
            )
        )
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Intent must contain exactly one complete sentence", result.stderr)
        self.assertIn("domain must be one supported single-domain value", result.stderr)

    def test_v2_packet_validates_with_cases_and_pins(self) -> None:
        signed = self.sign(self.v2_packet())
        result = self.validate(signed)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_v2_contract_case_requires_change_and_evidence_mapping(self) -> None:
        packet = self.v2_packet(required_change="1. `docs/catalog.md::Catalog` — Preserve it.")
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Contract case missing from Required Changes", result.stderr)

    def test_v2_removal_requires_residual_check(self) -> None:
        packet = self.v2_packet(
            required_change=(
                "1. `docs/catalog.md::Catalog` — Remove the legacy heading. "
                "Cases: `CATALOG-001`."
            )
        )
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("require a Residual Check", result.stderr)

    def test_v2_red_test_requires_exact_fingerprint_and_counts(self) -> None:
        packet = self.v2_packet(
            test_expectation="red",
            acceptance_expected=(
                "Exit non-zero; Passed: 0; Failed: 1; Skipped: 0; "
                "Cases: `CATALOG-001`."
            ),
        ).replace("`test -f docs/catalog.md && rg -n 'Catalog' docs/catalog.md`", "`phpunit tests/CatalogTest.php`")
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("FAILURE-FINGERPRINT", result.stderr)

    def test_v2_green_test_rejects_skips(self) -> None:
        packet = self.v2_packet(
            test_expectation="green",
            acceptance_expected=(
                "Exit 0; Passed: 1; Failed: 0; Skipped: 1; "
                "Cases: `CATALOG-001`."
            ),
        ).replace("`test -f docs/catalog.md && rg -n 'Catalog' docs/catalog.md`", "`phpunit tests/CatalogTest.php`")
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Skipped: 0", result.stderr)

    def test_v2_dependency_requires_hash_pinned_receipt(self) -> None:
        packet = self.v2_packet(depends_on="- TEST-UPSTREAM")
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("DEPENDENCY_EVIDENCE_REQUIRED", result.stderr)

    def test_v2_accepts_hash_pinned_done_dependency_receipt(self) -> None:
        receipt_path = self.repo / ".agents/task-results/TEST-UPSTREAM.json"
        receipt_path.parent.mkdir(parents=True)
        receipt_path.write_text(
            json.dumps(
                {
                    "task_id": "TEST-UPSTREAM",
                    "status": "DONE",
                    "checks_replayed": True,
                    "review": {"decision": "accepted"},
                    "concerns": [],
                }
            ),
            encoding="utf-8",
        )
        digest = hashlib.sha256(receipt_path.read_bytes()).hexdigest()
        signed = self.sign(
            self.v2_packet(
                depends_on="- TEST-UPSTREAM",
                dependency_pin=(
                    "- `DEPENDENCY-RECEIPT TEST-UPSTREAM "
                    f".agents/task-results/TEST-UPSTREAM.json {digest}`"
                ),
            )
        )
        result = self.validate(signed)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_v2_rejects_missing_literal_anchor(self) -> None:
        signed = self.sign(self.v2_packet(read_only_anchor="MissingSymbol"))
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("anchor not found", result.stderr)

    def test_v2_high_risk_semantic_excess_requires_strong_model(self) -> None:
        signed = self.sign(
            self.v2_packet(
                risk="high",
                review="architect",
                estimated_diff_lines=151,
            )
        )
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STRONG_MODEL_REQUIRED", result.stderr)

    def test_v2_scope_hash_covers_contract_cases(self) -> None:
        signed = self.sign(self.v2_packet())
        mutated = signed.replace("exact heading remains present", "exact title remains present")
        result = self.validate(mutated)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("scope_hash mismatch", result.stderr)

    def test_v2_migration_requires_backfill_and_rollback_cases(self) -> None:
        packet = self.v2_packet(risk="high", review="architect")
        packet = packet.replace("packet_type: refactor", "packet_type: migration")
        packet = packet.replace("domain: documentation", "domain: database")
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("backfill", result.stderr)
        self.assertIn("rollback", result.stderr)

    def test_v2_import_work_requires_retry_and_lineage_cases(self) -> None:
        packet = self.v2_packet().replace(
            "Preserve the exact approved catalog behavior.",
            "Import the exact approved catalog behavior.",
        )
        signed = self.sign(packet)
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("lineage", result.stderr)
        self.assertIn("retry", result.stderr)

    def test_v2_state_transitions_require_negative_toctou_cases(self) -> None:
        signed = self.sign(self.v2_packet(state_transition_count=1))
        result = self.validate(signed)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("negative", result.stderr)
        self.assertIn("toctou", result.stderr)


if __name__ == "__main__":
    unittest.main()
