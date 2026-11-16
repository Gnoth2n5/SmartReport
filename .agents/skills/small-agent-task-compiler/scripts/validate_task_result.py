#!/usr/bin/env python3
"""Validate a V2 task execution receipt or phase-closure manifest."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any


SCRIPT_DIR = Path(__file__).resolve().parent
PACKET_VALIDATOR = SCRIPT_DIR / "validate_task_packet.py"


def load_packet_validator():
    spec = importlib.util.spec_from_file_location("task_packet_validator", PACKET_VALIDATOR)
    if spec is None or spec.loader is None:
        raise RuntimeError("Unable to load task-packet validator.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


PV = load_packet_validator()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("packet", nargs="?", type=Path)
    parser.add_argument("receipt", nargs="?", type=Path)
    parser.add_argument("--repo-root", required=True, type=Path)
    parser.add_argument("--run-checks", action="store_true")
    parser.add_argument("--phase-manifest", type=Path)
    return parser.parse_args()


def read_json(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"JSON root must be an object: {path}")
    return data


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git(repo_root: Path, *args: str) -> bytes:
    return subprocess.run(
        ["git", *args],
        cwd=repo_root,
        capture_output=True,
        check=True,
    ).stdout


def repository_head(repo_root: Path) -> str:
    return git(repo_root, "rev-parse", "HEAD").decode().strip()


def parse_writable(section: str) -> dict[str, str]:
    writable: dict[str, str] = {}
    if section.strip() == "- none":
        return writable
    for line in section.splitlines():
        match = re.fullmatch(r"- `(MODIFY|CREATE) ([^`]+)` — .+", line)
        if match:
            action, path = match.groups()
            writable[path] = action
    return writable


def parse_read_only(section: str) -> set[str]:
    paths: set[str] = set()
    for line in section.splitlines():
        match = re.fullmatch(r"- `([^`]+)::[^`]+` — .+", line)
        if match:
            paths.add(match.group(1))
    return paths


def changed_paths(
    repo_root: Path, base_revision: str, excluded: set[str] | None = None
) -> tuple[list[str], list[str]]:
    excluded = excluded or set()
    tracked = {
        line
        for line in git(repo_root, "diff", "--name-only", base_revision, "--").decode().splitlines()
        if line
    }
    untracked = {
        line
        for line in git(repo_root, "ls-files", "--others", "--exclude-standard").decode().splitlines()
        if line
    }
    return sorted((tracked | untracked) - excluded), sorted(untracked - excluded)


def diff_sha256(
    repo_root: Path, base_revision: str, paths: list[str], untracked: list[str]
) -> str:
    tracked_paths = [path for path in paths if path not in untracked]
    payload = bytearray(
        git(repo_root, "diff", "--binary", base_revision, "--", *tracked_paths)
        if tracked_paths
        else b""
    )
    for raw_path in untracked:
        payload.extend(f"\nUNTRACKED {raw_path}\n".encode())
        payload.extend((repo_root / raw_path).read_bytes())
    return hashlib.sha256(payload).hexdigest()


def output_metrics(output: str) -> tuple[int | None, int | None, int | None]:
    for line in reversed(output.splitlines()):
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(payload, dict) and any(key in payload for key in ("passed", "failed", "skipped")):
            return (
                int(payload["passed"]) if payload.get("passed") is not None else None,
                int(payload["failed"]) if payload.get("failed") is not None else 0,
                int(payload["skipped"]) if payload.get("skipped") is not None else 0,
            )

    def first(pattern: str) -> int | None:
        match = re.search(pattern, output, re.IGNORECASE)
        return int(match.group(1)) if match else None

    return (
        first(r"\b(\d+)\s+passed\b"),
        first(r"\b(\d+)\s+failed\b"),
        first(r"\b(\d+)\s+skipped\b"),
    )


def expected_exit(expected: str, actual: int) -> bool:
    if "Exit non-zero" in expected:
        return actual != 0
    match = re.search(r"\bExit (\d+)\b", expected)
    return match is None or actual == int(match.group(1))


def expected_fingerprint(expected: str, output: str) -> bool | None:
    match = re.search(r"FAILURE-FINGERPRINT:\s*([^;]+)", expected)
    if not match:
        return None
    lowered = output.lower()
    tokens = [token.strip().lower() for token in match.group(1).split("|") if token.strip()]
    return bool(tokens) and all(token in lowered for token in tokens)


def run_check(command: str, expected: str, repo_root: Path, timeout: int) -> dict[str, Any]:
    completed = subprocess.run(
        command,
        cwd=repo_root,
        shell=True,
        executable="/bin/bash",
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=timeout,
        check=False,
    )
    output = completed.stdout
    passed, failed, skipped = output_metrics(output)
    fingerprint = expected_fingerprint(expected, output)
    if not expected_exit(expected, completed.returncode):
        raise ValueError(f"unexpected exit for command: {command}")
    for name, actual in (("Passed", passed), ("Failed", failed), ("Skipped", skipped)):
        wanted = PV.metric(expected, name)
        if wanted is not None and actual != wanted:
            raise ValueError(f"{name} mismatch for {command}: expected {wanted}, actual {actual}")
    if fingerprint is False:
        raise ValueError(f"failure fingerprint mismatch for command: {command}")
    test_name = re.search(r"\bTest:\s*([^;]+)", expected)
    if test_name and test_name.group(1).strip().lower() not in output.lower():
        raise ValueError(f"test-name fingerprint mismatch for command: {command}")
    return {
        "command": command,
        "exit_code": completed.returncode,
        "passed": passed,
        "failed": failed,
        "skipped": skipped,
        "fingerprint_matched": fingerprint,
    }


def compare_check_evidence(
    label: str,
    expected_entries: list[tuple[str, str]],
    receipt_entries: Any,
    replayed: list[dict[str, Any]] | None,
    errors: list[str],
) -> None:
    if not isinstance(receipt_entries, list) or len(receipt_entries) != len(expected_entries):
        errors.append(f"{label} receipt count does not match packet")
        return
    for index, ((command, _), receipt) in enumerate(zip(expected_entries, receipt_entries)):
        if not isinstance(receipt, dict) or receipt.get("command") != command:
            errors.append(f"{label} command mismatch at index {index}")
            continue
        if replayed is not None:
            for key in ("command", "exit_code", "passed", "failed", "skipped", "fingerprint_matched"):
                if receipt.get(key) != replayed[index].get(key):
                    errors.append(f"{label} replay mismatch for {command}: {key}")


def validate_receipt(args: argparse.Namespace) -> int:
    if args.packet is None or args.receipt is None:
        print("ERROR: packet and receipt are required", file=sys.stderr)
        return 2
    repo_root = args.repo_root.resolve()
    packet_path = args.packet.resolve()
    receipt_path = args.receipt.resolve()
    packet_check = subprocess.run(
        [
            sys.executable,
            str(PACKET_VALIDATOR),
            str(packet_path),
            "--repo-root",
            str(repo_root),
            "--result-mode",
        ],
        text=True,
        capture_output=True,
        check=False,
    )
    if packet_check.returncode != 0:
        print(packet_check.stderr, file=sys.stderr, end="")
        return 1

    errors: list[str] = []
    text = packet_path.read_text(encoding="utf-8")
    metadata, body = PV.parse_frontmatter(text, errors)
    sections = PV.parse_sections(body, errors, 2)
    if metadata.get("contract_version") != "2":
        errors.append("execution receipts require a V2 packet")
    receipt = read_json(receipt_path)
    writable = parse_writable(sections.get("Writable Files", ""))
    read_only = parse_read_only(sections.get("Read-Only Context", ""))
    case_ids = PV.parse_contract_cases(sections.get("Contract Cases", ""), errors)
    acceptance = PV.parse_check_entries(sections.get("Acceptance Checks", ""), errors)
    residual = PV.parse_check_entries(
        sections.get("Residual Checks", ""), errors, allow_none=True, label="Residual Checks"
    )

    base_revision = metadata.get("base_revision", "")
    excluded: set[str] = set()
    try:
        excluded.add(receipt_path.relative_to(repo_root).as_posix())
    except ValueError:
        pass
    actual_paths, untracked = changed_paths(repo_root, base_revision, excluded)
    status = receipt.get("status")
    if status not in {"DONE", "BLOCKED", "NEEDS_CONTEXT"}:
        errors.append("receipt status must be DONE, BLOCKED or NEEDS_CONTEXT")
    expected_paths = sorted(writable)
    if status == "DONE" and actual_paths != expected_paths:
        errors.append(f"actual changed paths differ from Writable Files: {actual_paths}")
    if status != "DONE" and not set(actual_paths).issubset(writable):
        errors.append("blocked receipt changed a path outside Writable Files")
    if set(actual_paths) & read_only:
        errors.append("Read-Only Context changed during execution")

    scalar_expectations = {
        "contract_version": 2,
        "task_id": metadata.get("task_id"),
        "scope_hash": metadata.get("scope_hash"),
        "base_revision": base_revision,
        "candidate_revision": repository_head(repo_root),
        "diff_sha256": diff_sha256(repo_root, base_revision, actual_paths, untracked),
        "files_changed": actual_paths,
        "contract_cases": case_ids,
    }
    for key, expected in scalar_expectations.items():
        if receipt.get(key) != expected:
            errors.append(f"receipt mismatch for {key}")

    file_hashes = receipt.get("file_sha256")
    if not isinstance(file_hashes, dict):
        errors.append("receipt file_sha256 must be an object")
    else:
        if set(file_hashes) != set(actual_paths):
            errors.append("receipt file_sha256 keys must equal actual changed paths")
        for raw_path, digest in file_hashes.items():
            path = repo_root / raw_path
            if not path.is_file() or sha256_file(path) != digest:
                errors.append(f"final file hash mismatch: {raw_path}")

    replayed_acceptance = None
    replayed_residual = None
    if args.run_checks:
        timeout = int(metadata.get("max_runtime_minutes", "20")) * 60
        try:
            replayed_acceptance = [run_check(command, expected, repo_root, timeout) for command, expected in acceptance]
            replayed_residual = [run_check(command, expected, repo_root, timeout) for command, expected in residual]
        except (ValueError, subprocess.TimeoutExpired) as exc:
            errors.append(str(exc))
        if receipt.get("checks_replayed") is not True:
            errors.append("receipt must set checks_replayed=true when checks are replayed")
    elif status == "DONE" and receipt.get("checks_replayed") is not True:
        errors.append("DONE receipt requires checks_replayed=true")

    compare_check_evidence("checks", acceptance, receipt.get("checks"), replayed_acceptance, errors)
    compare_check_evidence(
        "residual_checks", residual, receipt.get("residual_checks"), replayed_residual, errors
    )

    review = receipt.get("review")
    if status == "DONE":
        if not isinstance(review, dict):
            errors.append("DONE receipt requires review evidence")
        else:
            if review.get("required") != metadata.get("review"):
                errors.append("receipt review level does not match packet")
            if review.get("decision") != "accepted" or not review.get("reviewer"):
                errors.append("DONE receipt requires an accepted named reviewer")
        if receipt.get("concerns") != []:
            errors.append("DONE receipt must have no concerns")

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1
    print(
        f"PASS {metadata['task_id']}: status={status}; files={len(actual_paths)}; "
        f"checks={len(acceptance) + len(residual)}; cases={len(case_ids)}"
    )
    return 0


def validate_phase_manifest(path: Path, repo_root: Path) -> int:
    errors: list[str] = []
    manifest = read_json(path)
    if manifest.get("contract_version") != 2:
        errors.append("phase manifest contract_version must be 2")
    candidate = manifest.get("candidate_revision")
    if candidate != repository_head(repo_root):
        errors.append("phase candidate_revision must equal repository HEAD")
    required_tasks = manifest.get("required_tasks")
    required_cases = manifest.get("required_cases")
    gate_tasks = set(manifest.get("gate_tasks", []))
    receipt_specs = manifest.get("receipts")
    if not all(isinstance(value, list) for value in (required_tasks, required_cases, receipt_specs)):
        errors.append("phase required_tasks, required_cases and receipts must be lists")
        required_tasks, required_cases, receipt_specs = [], [], []

    receipts: dict[str, dict[str, Any]] = {}
    covered_cases: set[str] = set()
    for spec in receipt_specs:
        if not isinstance(spec, dict) or not isinstance(spec.get("path"), str):
            errors.append("invalid phase receipt entry")
            continue
        receipt_path = repo_root / spec["path"]
        if not receipt_path.is_file():
            errors.append(f"phase receipt is missing: {spec['path']}")
            continue
        if sha256_file(receipt_path) != spec.get("sha256"):
            errors.append(f"phase receipt hash mismatch: {spec['path']}")
            continue
        receipt = read_json(receipt_path)
        task_id = receipt.get("task_id")
        if not isinstance(task_id, str) or task_id in receipts:
            errors.append(f"invalid or duplicate phase receipt task: {task_id}")
            continue
        receipts[task_id] = receipt
        covered_cases.update(receipt.get("contract_cases", []))
        if receipt.get("status") != "DONE" or receipt.get("checks_replayed") is not True:
            errors.append(f"phase receipt is not accepted DONE evidence: {task_id}")
        if receipt.get("review", {}).get("decision") != "accepted":
            errors.append(f"phase receipt lacks accepted review: {task_id}")
        if receipt.get("concerns") != []:
            errors.append(f"phase receipt has unresolved concerns: {task_id}")
        for check in receipt.get("checks", []) + receipt.get("residual_checks", []):
            if isinstance(check, dict) and (check.get("skipped") or 0) > 0:
                errors.append(f"phase receipt contains skipped gate evidence: {task_id}")
        if task_id in gate_tasks and receipt.get("candidate_revision") != candidate:
            errors.append(f"gate receipt candidate mismatch: {task_id}")

    if set(required_tasks) != set(receipts):
        errors.append("phase receipts do not exactly cover required_tasks")
    missing_cases = set(required_cases) - covered_cases
    if missing_cases:
        errors.append("phase receipts miss required cases: " + ", ".join(sorted(missing_cases)))
    if gate_tasks - set(required_tasks):
        errors.append("gate_tasks must be included in required_tasks")

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1
    print(
        f"PASS {manifest.get('phase_id')}: tasks={len(receipts)}; "
        f"cases={len(covered_cases)}; candidate={candidate}"
    )
    return 0


def main() -> int:
    args = parse_args()
    repo_root = args.repo_root.resolve()
    try:
        if args.phase_manifest is not None:
            return validate_phase_manifest(args.phase_manifest.resolve(), repo_root)
        return validate_receipt(args)
    except (OSError, ValueError, json.JSONDecodeError, subprocess.CalledProcessError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
