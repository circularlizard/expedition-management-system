#!/usr/bin/env python3
"""
Compare the definitive Tutor LMS export with the EMS-generated training report.

Tutor LMS  — long format: one row per student-course enrollment (course_progress as %).
EMS report — wide format: one row per student, columns = courses, values = status.

The script pivots Tutor LMS to wide format, normalises both, then reports:
  1. Students in one source but not the other
  2. Per-student, per-course status mismatches
"""

import csv
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def tutor_progress_to_status(pct: str) -> str:
    """Map Tutor LMS percentage string to EMS status label."""
    pct = pct.strip().rstrip("%")
    try:
        val = int(pct)
    except ValueError:
        return "Unknown"
    if val >= 100:
        return "Complete"
    # 0% means enrolled but hasn't started any content → "In Progress" in EMS terms
    if val > 0:
        return "In Progress"
    return "In Progress"


def normalise_display_name(name: str) -> str:
    """Lowercase and strip for matching."""
    return name.strip().lower()


def normalise_email(email: str) -> str:
    return email.strip().lower().strip('"')


# ---------------------------------------------------------------------------
# Parse Tutor LMS (long → wide)
# ---------------------------------------------------------------------------
def parse_tutor_lms(path: Path) -> dict:
    """
    Returns {email_lower: {
        "name": display_name,
        "email": email,
        "courses": {course_name: status},
    }}
    """
    students: dict[str, dict] = {}

    with open(path, newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        for row in reader:
            email = normalise_email(row["email"])
            name = row["display_name"].strip().strip('"')
            course = row["enrolled_course"].strip().strip('"')
            status = tutor_progress_to_status(row["course_progress"])

            if email not in students:
                students[email] = {
                    "name": name,
                    "email": email,
                    "courses": {},
                }
            students[email]["courses"][course] = status

    return students


# ---------------------------------------------------------------------------
# Parse EMS report (wide)
# ---------------------------------------------------------------------------
def parse_ems_report(path: Path) -> dict:
    """
    Returns {email_lower: {
        "name": student_name,
        "email": email,
        "courses": {course_name: status},
    }}
    """
    students: dict[str, dict] = {}

    with open(path, newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        fieldnames = reader.fieldnames or []
        for row in reader:
            name = (row.get("Student Name") or "").strip().strip('"')
            email = normalise_email(row.get("Email") or "")

            courses = {}
            for field in fieldnames:
                if field in ("Student Name", "Email"):
                    continue
                courses[field.strip().strip('"')] = (row[field] or "").strip().strip('"')

            students[email] = {
                "name": name,
                "email": email,
                "courses": courses,
            }

    return students


# ---------------------------------------------------------------------------
# Comparison
# ---------------------------------------------------------------------------
def compare(tutor: dict, ems: dict, tutor_file: Path, ems_file: Path):
    tutor_emails = set(tutor.keys())
    ems_emails = set(ems.keys())

    only_tutor = tutor_emails - ems_emails
    only_ems = ems_emails - tutor_emails
    common = tutor_emails & ems_emails

    print("=" * 80)
    print("TUTOR LMS ↔ EMS TRAINING REPORT COMPARISON")
    print("=" * 80)
    print(f"  Tutor LMS file : {tutor_file.name}")
    print(f"  EMS report file: {ems_file.name}")
    print(f"  Tutor LMS students : {len(tutor_emails)}")
    print(f"  EMS students       : {len(ems_emails)}")
    print()

    # --- Students only in one source ---
    mismatches = 0

    if only_tutor:
        print(f"⚠  {len(only_tutor)} student(s) ONLY in Tutor LMS:")
        for e in sorted(only_tutor):
            print(f"      {e}  ({tutor[e]['name']})")
        print()

    if only_ems:
        print(f"⚠  {len(only_ems)} student(s) ONLY in EMS report:")
        for e in sorted(only_ems):
            print(f"      {e}  ({ems[e]['name']})")
        print()

    # --- Name mismatches for common students ---
    name_diffs = []
    for e in sorted(common):
        if tutor[e]["name"] != ems[e]["name"]:
            name_diffs.append((e, tutor[e]["name"], ems[e]["name"]))

    if name_diffs:
        print(f"⚠  {len(name_diffs)} student(s) with DIFFERENT names:")
        for email, t_name, e_name in name_diffs:
            print(f"      {email}")
            print(f"        Tutor : {t_name}")
            print(f"        EMS   : {e_name}")
        print()

    # --- Course-level mismatches ---
    all_courses = set()
    for e in common:
        all_courses |= tutor[e]["courses"].keys()
        all_courses |= ems[e]["courses"].keys()

    course_mismatches: list[tuple[str, str, str, str, str]] = []  # (email, course, tutor_status, ems_status, name)

    for email in sorted(common):
        for course in sorted(all_courses):
            t_status = tutor[email]["courses"].get(course, "Not Enrolled")
            e_status = ems[email]["courses"].get(course, "Not Enrolled")
            if t_status != e_status:
                course_mismatches.append((email, course, t_status, e_status, tutor[email]["name"]))
                mismatches += 1

    if course_mismatches:
        print(f"⚠  {mismatches} course-level mismatch(es) found:")
        print()
        print(f"  {'Student':<30} {'Email':<35} {'Course':<45} {'Tutor':<12} {'EMS':<12}")
        print(f"  {'-'*28:<30} {'-'*33:<35} {'-'*43:<45} {'-'*10:<12} {'-'*10:<12}")
        for email, course, t_status, e_status, name in course_mismatches:
            short_name = (name or email)[:28]
            short_email = email[:33]
            short_course = course[:43]
            print(f"  {short_name:<30} {short_email:<35} {short_course:<45} {t_status:<12} {e_status:<12}")
    else:
        print("✓  No course-level mismatches — all records match.")

    print()
    print("=" * 80)
    summary = f"  Summary: {mismatches} mismatch(es), {len(only_tutor)} only-Tutor, {len(only_ems)} only-EMS"
    print(summary)
    print("=" * 80)

    return mismatches + len(only_tutor) + len(only_ems)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    if len(sys.argv) != 3:
        print("Usage: compare-training-reports.py <tutor-lms.csv> <ems-report.csv>", file=sys.stderr)
        sys.exit(1)

    tutor_file = Path(sys.argv[1])
    ems_file = Path(sys.argv[2])

    if not tutor_file.exists():
        print(f"ERROR: Tutor LMS file not found: {tutor_file}", file=sys.stderr)
        sys.exit(1)

    if not ems_file.exists():
        print(f"ERROR: EMS report file not found: {ems_file}", file=sys.stderr)
        sys.exit(1)

    tutor = parse_tutor_lms(tutor_file)
    ems = parse_ems_report(ems_file)

    exit_code = compare(tutor, ems, tutor_file, ems_file)
    sys.exit(1 if exit_code else 0)


if __name__ == "__main__":
    main()
