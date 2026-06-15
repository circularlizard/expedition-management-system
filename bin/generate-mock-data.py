#!/usr/bin/env python3
"""
Generate anonymised mock data for EMS tests.

Reads real API responses from mockdata/ and writes anonymised JSON to tests/mocks/.
All transformations are deterministic: random.seed(42) is set once at startup.

Usage:
    python3 bin/generate-mock-data.py

Safe to re-run: overwrites existing output files.
"""

import json
import os
import random
import re
import sys
from copy import deepcopy
from pathlib import Path

random.seed(42)

REPO_ROOT   = Path(__file__).parent.parent
MOCKDATA    = REPO_ROOT / "mockdata"
TESTS_MOCKS = REPO_ROOT / "tests" / "mocks"

MOCK_SECTION_ID  = 99001
MOCK_SECTION_ID2 = 99002
MOCK_EXTRAID     = 99848
MOCK_TERM_ID     = 5001

# ── Name pools ──────────────────────────────────────────────────────────────

FIRST_NAMES = [
    "Alasdair","Ailsa","Angus","Beira","Bonnie","Brodie","Catriona","Callum",
    "Ceilidh","Colin","Dougal","Eilidh","Euan","Fiona","Fergus","Finlay",
    "Gillian","Gordon","Hamish","Heather","Iain","Isla","Jamie","Kirsty",
    "Lachlan","Lauren","Lewis","Lorna","Magnus","Mairi","Malcolm","Morag",
    "Murray","Niall","Oonagh","Rory","Ross","Seamus","Seonaid","Siobhan",
    "Stewart","Struan","Tamsin","Torquil","Una","Wallace","Wilma","Yvonne",
    "Zsuzsa","Blair","Bram","Craig","Darren","Elspeth","Fionnuala","Glen",
    "Greer","Innes","Katrina","Kenneth","Lynsey","Morvern","Nairn","Orla",
    "Pàdraig","Rhona","Ruaridh","Sorcha","Tara","Uisdean","Vairi","Wendy",
]

LAST_NAMES = [
    "Anderson","Baird","Buchanan","Cameron","Campbell","Davidson","Douglas",
    "Duncan","Dunbar","Ferguson","Forbes","Fraser","Gillies","Gordon","Graham",
    "Grant","Hamilton","Henderson","Hunter","Innes","Johnston","Kennedy","Kerr",
    "Lamont","Lennox","Lindsay","Logan","MacDonald","MacKenzie","MacLeod",
    "MacPherson","Maxwell","McAllister","McCallum","McClure","McCrae","McGill",
    "McGregor","McKay","McKenna","McLean","McMillan","Millar","Mitchell","Munro",
    "Murray","Nairn","Paterson","Reid","Robertson","Ross","Scott","Simpson",
    "Sinclair","Smith","Stewart","Sutherland","Thomson","Wallace","Watson","Wilson",
]

PATROL_NAMES = ["Braid", "Edge", "Wildfire", "Castle", "Summit", "Ridge", "Glen", "Cairn"]

# ── Helpers ──────────────────────────────────────────────────────────────────

def load(path: Path) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)

def save(path: Path, data) -> None:
    TESTS_MOCKS.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=4, ensure_ascii=False)
    print(f"  wrote {path.relative_to(REPO_ROOT)}")

def pick_name(used: set) -> tuple[str, str]:
    """Return a unique (first, last) pair not already in `used`."""
    attempts = 0
    while True:
        fn = random.choice(FIRST_NAMES)
        ln = random.choice(LAST_NAMES)
        key = f"{fn} {ln}"
        if key not in used:
            used.add(key)
            return fn, ln
        attempts += 1
        if attempts > 10000:
            raise RuntimeError("Name pool exhausted")

# ── Build the master member list ─────────────────────────────────────────────

def build_member_map() -> list[dict]:
    """
    Load real getListOfMembers.json, remap to mock IDs and anonymised names,
    keep patrol structure intact (patrol IDs remapped to mock range 99200+).
    Returns list of member dicts with keys:
        scout_id, first_name, last_name, patrol, patrol_id, dob, age
    """
    raw = load(MOCKDATA / "getListOfMembers.json")
    items = raw.get("items", [])

    # Build real_patrol_id → mock_patrol_id mapping
    real_patrol_ids = sorted({int(m.get("patrolid", 0)) for m in items if int(m.get("patrolid", 0)) > 0})
    patrol_id_map: dict[int, int] = {}
    for i, rpid in enumerate(real_patrol_ids):
        patrol_id_map[rpid] = 99200 + i

    # Build real_scout_id → mock_scout_id mapping (keep original order, assign 3417257+)
    # Start at 3417257 so that existing tests pinned to that first ID still pass
    real_scout_ids = [int(m["scoutid"]) for m in items]
    scout_id_map: dict[int, int] = {}
    for i, rsid in enumerate(real_scout_ids):
        scout_id_map[rsid] = 3417257 + i

    # Assign patrol names from PATROL_NAMES pool, consistently per patrol_id
    patrol_name_map: dict[int, str] = {}
    patrol_pool = PATROL_NAMES[:]
    random.shuffle(patrol_pool)
    for i, mock_pid in enumerate(sorted(patrol_id_map.values())):
        patrol_name_map[mock_pid] = patrol_pool[i % len(patrol_pool)]

    used_names: set = set()
    members = []
    for m in items:
        real_sid = int(m["scoutid"])
        real_pid = int(m.get("patrolid", 0))
        mock_sid = scout_id_map[real_sid]
        mock_pid = patrol_id_map.get(real_pid, 0)
        patrol_name = patrol_name_map.get(mock_pid, "Unassigned")
        fn, ln = pick_name(used_names)
        members.append({
            "scout_id":  mock_sid,
            "first_name": fn,
            "last_name":  ln,
            "patrol":    patrol_name,
            "patrol_id": mock_pid,
            "dob":       m.get("dob", "2007-01-01"),
            "age":       m.get("age", "17 / 00"),
        })

    return members, scout_id_map, patrol_id_map, patrol_name_map

# ── 1. osm-list-of-members.json ───────────────────────────────────────────────

def gen_list_of_members(members: list[dict]) -> None:
    raw = load(MOCKDATA / "getListOfMembers.json")
    template_items = raw.get("data", {}).get("items", [])

    items_out = []
    for i, m in enumerate(members):
        tmpl = template_items[i] if i < len(template_items) else {}
        item = deepcopy(tmpl)
        item["scoutid"]   = str(m["scout_id"])
        item["firstname"] = m["first_name"]
        item["lastname"]  = m["last_name"]
        item["patrol"]    = m["patrol"]
        item["patrolid"]  = str(m["patrol_id"])
        item["sectionid"] = MOCK_SECTION_ID
        item["dob"]       = m["dob"]
        item["age"]       = m["age"]
        item["_filterString"] = f"{m['first_name'].lower()} {m['last_name'].lower()}"
        items_out.append(item)

    out = deepcopy(raw)
    out["items"] = items_out
    save(TESTS_MOCKS / "osm-list-of-members.json", out)

# ── 2. osm-member-detail.json (keyed map) ────────────────────────────────────

def gen_member_detail(members: list[dict]) -> None:
    detail_map = {}
    for m in members:
        sid = m["scout_id"]
        detail_map[str(sid)] = {
            "email":        f"scout.{sid}@example-ems.test",
            "parent_email": f"parent.{sid}@example-ems.test",
        }
    save(TESTS_MOCKS / "osm-member-detail.json", detail_map)

# ── 3. osm-patrols.json ───────────────────────────────────────────────────────

def gen_patrols(patrol_id_map: dict[int, int], patrol_name_map: dict[int, str]) -> None:
    patrols = [
        {
            "patrolid":     str(-2),
            "sectionid":    str(MOCK_SECTION_ID),
            "name":         "Leaders",
            "active":       "1",
            "points":       "0",
            "census_costs": False,
        }
    ]
    for mock_pid in sorted(patrol_name_map.keys()):
        patrols.append({
            "patrolid":     str(mock_pid),
            "sectionid":    str(MOCK_SECTION_ID),
            "name":         patrol_name_map[mock_pid],
            "active":       "1",
            "points":       "0",
            "census_costs": False,
        })
    save(TESTS_MOCKS / "osm-patrols.json", {"patrols": patrols})

# ── 4. osm-events.json ────────────────────────────────────────────────────────

def gen_events() -> list[int]:
    """Returns list of mock event IDs generated. Caps to 2 items for test assertions."""
    raw = load(MOCKDATA / "getEventList.json")
    items = raw.get("items", [])[:2]  # tests assert count == 2
    event_id_map: dict[str, int] = {}
    out_items = []
    for i, ev in enumerate(items):
        mock_eid = 40001 + i
        event_id_map[ev["eventid"]] = mock_eid
        item = deepcopy(ev)
        item["eventid"] = str(mock_eid)
        out_items.append(item)

    out = deepcopy(raw)
    out["items"] = out_items
    save(TESTS_MOCKS / "osm-events.json", out)
    return list(event_id_map.values())

# ── 5. osm-event-attendance.json ─────────────────────────────────────────────

def gen_event_attendance(members: list[dict], event_ids: list[int]) -> None:
    """
    One attendance file covers a representative event (first event_id).
    Varies attending values across members for entropy.
    """
    attending_options = ["yes", "no", ""]
    weights           = [0.45, 0.15, 0.40]

    data_rows = []
    for m in members:
        attending = random.choices(attending_options, weights=weights)[0]
        details: dict | list = []
        if attending == "yes" and random.random() < 0.4:
            details = {"f_1": f"mock-team-{random.randint(1,6)}"}
        data_rows.append({
            "member_id":     m["scout_id"],
            "patrol_id":     m["patrol_id"],
            "first_name":    m["first_name"],
            "last_name":     m["last_name"],
            "_filterString": f"{m['first_name'].lower()} {m['last_name'].lower()}",
            "photo_guid":    None,
            "attending":     attending,
            "emailable":     True,
            "details":       details,
            "age_at_start":  m["age"],
        })

    out = {
        "status": True,
        "error":  None,
        "data":   data_rows,
    }
    save(TESTS_MOCKS / "osm-event-attendance.json", out)

# ── 6. osm-flexi-record-structure.json ────────────────────────────────────────

def gen_flexi_structure() -> None:
    raw = load(MOCKDATA / "getStructure.json")
    out = deepcopy(raw)
    out["extraid"]   = str(MOCK_EXTRAID)
    out["sectionid"] = str(MOCK_SECTION_ID)
    save(TESTS_MOCKS / "osm-flexi-record-structure.json", out)

# ── 7. osm-flexi-record-data.json ─────────────────────────────────────────────

PRACTICE_GROUPS  = ["HGP1-1","HGP1-2","HGP1-3","HGP1-4","HSP1-1","HSP1-2","HSP1-3",""]
QUALIFIER_GROUPS = ["HGQ1-1","HGQ1-2","HGQ1-3","HGQ2-1","HSQ1-1","HSQ1-2","HSQ2-1",""]
FIRST_AID_OPTS   = ["FIRST RESPONSE","OUTDOOR FIRST AID","",""]
TRAINING_DAYS    = ["TD1","TD2","TD3",""]

def gen_flexi_data(members: list[dict]) -> None:
    raw = load(MOCKDATA / "getData.json")

    items_out = []
    for m in members:
        pg  = random.choice(PRACTICE_GROUPS)
        qg  = random.choice(QUALIFIER_GROUPS)
        fa  = random.choice(FIRST_AID_OPTS)
        td  = random.choice(TRAINING_DAYS)
        items_out.append({
            "scoutid":        str(m["scout_id"]),
            "firstname":      m["first_name"],
            "lastname":       m["last_name"],
            "dob":            m["dob"],
            "photo_guid":     None,
            "patrolid":       str(m["patrol_id"]),
            "total":          0,
            "completed":      "",
            "f_9":  pg,
            "f_10": f"{pg} Y" if pg and random.random() > 0.3 else "",
            "f_11": f"{qg} Y" if qg and random.random() > 0.4 else "",
            "f_12": qg,
            "f_13": fa,
            "f_14": "",
            "f_15": "",
            "f_16": "",
            "f_17": "",
            "f_18": td,
            "age":            m["age"],
            "patrol":         m["patrol"],
            "_filterString":  f"{m['first_name'].lower()} {m['last_name'].lower()}",
        })

    out = deepcopy(raw)
    out["items"] = items_out
    out["identifier"] = "scoutid"
    save(TESTS_MOCKS / "osm-flexi-record-data.json", out)

# ── 8. osm-get-data-payload-explorer.json & -parent.json ────────────────────

def gen_data_payload(members: list[dict]) -> None:
    real = load(MOCKDATA / "getDataPayload.json")

    # Build a scrubbed globals block from the real one
    g = deepcopy(real.get("data", {}).get("globals", {}))

    # Scrub PII fields
    g["email"]     = "admin.mock@example-ems.test"
    g["firstname"] = "Mock"
    g["lastname"]  = "Admin"
    g["fullname"]  = "Mock Admin"
    g["userid"]    = "99000"
    g["session_id"]       = "mock-session-id"
    g["user_socket"]      = "mock-user-socket"
    g["user_hash"]        = "mock-user-hash"
    g["user_hash_legacy"] = "mock-user-hash-legacy"

    # Remap section IDs in terms
    real_terms = real.get("data", {}).get("globals", {}).get("terms", {})
    real_section_ids = list(real_terms.keys())
    mock_terms: dict = {}
    mock_section_map = {}
    for i, rsid in enumerate(real_section_ids[:2]):  # we only need 2 mock sections
        msid = MOCK_SECTION_ID if i == 0 else MOCK_SECTION_ID2
        mock_section_map[rsid] = msid
        real_term_list = real_terms[rsid]
        mock_term_list = []
        for j, t in enumerate(real_term_list[:1]):  # keep one term per section
            mt = deepcopy(t)
            mt["termid"]    = str(MOCK_TERM_ID + i)
            mt["sectionid"] = str(msid)
            mt["name"]      = "Spring 2026"
            mt["startdate"] = "2026-01-01"
            mt["enddate"]   = "2026-12-31"
            mock_term_list.append(mt)
        mock_terms[str(msid)] = mock_term_list

    g["terms"] = mock_terms

    # Build a minimal but structurally correct roles block
    g["roles"] = [
        {
            "sectionid": str(MOCK_SECTION_ID),
            "section":   "Explorers",
            "groupname": "Mock Scout Group",
            "roleclass": "Section",
            "rolename":  "Section Leader",
        }
    ]

    # Build member_access in the structure OSM_Parser expects:
    # member_access[section_id]["members"][scout_id] = {access_type, first_name, last_name}
    g["member_access"] = {}  # will be overridden per variant below

    # Build output data block
    base = deepcopy(real.get("data", {}))
    base["globals"] = g
    base.pop("sections", None)  # sections block not used by our parser

    # Explorer variant — userid 20001, member_access with scout_id 30001 in two sections
    g_exp = deepcopy(g)
    g_exp["userid"]               = "20001"
    g_exp["active_user_member"]   = "0"
    g_exp["associated_member_id"] = "0"
    g_exp["member_access"] = {
        str(MOCK_SECTION_ID): {
            "members": {
                "30001": {"access_type": "member", "first_name": "Mock", "last_name": "Explorer"},
            }
        },
        str(MOCK_SECTION_ID2): {
            "members": {
                "30001": {"access_type": "member", "first_name": "Mock", "last_name": "Explorer"},
            }
        },
    }
    base_exp = deepcopy(base)
    base_exp["globals"] = g_exp
    save(TESTS_MOCKS / "osm-get-data-payload-explorer.json", {"data": base_exp})

    # Parent variant — userid 20002, two children (30001 in both sections, 30002 in section 1)
    g_par = deepcopy(g)
    g_par["userid"]               = "20002"
    g_par["active_user_member"]   = "1"
    g_par["associated_member_id"] = "30001"
    g_par["email"]     = "parent.mock@example-ems.test"
    g_par["firstname"] = "Parent"
    g_par["lastname"]  = "Mock"
    g_par["fullname"]  = "Parent Mock"
    g_par["member_access"] = {
        str(MOCK_SECTION_ID): {
            "members": {
                "30001": {"access_type": "parent", "first_name": "Child", "last_name": "One"},
                "30002": {"access_type": "parent", "first_name": "Child", "last_name": "Two"},
            }
        },
        str(MOCK_SECTION_ID2): {
            "members": {
                "30001": {"access_type": "parent", "first_name": "Child", "last_name": "One"},
            }
        },
    }
    base_par = deepcopy(base)
    base_par["globals"] = g_par
    save(TESTS_MOCKS / "osm-get-data-payload-parent.json", {"data": base_par})

# ── Main ─────────────────────────────────────────────────────────────────────

def main() -> None:
    print("==> Generating anonymised mock data (seed=42)...")

    if not MOCKDATA.is_dir():
        print(f"ERROR: mockdata/ not found at {MOCKDATA}", file=sys.stderr)
        sys.exit(1)

    members, scout_id_map, patrol_id_map, patrol_name_map = build_member_map()
    print(f"  member pool: {len(members)} members, {len(patrol_id_map)} patrols")

    gen_list_of_members(members)
    gen_member_detail(members)
    gen_patrols(patrol_id_map, patrol_name_map)
    event_ids = gen_events()
    gen_event_attendance(members, event_ids)
    gen_flexi_structure()
    gen_flexi_data(members)
    gen_data_payload(members)

    print(f"\n==> Done. {8} files written to tests/mocks/")
    print("    Run: vendor/bin/phpunit to verify tests still pass.")

if __name__ == "__main__":
    main()
