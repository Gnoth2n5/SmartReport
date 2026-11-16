#!/usr/bin/env python3
"""Validate strict Markdown task packets for small coding agents."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
import shlex
import subprocess
import sys
from pathlib import Path, PurePosixPath


REQUIRED_METADATA = {
    "task_id",
    "worker_profile",
    "packet_type",
    "execution_mode",
    "domain",
    "risk",
    "review",
    "status",
    "scope_hash",
    "max_context_files",
    "max_context_tokens",
    "max_file_changes",
    "max_commands",
    "max_runtime_minutes",
}
V2_METADATA = {
    "contract_version",
    "base_revision",
    "estimated_diff_lines",
    "state_transition_count",
    "test_expectation",
}
INTEGER_METADATA = {
    "max_context_files",
    "max_context_tokens",
    "max_file_changes",
    "max_commands",
    "max_runtime_minutes",
}
DOMAINS = {
    "backend-api",
    "frontend-ui",
    "database",
    "testing",
    "infrastructure",
    "documentation",
    "tooling",
}
PACKET_TYPES = {"mechanical", "review", "refactor", "test", "migration"}
EXECUTION_MODES = {"generator", "literal", "direct"}
RISKS = {"low", "medium", "high", "critical"}
REVIEWS = {"peer", "strong", "architect"}
SMALL_PROFILE_CEILINGS = {
    profile: {
        "max_context_files": 8,
        "max_context_tokens": 16_000,
        "max_file_changes": 4,
        "max_commands": 3,
        "max_runtime_minutes": 20,
        "max_change_steps": 4,
    }
    for profile in ("local-8b", "qwen-8b")
}
LARGE_PROFILE_CEILINGS = {
    profile: {
        "max_context_files": 12,
        "max_context_tokens": 64_000,
        "max_file_changes": 4,
        "max_commands": 3,
        "max_runtime_minutes": 20,
        "max_change_steps": 4,
    }
    for profile in ("luna", "gemini")
}
PROFILE_CEILINGS = SMALL_PROFILE_CEILINGS | LARGE_PROFILE_CEILINGS
PROFILE_ERROR = "worker_profile must be local-8b, qwen-8b, luna or gemini"
REQUIRED_SECTIONS = [
    "Intent",
    "Objective",
    "Depends On",
    "Preconditions",
    "Mechanical Input",
    "Writable Files",
    "Read-Only Context",
    "Forbidden Changes",
    "Required Changes",
    "Acceptance Checks",
    "Verification Scope",
    "Stop Conditions",
    "Report Contract",
]
V2_REQUIRED_SECTIONS = [
    "Intent",
    "Objective",
    "Depends On",
    "Preconditions",
    "Contract Cases",
    "Mechanical Input",
    "Pinned Inputs",
    "Writable Files",
    "Read-Only Context",
    "Forbidden Changes",
    "Required Changes",
    "Acceptance Checks",
    "Residual Checks",
    "Verification Scope",
    "Stop Conditions",
    "Report Contract",
]
SCOPE_HASH_SECTIONS = ["Writable Files", "Required Changes", "Acceptance Checks"]
V2_SCOPE_HASH_SECTIONS = [
    "Objective",
    "Depends On",
    "Preconditions",
    "Contract Cases",
    "Mechanical Input",
    "Pinned Inputs",
    "Writable Files",
    "Forbidden Changes",
    "Required Changes",
    "Acceptance Checks",
    "Residual Checks",
    "Verification Scope",
    "Stop Conditions",
]
SEMANTIC_CEILINGS = {
    "local-8b": {
        "contract_cases": 2,
        "target_file_lines": 600,
        "estimated_diff_lines": 150,
        "state_transitions": 1,
    },
    "qwen-8b": {
        "contract_cases": 2,
        "target_file_lines": 600,
        "estimated_diff_lines": 150,
        "state_transitions": 1,
    },
    "luna": {
        "contract_cases": 4,
        "target_file_lines": 1500,
        "estimated_diff_lines": 400,
        "state_transitions": 3,
    },
    "gemini": {
        "contract_cases": 4,
        "target_file_lines": 1500,
        "estimated_diff_lines": 400,
        "state_transitions": 3,
    },
}
CASE_CLASSES = {
    "positive",
    "negative",
    "absent",
    "retry",
    "concurrency",
    "toctou",
    "backfill",
    "rollback",
    "lineage",
}
REQUIRED_FORBIDS = {
    "FORBID-OUTSIDE-WRITABLE",
    "FORBID-DEPENDENCIES",
    "FORBID-GIT",
    "FORBID-DESTRUCTIVE",
    "FORBID-AUTHORITATIVE-CLAIM",
}
REQUIRED_STOPS = {
    "STOP-UNEXPECTED-DIFF",
    "STOP-MISSING-ANCHOR",
    "STOP-OUT-OF-SCOPE",
    "STOP-FAILED-CHECK",
    "STOP-RUNTIME-LIMIT",
}
AMBIGUOUS_PHRASES = (
    "as needed",
    "if appropriate",
    "use best judgment",
    "consider ",
    "etc.",
    "and so on",
    "where applicable",
    "follow the existing pattern",
)
DANGEROUS_COMMANDS = (
    r"\brm\s+-rf\b",
    r"\bgit\s+(?:commit|push|rebase|reset|checkout|clean)\b",
    r"\b(?:npm|pnpm|yarn)\s+(?:install|add|update|remove)\b",
    r"\bcomposer\s+(?:require|update|remove)\b",
    r"\bphp\s+artisan\s+migrate:fresh\b",
    r"\bsudo\b",
)
DEPENDENCY_FILES = {
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "pnpm-lock.yaml",
    "yarn.lock",
}
HIGH_RISK_PATHS = (
    "auth",
    "authorization",
    "security",
    "payroll",
    "database/migrations/",
    ".github/workflows/",
    "config/",
    "routes/",
)
NON_MECHANICAL_DIRECTIVES = (
    r"\btranscribe\b",
    r"\bgather(?:ed)?\b",
    r"\binfer\b",
    r"\bderive\b",
    r"\benumerate\b",
    r"\bdetermine\b",
    r"in declaration order",
    r"middleware order",
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("packet", help="Packet path or - for stdin")
    parser.add_argument("--repo-root", type=Path, help="Repository root for path and context checks")
    parser.add_argument(
        "--print-scope-hash",
        action="store_true",
        help="Print the canonical scope hash without validating its frontmatter value",
    )
    parser.add_argument(
        "--result-mode",
        action="store_true",
        help="Validate an immutable V2 packet after execution while allowing declared writable drift",
    )
    return parser.parse_args()


def read_packet(packet: str) -> str:
    if packet == "-":
        return sys.stdin.read()
    return Path(packet).read_text(encoding="utf-8")


def parse_frontmatter(text: str, errors: list[str]) -> tuple[dict[str, str], str]:
    match = re.match(r"\A---\n(.*?)\n---\n", text, re.DOTALL)
    if not match:
        errors.append("missing YAML frontmatter")
        return {}, text

    metadata: dict[str, str] = {}
    for line in match.group(1).splitlines():
        key, separator, value = line.partition(":")
        key = key.strip()
        value = value.strip()
        if not separator or not key or not value:
            errors.append(f"invalid frontmatter line: {line!r}")
            continue
        if key in metadata:
            errors.append(f"duplicate frontmatter key: {key}")
        metadata[key] = value

    contract_version = metadata.get("contract_version")
    required = REQUIRED_METADATA | (V2_METADATA if contract_version is not None else set())
    missing = required - metadata.keys()
    extra = metadata.keys() - required
    if missing:
        errors.append("missing frontmatter keys: " + ", ".join(sorted(missing)))
    if extra:
        errors.append("unknown frontmatter keys: " + ", ".join(sorted(extra)))
    return metadata, text[match.end() :]


def parse_sections(body: str, errors: list[str], contract_version: int = 1) -> dict[str, str]:
    matches = list(re.finditer(r"(?m)^## ([^\n]+)\n", body))
    sections: dict[str, str] = {}
    order: list[str] = []
    for index, match in enumerate(matches):
        name = match.group(1).strip()
        end = matches[index + 1].start() if index + 1 < len(matches) else len(body)
        if name in sections:
            errors.append(f"duplicate section: {name}")
        sections[name] = body[match.end() : end].strip()
        order.append(name)

    required_sections = V2_REQUIRED_SECTIONS if contract_version == 2 else REQUIRED_SECTIONS
    if order != required_sections:
        errors.append("sections must match the required names and order exactly")
    for name in required_sections:
        if not sections.get(name, "").strip():
            errors.append(f"section is empty: {name}")
    return sections


def canonical_section(section: str) -> str:
    return "\n".join(line.rstrip() for line in section.strip().splitlines())


def scope_hash(sections: dict[str, str], contract_version: int = 1) -> str:
    hash_sections = V2_SCOPE_HASH_SECTIONS if contract_version == 2 else SCOPE_HASH_SECTIONS
    payload = "\n\n".join(
        f"## {name}\n{canonical_section(sections.get(name, ''))}"
        for name in hash_sections
    ) + "\n"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def safe_relative_path(raw: str) -> bool:
    if "\\" in raw or any(char in raw for char in "*?[]"):
        return False
    path = PurePosixPath(raw)
    return (
        not path.is_absolute()
        and ".." not in path.parts
        and raw not in {"", ".", "none"}
        and not raw.endswith("/")
    )


def integer_limit(metadata: dict[str, str], name: str, errors: list[str]) -> int:
    raw = metadata.get(name, "")
    try:
        value = int(raw)
    except ValueError:
        errors.append(f"{name} must be an integer")
        return 0
    allow_zero = name == "max_file_changes" and metadata.get("packet_type") == "review"
    if value < 0 or (value == 0 and not allow_zero):
        errors.append(f"{name} must be positive")
    return value


def nonnegative_integer(metadata: dict[str, str], name: str, errors: list[str]) -> int:
    raw = metadata.get(name, "")
    try:
        value = int(raw)
    except ValueError:
        errors.append(f"{name} must be an integer")
        return 0
    if value < 0:
        errors.append(f"{name} must be non-negative")
    return value


def parse_limits(metadata: dict[str, str], errors: list[str]) -> dict[str, int]:
    limits = {name: integer_limit(metadata, name, errors) for name in INTEGER_METADATA}

    ceilings = PROFILE_CEILINGS.get(metadata.get("worker_profile", ""))
    if not ceilings:
        errors.append(PROFILE_ERROR)
        return limits
    for name in INTEGER_METADATA:
        if limits.get(name, 0) > ceilings[name]:
            errors.append(
                f"SPLIT_REQUIRED: {name}={limits[name]} exceeds "
                f"{metadata['worker_profile']} ceiling {ceilings[name]}"
            )
    return limits


def parse_writable_files(
    section: str,
    packet_type: str,
    repo_root: Path,
    errors: list[str],
    allow_existing_create: bool = False,
) -> dict[str, str]:
    if packet_type == "review":
        if section.strip() != "- none":
            errors.append("review packets require Writable Files: - none")
        return {}

    writable: dict[str, str] = {}
    for line in section.splitlines():
        if not line.strip():
            continue
        match = re.fullmatch(r"- `(MODIFY|CREATE) ([^`]+)` — .+", line)
        if not match:
            errors.append(f"invalid Writable Files entry: {line}")
            continue
        action, raw_path = match.groups()
        if not safe_relative_path(raw_path):
            errors.append(f"unsafe writable path: {raw_path}")
            continue
        if raw_path in writable:
            errors.append(f"duplicate writable path: {raw_path}")
            continue
        if PurePosixPath(raw_path).name in DEPENDENCY_FILES:
            errors.append(f"dependency file is forbidden: {raw_path}")
        writable[raw_path] = action

        target = repo_root / raw_path
        if action == "MODIFY" and not target.is_file():
            errors.append(f"MODIFY path is not an existing file: {raw_path}")
        if action == "CREATE" and target.exists() and not allow_existing_create:
            errors.append(f"CREATE path already exists: {raw_path}")
        if action == "CREATE" and not target.parent.is_dir():
            errors.append(f"CREATE parent directory is missing: {raw_path}")

    if not writable:
        errors.append("write packets require at least one Writable Files entry")
    return writable


def parse_read_only(section: str, repo_root: Path, errors: list[str]) -> set[str]:
    paths: set[str] = set()
    entries = [line for line in section.splitlines() if line.strip()]
    if not entries:
        errors.append("Read-Only Context requires at least one exact file anchor")
    for line in entries:
        match = re.fullmatch(r"- `([^`]+)::([^`]+)` — .+", line)
        if not match:
            errors.append(f"invalid Read-Only Context entry: {line}")
            continue
        raw_path = match.group(1)
        if not safe_relative_path(raw_path):
            errors.append(f"unsafe read-only path: {raw_path}")
            continue
        if raw_path in paths:
            errors.append(f"duplicate read-only path: {raw_path}")
        paths.add(raw_path)
        if not (repo_root / raw_path).is_file():
            errors.append(f"read-only path is not an existing file: {raw_path}")
    return paths


def parse_required_changes(
    section: str,
    packet_type: str,
    writable: dict[str, str],
    max_steps: int,
    errors: list[str],
) -> int:
    if packet_type == "review":
        if section.strip() != "- none":
            errors.append("review packets require Required Changes: - none")
        return 0

    entries = [line for line in section.splitlines() if line.strip()]
    if not 1 <= len(entries) <= max_steps:
        errors.append(f"SPLIT_REQUIRED: Required Changes must contain 1-{max_steps} steps")
    for expected_number, line in enumerate(entries, start=1):
        match = re.fullmatch(r"(\d+)\. `([^`]+)::([^`]+)` — .+", line)
        if not match:
            errors.append(f"invalid Required Changes entry: {line}")
            continue
        number, raw_path, symbol = match.groups()
        if int(number) != expected_number:
            errors.append("Required Changes numbering must be sequential")
        if raw_path not in writable:
            errors.append(f"Required Changes path is not writable: {raw_path}")
            continue
        if writable[raw_path] == "CREATE" and symbol != "<new-file>":
            errors.append(f"CREATE change must use <new-file>: {raw_path}")
        if writable[raw_path] == "MODIFY" and symbol == "<new-file>":
            errors.append(f"MODIFY change requires an exact symbol: {raw_path}")
    return len(entries)


def parse_check_entries(
    section: str,
    errors: list[str],
    *,
    allow_none: bool = False,
    label: str = "Acceptance Checks",
) -> list[tuple[str, str]]:
    if allow_none and section.strip() == "- none":
        return []
    matches = list(
        re.finditer(r"(?m)^(\d+)\. `([^`]+)`\n {3}Expected: (.+)$", section)
    )
    unmatched = re.sub(
        r"(?m)^\d+\. `[^`]+`\n {3}Expected: .+$", "", section
    ).strip()
    if unmatched:
        errors.append(f"{label} contains invalid extra text")
    if not matches:
        errors.append(f"{label} requires at least one command")

    entries: list[tuple[str, str]] = []
    for expected_number, match in enumerate(matches, start=1):
        number, command, expected = match.groups()
        if int(number) != expected_number:
            errors.append("Acceptance Checks numbering must be sequential")
        if not expected.strip():
            errors.append(f"Acceptance Check {number} has no expected result")
        for pattern in DANGEROUS_COMMANDS:
            if re.search(pattern, command, re.IGNORECASE):
                errors.append(f"dangerous {label} command: {command}")
        entries.append((command, expected))
    return entries


def parse_checks(section: str, errors: list[str]) -> list[str]:
    return [command for command, _ in parse_check_entries(section, errors)]


def parse_dependencies(section: str, errors: list[str]) -> list[str]:
    if section.strip() == "- none":
        return []
    dependencies: list[str] = []
    for line in section.splitlines():
        match = re.fullmatch(r"- ([A-Z0-9][A-Z0-9._-]{2,63})", line)
        if not match:
            errors.append(f"invalid Depends On entry: {line}")
            continue
        dependencies.append(match.group(1))
    if len(dependencies) != len(set(dependencies)):
        errors.append("Depends On contains duplicate task IDs")
    return dependencies


def parse_contract_cases(section: str, errors: list[str]) -> list[str]:
    case_ids: list[str] = []
    for line in section.splitlines():
        match = re.fullmatch(
            r"- `([A-Z][A-Z0-9-]{2,63})` `\[([a-z,-]+)\]` — GIVEN .+ WHEN .+ THEN .+\.?",
            line,
        )
        if not match:
            errors.append(f"invalid Contract Cases entry: {line}")
            continue
        case_id, raw_classes = match.groups()
        classes = {value for value in raw_classes.split(",") if value}
        if not classes or not classes.issubset(CASE_CLASSES):
            errors.append(f"invalid Contract Cases classes for {case_id}")
        case_ids.append(case_id)
    if not case_ids:
        errors.append("Contract Cases requires at least one GIVEN/WHEN/THEN case")
    if len(case_ids) != len(set(case_ids)):
        errors.append("Contract Cases contains duplicate IDs")
    return case_ids


def contract_case_classes(section: str) -> set[str]:
    classes: set[str] = set()
    for raw in re.findall(r"`\[([a-z,-]+)\]`", section):
        classes.update(value for value in raw.split(",") if value)
    return classes


def validate_case_matrix(
    metadata: dict[str, str],
    case_section: str,
    packet_text: str,
    errors: list[str],
) -> None:
    classes = contract_case_classes(case_section)
    required = {"positive"}
    if metadata.get("risk") == "high" or metadata.get("test_expectation") == "red":
        required.add("negative")
    if metadata.get("packet_type") == "migration":
        required.update({"backfill", "rollback"})
    if re.search(r"\bimport(?:er|ing|ed)?\b", packet_text, re.IGNORECASE):
        required.update({"retry", "lineage"})
    if nonnegative_integer(metadata, "state_transition_count", errors) > 0:
        required.update({"negative", "toctou"})
    missing = required - classes
    if missing:
        errors.append("Contract Cases missing required classes: " + ", ".join(sorted(missing)))


def validate_case_coverage(
    case_ids: list[str],
    required_changes: str,
    acceptance_checks: str,
    residual_checks: str,
    errors: list[str],
    *,
    require_change: bool = True,
) -> None:
    evidence = acceptance_checks + "\n" + residual_checks
    for case_id in case_ids:
        if require_change and f"`{case_id}`" not in required_changes:
            errors.append(f"Contract case missing from Required Changes: {case_id}")
        if f"`{case_id}`" not in evidence:
            errors.append(f"Contract case missing from Acceptance/Residual Checks: {case_id}")


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def validate_pinned_inputs(
    section: str,
    metadata: dict[str, str],
    writable: dict[str, str],
    read_only: set[str],
    dependencies: list[str],
    repo_root: Path,
    errors: list[str],
    *,
    allow_writable_drift: bool = False,
) -> None:
    base_revision = metadata.get("base_revision", "")
    pins: dict[str, str] = {}
    dependency_pins: dict[str, tuple[str, str]] = {}
    found_base = None

    for line in section.splitlines():
        base = re.fullmatch(r"- `BASE-REVISION ([a-f0-9]{40})`", line)
        file_pin = re.fullmatch(r"- `FILE-SHA256 ([^ ]+) ([a-f0-9]{64})`", line)
        dependency = re.fullmatch(
            r"- `DEPENDENCY-RECEIPT ([A-Z0-9][A-Z0-9._-]{2,63}) ([^ ]+) ([a-f0-9]{64})`",
            line,
        )
        if base:
            found_base = base.group(1)
        elif file_pin:
            path, digest = file_pin.groups()
            if not safe_relative_path(path):
                errors.append(f"unsafe pinned path: {path}")
            pins[path] = digest
        elif dependency:
            task_id, path, digest = dependency.groups()
            if not safe_relative_path(path):
                errors.append(f"unsafe dependency receipt path: {path}")
            dependency_pins[task_id] = (path, digest)
        else:
            errors.append(f"invalid Pinned Inputs entry: {line}")

    if found_base != base_revision:
        errors.append("Pinned BASE-REVISION must equal frontmatter base_revision")
    try:
        head = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=repo_root,
            text=True,
            capture_output=True,
            check=True,
        ).stdout.strip()
        if head != base_revision:
            errors.append(f"base_revision does not match repository HEAD: {head}")
    except (OSError, subprocess.CalledProcessError) as exc:
        errors.append(f"cannot resolve repository HEAD: {exc}")

    required_file_pins = {
        path for path, action in writable.items() if action == "MODIFY"
    } | read_only
    missing_pins = required_file_pins - pins.keys()
    extra_pins = pins.keys() - required_file_pins
    if missing_pins:
        errors.append("Pinned Inputs missing FILE-SHA256: " + ", ".join(sorted(missing_pins)))
    if extra_pins:
        errors.append("Pinned Inputs has unexpected FILE-SHA256: " + ", ".join(sorted(extra_pins)))
    writable_modify = {path for path, action in writable.items() if action == "MODIFY"}
    for raw_path, digest in pins.items():
        path = repo_root / raw_path
        if (
            path.is_file()
            and not (allow_writable_drift and raw_path in writable_modify)
            and file_sha256(path) != digest
        ):
            errors.append(f"FILE-SHA256 mismatch: {raw_path}")

    missing_dependencies = set(dependencies) - dependency_pins.keys()
    extra_dependencies = dependency_pins.keys() - set(dependencies)
    if missing_dependencies:
        errors.append(
            "DEPENDENCY_EVIDENCE_REQUIRED: missing receipt pins for "
            + ", ".join(sorted(missing_dependencies))
        )
    if extra_dependencies:
        errors.append("unexpected dependency receipt pins: " + ", ".join(sorted(extra_dependencies)))
    for task_id, (raw_path, digest) in dependency_pins.items():
        path = repo_root / raw_path
        if not path.is_file():
            errors.append(f"DEPENDENCY_EVIDENCE_REQUIRED: receipt missing for {task_id}: {raw_path}")
        elif file_sha256(path) != digest:
            errors.append(f"dependency receipt hash mismatch for {task_id}")
        else:
            try:
                receipt = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, UnicodeError, json.JSONDecodeError) as exc:
                errors.append(f"invalid dependency receipt for {task_id}: {exc}")
                continue
            if (
                not isinstance(receipt, dict)
                or receipt.get("task_id") != task_id
                or receipt.get("status") != "DONE"
                or receipt.get("checks_replayed") is not True
                or receipt.get("review", {}).get("decision") != "accepted"
                or receipt.get("concerns") != []
            ):
                errors.append(f"DEPENDENCY_EVIDENCE_REQUIRED: receipt is not accepted DONE evidence for {task_id}")


def validate_literal_anchors(
    read_only_section: str,
    required_changes_section: str,
    writable: dict[str, str],
    repo_root: Path,
    errors: list[str],
    *,
    allow_writable_drift: bool = False,
) -> None:
    anchors: list[tuple[str, str]] = []
    for line in read_only_section.splitlines():
        match = re.fullmatch(r"- `([^`]+)::([^`]+)` — .+", line)
        if match:
            anchors.append(match.groups())
    for line in required_changes_section.splitlines():
        match = re.fullmatch(r"\d+\. `([^`]+)::([^`]+)` — .+", line)
        if (
            match
            and writable.get(match.group(1)) == "MODIFY"
            and not allow_writable_drift
        ):
            anchors.append(match.groups())
    for raw_path, symbol in anchors:
        try:
            contents = (repo_root / raw_path).read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            continue
        if symbol not in contents:
            errors.append(f"anchor not found in file: {raw_path}::{symbol}")


def validate_residual_requirement(required_changes: str, residual: str, errors: list[str]) -> None:
    removes = re.search(
        r"\b(remove|delete|drop|prohibit|forbid|deprecat(?:e|ed)|no longer)\b",
        required_changes,
        re.IGNORECASE,
    )
    if removes and residual.strip() == "- none":
        errors.append("removal/prohibition changes require a Residual Check")


def metric(expected: str, name: str) -> int | None:
    match = re.search(rf"\b{re.escape(name)}:\s*(\d+)\b", expected, re.IGNORECASE)
    return int(match.group(1)) if match else None


def validate_test_expectation(
    metadata: dict[str, str],
    acceptance_entries: list[tuple[str, str]],
    errors: list[str],
) -> None:
    expectation = metadata.get("test_expectation")
    test_entries = [
        (command, expected)
        for command, expected in acceptance_entries
        if re.search(r"test|phpunit|playwright|jest|vitest", command, re.IGNORECASE)
    ]
    if expectation == "none":
        return
    if not test_entries:
        errors.append("red/green test_expectation requires a focused test command")
        return
    for command, expected in test_entries:
        passed = metric(expected, "Passed")
        failed = metric(expected, "Failed")
        skipped = metric(expected, "Skipped")
        if passed is None or failed is None or skipped is None:
            errors.append(f"test evidence requires exact Passed/Failed/Skipped counts: {command}")
            continue
        if skipped != 0:
            errors.append(f"gated test evidence requires Skipped: 0: {command}")
        if expectation == "green":
            if "Exit 0" not in expected or passed <= 0 or failed != 0:
                errors.append(f"green test evidence must be Exit 0 with Passed > 0 and Failed: 0: {command}")
        if expectation == "red":
            if not re.search(r"Exit (?:[1-9]\d*|non-zero)", expected) or failed <= 0:
                errors.append(f"red test evidence must be non-zero with Failed > 0: {command}")
            if "FAILURE-FINGERPRINT:" not in expected or "Test:" not in expected:
                errors.append(f"red test evidence requires Test and FAILURE-FINGERPRINT: {command}")


def validate_semantic_budget(
    metadata: dict[str, str],
    case_count: int,
    writable: dict[str, str],
    repo_root: Path,
    errors: list[str],
) -> None:
    profile = metadata.get("worker_profile", "local-8b")
    ceiling = SEMANTIC_CEILINGS.get(profile, SEMANTIC_CEILINGS["local-8b"])
    risk = metadata.get("risk")
    prefix = "STRONG_MODEL_REQUIRED" if risk == "high" else "SPLIT_REQUIRED"
    estimated_diff = nonnegative_integer(metadata, "estimated_diff_lines", errors)
    transitions = nonnegative_integer(metadata, "state_transition_count", errors)

    if case_count > ceiling["contract_cases"]:
        errors.append(f"{prefix}: contract cases exceed {profile} semantic ceiling")
    if estimated_diff > ceiling["estimated_diff_lines"]:
        errors.append(f"{prefix}: estimated diff exceeds {profile} semantic ceiling")
    if transitions > ceiling["state_transitions"]:
        errors.append(f"{prefix}: state transitions exceed {profile} semantic ceiling")

    for raw_path, action in writable.items():
        if action != "MODIFY":
            continue
        try:
            lines = len((repo_root / raw_path).read_text(encoding="utf-8").splitlines())
        except (OSError, UnicodeError):
            continue
        if lines > ceiling["target_file_lines"]:
            errors.append(f"{prefix}: target file exceeds {profile} line ceiling: {raw_path} ({lines})")

    if (
        profile in SMALL_PROFILE_CEILINGS
        and metadata.get("packet_type") == "migration"
        and (case_count > 1 or estimated_diff > 80)
    ):
        errors.append("STRONG_MODEL_REQUIRED: small-profile migration exceeds transcription-level work")


def parse_verification_scope(
    section: str,
    packet_type: str,
    writable: dict[str, str],
    read_only: set[str],
    repo_root: Path,
    errors: list[str],
) -> tuple[set[str], set[str]]:
    modified: set[str] = set()
    unchanged: set[str] = set()
    for line in section.splitlines():
        if not line.strip():
            continue
        match = re.fullmatch(r"- `(EXPECTED-MODIFIED|EXPECTED-UNCHANGED) ([^`]+)`", line)
        if not match:
            errors.append(f"invalid Verification Scope entry: {line}")
            continue
        kind, raw_path = match.groups()
        if kind == "EXPECTED-MODIFIED" and raw_path == "none" and packet_type == "review":
            continue
        if not safe_relative_path(raw_path):
            errors.append(f"unsafe Verification Scope path: {raw_path}")
            continue
        target = modified if kind == "EXPECTED-MODIFIED" else unchanged
        if raw_path in target:
            errors.append(f"duplicate Verification Scope path: {raw_path}")
        target.add(raw_path)

    if modified != writable:
        errors.append("EXPECTED-MODIFIED must equal Writable Files exactly")
    if not unchanged:
        errors.append("Verification Scope requires at least one EXPECTED-UNCHANGED file")
    missing_unchanged = read_only - unchanged
    if missing_unchanged:
        errors.append(
            "Read-Only Context missing from EXPECTED-UNCHANGED: "
            + ", ".join(sorted(missing_unchanged))
        )
    overlap = modified & unchanged
    if overlap:
        errors.append("Verification Scope modified/unchanged overlap: " + ", ".join(sorted(overlap)))
    for raw_path in unchanged:
        if not (repo_root / raw_path).is_file():
            errors.append(f"EXPECTED-UNCHANGED path is not an existing file: {raw_path}")
    return modified, unchanged


def validate_identifiers(section: str, required: set[str], label: str, errors: list[str]) -> None:
    found = set(re.findall(r"`([A-Z][A-Z0-9-]+)`", section))
    missing = required - found
    if missing:
        errors.append(f"missing {label}: " + ", ".join(sorted(missing)))


def estimate_context(
    paths: set[str], repo_root: Path, errors: list[str]
) -> tuple[int, int]:
    characters = 0
    for raw_path in sorted(paths):
        try:
            characters += len((repo_root / raw_path).read_text(encoding="utf-8"))
        except (OSError, UnicodeError) as exc:
            errors.append(f"cannot estimate context for {raw_path}: {exc}")
    return len(paths), math.ceil(characters / 4)


def validate_intent(intent: str, errors: list[str]) -> None:
    normalized = " ".join(intent.split())
    endings = re.findall(r"[.!?](?=\s|$)", normalized)
    if len(endings) != 1 or not normalized.endswith((".", "!", "?")):
        errors.append("Intent must contain exactly one complete sentence")
    if len(normalized) > 240:
        errors.append("Intent must be at most 240 characters")


def mechanical_value(section: str, key: str) -> str | None:
    match = re.search(rf"(?m)^- `{re.escape(key)}`: `([^`]+)`$", section)
    return match.group(1) if match else None


def validate_mechanical_input(
    metadata: dict[str, str],
    section: str,
    objective: str,
    read_only: str,
    writable: set[str],
    required_changes: str,
    checks: str,
    commands: list[str],
    repo_root: Path,
    errors: list[str],
) -> None:
    packet_type = metadata.get("packet_type")
    mode = metadata.get("execution_mode")

    if packet_type != "mechanical":
        if mode != "direct" or section.strip() != "- none":
            errors.append("non-mechanical packets require execution_mode: direct and Mechanical Input: - none")
        return

    for name in ("Objective", "Read-Only Context", "Required Changes"):
        text = required_changes if name == "Required Changes" else ""
        if name == "Objective":
            text = objective
        if name == "Read-Only Context":
            text = read_only
        for pattern in NON_MECHANICAL_DIRECTIVES:
            if re.search(pattern, text, re.IGNORECASE):
                errors.append(
                    f"STRONG_MODEL_REQUIRED: mechanical packet delegates discovery via {pattern!r} in {name}"
                )

    if mode == "generator":
        generator = mechanical_value(section, "GENERATOR-COMMAND")
        check = mechanical_value(section, "GENERATOR-CHECK")
        expected_hash = mechanical_value(section, "EXPECTED-SHA256")
        if not generator or not check or not expected_hash:
            errors.append("generator mode requires GENERATOR-COMMAND, GENERATOR-CHECK and EXPECTED-SHA256")
            return
        if not re.fullmatch(r"[a-f0-9]{64}", expected_hash):
            errors.append("GENERATOR EXPECTED-SHA256 must be lowercase SHA-256")
        output_match = re.search(r"(?:^|\s)--output=([^\s]+)", generator)
        check_match = re.search(r"(?:^|\s)--check=([^\s]+)", check)
        output_path = output_match.group(1) if output_match else ""
        check_path = check_match.group(1) if check_match else ""
        if not output_path or output_path != check_path:
            errors.append("generator/check commands must name the same --output/--check path")
        if set(writable) != {output_path}:
            errors.append("generator output path must equal Writable Files exactly")
        target = repo_root / output_path
        if writable.get(output_path) == "CREATE" and target.exists():
            if not target.is_file():
                errors.append(f"existing generator artifact is not a file: {output_path}")
            else:
                actual_hash = hashlib.sha256(target.read_bytes()).hexdigest()
                if actual_hash != expected_hash:
                    errors.append(
                        f"existing generator artifact hash mismatch: "
                        f"expected {expected_hash}, actual {actual_hash}"
                    )
        if generator not in required_changes:
            errors.append("Required Changes must contain the exact GENERATOR-COMMAND")
        if check not in commands:
            errors.append("Acceptance Checks must contain the exact GENERATOR-CHECK")
        if expected_hash not in checks:
            errors.append("Acceptance Checks must state the exact generator SHA-256")
        try:
            tokens = shlex.split(generator)
        except ValueError as exc:
            errors.append(f"invalid GENERATOR-COMMAND quoting: {exc}")
            tokens = []
        script_paths = [token for token in tokens if token.endswith((".php", ".py", ".sh"))]
        if not script_paths or not all((repo_root / path).is_file() for path in script_paths):
            errors.append("GENERATOR-COMMAND must reference an existing repository script")
        return

    if mode == "literal":
        artifact = mechanical_value(section, "LITERAL-ARTIFACT")
        expected_hash = mechanical_value(section, "LITERAL-SHA256")
        if not artifact or not expected_hash:
            errors.append("literal mode requires LITERAL-ARTIFACT and LITERAL-SHA256")
            return
        path = repo_root / artifact
        if not path.is_file():
            errors.append(f"LITERAL-ARTIFACT is missing: {artifact}")
        elif hashlib.sha256(path.read_bytes()).hexdigest() != expected_hash:
            errors.append("LITERAL-ARTIFACT hash mismatch")
        return

    errors.append("mechanical packets require execution_mode: generator or literal")


def validate_metadata(metadata: dict[str, str], errors: list[str]) -> None:
    if metadata.get("worker_profile") not in PROFILE_CEILINGS:
        errors.append(PROFILE_ERROR)
    if metadata.get("packet_type") not in PACKET_TYPES:
        errors.append("packet_type must be mechanical, review, refactor, test or migration")
    if metadata.get("execution_mode") not in EXECUTION_MODES:
        errors.append("execution_mode must be generator, literal or direct")
    if metadata.get("domain") not in DOMAINS:
        errors.append("domain must be one supported single-domain value")
    if metadata.get("risk") not in RISKS:
        errors.append("risk must be low, medium, high or critical")
    if metadata.get("review") not in REVIEWS:
        errors.append("review must be peer, strong or architect")
    if metadata.get("status") != "ready":
        errors.append("status must be ready")
    contract_version = metadata.get("contract_version")
    if contract_version is not None:
        if contract_version != "2":
            errors.append("contract_version must be 2 when present")
        if not re.fullmatch(r"[a-f0-9]{40}", metadata.get("base_revision", "")):
            errors.append("base_revision must be a lowercase 40-character Git SHA")
        nonnegative_integer(metadata, "estimated_diff_lines", errors)
        nonnegative_integer(metadata, "state_transition_count", errors)
        if metadata.get("test_expectation") not in {"red", "green", "none"}:
            errors.append("test_expectation must be red, green or none")
    task_id = metadata.get("task_id", "")
    if not re.fullmatch(r"[A-Z0-9][A-Z0-9._-]{2,63}", task_id):
        errors.append("task_id must be a stable uppercase identifier")


def validate_packet_type(
    metadata: dict[str, str],
    writable: set[str],
    commands: list[str],
    limits: dict[str, int],
    preconditions: str,
    errors: list[str],
) -> None:
    packet_type = metadata.get("packet_type")
    domain = metadata.get("domain")
    review = metadata.get("review")
    risk = metadata.get("risk")
    command_text = "\n".join(commands).lower()

    if packet_type == "test":
        if domain != "testing":
            errors.append("test packets require domain: testing")
        if not any(re.search(r"(?:^|/)(?:tests?|__tests__)(?:/|$)|(?:test|spec)\.", path, re.I) for path in writable):
            errors.append("test packets require a writable test/spec path")
        if not re.search(r"test|phpunit|playwright|jest|vitest", command_text):
            errors.append("test packets require a focused test command")

    if packet_type == "refactor":
        if review not in {"strong", "architect"}:
            errors.append("refactor packets require review: strong or architect")
        if not re.search(r"test|lint|type|check", command_text):
            errors.append("refactor packets require a behavior-preserving check")

    if packet_type == "migration":
        if domain != "database":
            errors.append("migration packets require domain: database")
        if risk != "high":
            errors.append("migration packets require risk: high")
        if review != "architect":
            errors.append("migration packets require review: architect")
        if limits.get("max_file_changes", 0) > 2:
            errors.append("SPLIT_REQUIRED: migration max_file_changes may not exceed 2")
        if not any("migration" in path.lower() for path in writable):
            errors.append("migration packets require a writable migration path")
        if "STRONG_REVIEW_REQUIRED" not in preconditions:
            errors.append("migration packets require STRONG_REVIEW_REQUIRED")


def main() -> int:
    args = parse_args()
    errors: list[str] = []
    try:
        text = read_packet(args.packet)
    except (OSError, UnicodeError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    metadata, body = parse_frontmatter(text, errors)
    contract_version = 2 if metadata.get("contract_version") == "2" else 1
    sections = parse_sections(body, errors, contract_version)
    computed_hash = scope_hash(sections, contract_version)

    if args.print_scope_hash:
        if errors:
            for error in errors:
                print(f"ERROR: {error}", file=sys.stderr)
            return 1
        print(computed_hash)
        return 0

    validate_metadata(metadata, errors)
    limits = parse_limits(metadata, errors)
    if not args.repo_root:
        errors.append("--repo-root is required for ready-packet validation")
        repo_root = Path.cwd()
    else:
        repo_root = args.repo_root.resolve()
        if not repo_root.is_dir():
            errors.append("--repo-root must be an existing directory")

    title = re.search(r"(?m)^# TASK ([A-Z0-9._-]+) — .+$", body)
    if not title or title.group(1) != metadata.get("task_id"):
        errors.append("TASK title must repeat task_id exactly")

    validate_intent(sections.get("Intent", ""), errors)
    lower_text = text.lower()
    for phrase in AMBIGUOUS_PHRASES:
        if phrase in lower_text:
            errors.append(f"ambiguous phrase is forbidden: {phrase}")

    declared_hash = metadata.get("scope_hash", "")
    if not re.fullmatch(r"[a-f0-9]{64}", declared_hash):
        errors.append("scope_hash must be a lowercase SHA-256")
    elif declared_hash != computed_hash:
        errors.append(f"scope_hash mismatch; expected {computed_hash}")

    packet_type = metadata.get("packet_type", "")
    writable = parse_writable_files(
        sections.get("Writable Files", ""),
        packet_type,
        repo_root,
        errors,
        allow_existing_create=args.result_mode
        or (
            packet_type == "mechanical"
            and metadata.get("execution_mode") == "generator"
        ),
    )
    read_only = parse_read_only(sections.get("Read-Only Context", ""), repo_root, errors)
    overlap = set(writable) & read_only
    if overlap:
        errors.append("files cannot be both writable and read-only: " + ", ".join(sorted(overlap)))

    ceilings = PROFILE_CEILINGS.get(metadata.get("worker_profile", ""), PROFILE_CEILINGS["local-8b"])
    parse_required_changes(
        sections.get("Required Changes", ""),
        packet_type,
        writable,
        ceilings["max_change_steps"],
        errors,
    )
    acceptance_entries = parse_check_entries(sections.get("Acceptance Checks", ""), errors)
    commands = [command for command, _ in acceptance_entries]
    residual_entries: list[tuple[str, str]] = []
    if contract_version == 2:
        residual_entries = parse_check_entries(
            sections.get("Residual Checks", ""),
            errors,
            allow_none=True,
            label="Residual Checks",
        )
    validate_mechanical_input(
        metadata,
        sections.get("Mechanical Input", ""),
        sections.get("Objective", ""),
        sections.get("Read-Only Context", ""),
        writable,
        sections.get("Required Changes", ""),
        sections.get("Acceptance Checks", ""),
        commands,
        repo_root,
        errors,
    )
    parse_verification_scope(
        sections.get("Verification Scope", ""),
        packet_type,
        set(writable),
        read_only,
        repo_root,
        errors,
    )
    validate_identifiers(
        sections.get("Forbidden Changes", ""), REQUIRED_FORBIDS, "forbidden-change IDs", errors
    )
    validate_identifiers(
        sections.get("Stop Conditions", ""), REQUIRED_STOPS, "stop-condition IDs", errors
    )

    if contract_version == 2:
        dependencies = parse_dependencies(sections.get("Depends On", ""), errors)
        case_ids = parse_contract_cases(sections.get("Contract Cases", ""), errors)
        semantic_text = "\n".join(
            sections.get(name, "")
            for name in ("Objective", "Writable Files", "Read-Only Context", "Required Changes")
        )
        validate_case_matrix(
            metadata,
            sections.get("Contract Cases", ""),
            semantic_text,
            errors,
        )
        validate_case_coverage(
            case_ids,
            sections.get("Required Changes", ""),
            sections.get("Acceptance Checks", ""),
            sections.get("Residual Checks", ""),
            errors,
            require_change=packet_type != "review",
        )
        validate_pinned_inputs(
            sections.get("Pinned Inputs", ""),
            metadata,
            writable,
            read_only,
            dependencies,
            repo_root,
            errors,
            allow_writable_drift=args.result_mode,
        )
        validate_literal_anchors(
            sections.get("Read-Only Context", ""),
            sections.get("Required Changes", ""),
            writable,
            repo_root,
            errors,
            allow_writable_drift=args.result_mode,
        )
        validate_residual_requirement(
            sections.get("Required Changes", ""),
            sections.get("Residual Checks", ""),
            errors,
        )
        validate_test_expectation(metadata, acceptance_entries, errors)
        if not args.result_mode:
            validate_semantic_budget(metadata, len(case_ids), writable, repo_root, errors)

    context_paths = {path for path, action in writable.items() if action == "MODIFY"} | read_only
    context_files, context_tokens = estimate_context(context_paths, repo_root, errors)
    if len(writable) > limits.get("max_file_changes", 0):
        errors.append("SPLIT_REQUIRED: writable files exceed max_file_changes")
    if len(commands) + len(residual_entries) > limits.get("max_commands", 0):
        errors.append("SPLIT_REQUIRED: Acceptance plus Residual Checks exceed max_commands")
    if context_files > limits.get("max_context_files", 0):
        errors.append("SPLIT_REQUIRED: context files exceed max_context_files")
    if context_tokens > limits.get("max_context_tokens", 0):
        errors.append(
            f"SPLIT_REQUIRED: estimated context tokens {context_tokens} exceed "
            f"max_context_tokens {limits.get('max_context_tokens', 0)}"
        )

    risk = metadata.get("risk")
    review = metadata.get("review")
    preconditions = sections.get("Preconditions", "")
    if risk == "critical":
        errors.append("STRONG_MODEL_REQUIRED: critical packets cannot be ready for a weak-worker profile")
    if risk == "high":
        if "STRONG_REVIEW_REQUIRED" not in preconditions:
            errors.append("high-risk packets require STRONG_REVIEW_REQUIRED")
        if review not in {"strong", "architect"}:
            errors.append("high-risk packets require review: strong or architect")

    risky_writable = [
        path
        for path in writable
        if any(part in path.lower() for part in HIGH_RISK_PATHS)
    ]
    if risky_writable and risk not in {"high", "critical"}:
        errors.append(
            "high-risk writable paths require risk: high or critical: "
            + ", ".join(risky_writable)
        )

    validate_packet_type(metadata, set(writable), commands, limits, preconditions, errors)

    report = sections.get("Report Contract", "")
    required_report_fields = [
        "Status: DONE | BLOCKED | NEEDS_CONTEXT",
        "Scope hash:",
        "Files changed:",
        "Files unchanged:",
        "Checks:",
        "Commands run:",
        "Runtime minutes:",
        "Review required:",
        "Concerns:",
    ]
    if contract_version == 2:
        required_report_fields.extend(
            [
                "Base revision:",
                "Contract cases:",
                "Residual checks:",
            ]
        )
    for required in required_report_fields:
        if required not in report:
            errors.append(f"Report Contract missing: {required}")

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    print(
        f"PASS {metadata['task_id']}: type={metadata['packet_type']}; "
        f"domain={metadata['domain']}; risk={metadata['risk']}; review={metadata['review']}; "
        f"files={len(writable)}/{limits['max_file_changes']}; "
        f"commands={len(commands)}/{limits['max_commands']}; "
        f"context≈{context_tokens}/{limits['max_context_tokens']} tokens; "
        f"scope={computed_hash}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
