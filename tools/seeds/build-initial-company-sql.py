#!/usr/bin/env python3
"""Build a private-only MySQL fresh-install fixture from pinned source snapshots.

This is a build step, NOT an SQL execution, runtime test or verification of recruitment.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
SOURCES = {
    "database/seeds_company_2026-11-18.json": "e274f9df5739d813c46089e866cf561815078c3ff45d93fda22affcbe54559d1",
    "docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json": "21bc170942217e30308e166892b4b437c63ae7041eaf12d5368c8733434e1484",
    "docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json": "e83f38320148dc283bce935d9321d3df72bcbea0b85f3f38b4cf2aed3f180cc1",
}
SQL_PATH = ROOT / "database/initial_company_data.sql"
MANIFEST_PATH = ROOT / "database/initial_company_data_manifest.json"


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(value) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def literal(value) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, int):
        return str(value)
    if not isinstance(value, str):
        raise TypeError("Non-scalar SQL value")
    return "CONVERT(0x" + value.encode("utf-8").hex() + " USING utf8mb4)"


def row(table: str, fields: dict[str, object]) -> str:
    assert re.fullmatch(r"[a-z_]+", table)
    assert all(re.fullmatch(r"[a-z_][a-z0-9_]*", key) for key in fields)
    return "INSERT INTO `" + table + "` (" + ",".join("`" + k + "`" for k in fields) + ") VALUES (" + ",".join(literal(v) for v in fields.values()) + ");"


def id32(material: str) -> str:
    return sha(material.encode("utf-8"))[:32]


def load_json(path: str, expected_sha: str):
    data = (ROOT / path).read_bytes()
    if sha(data) != expected_sha:
        raise ValueError("Pinned input changed; require review before regeneration: " + path)
    return json.loads(data)


def main():
    inputs = {path: load_json(path, digest) for path, digest in SOURCES.items()}
    seeds = inputs["database/seeds_company_2026-11-18.json"]
    feed = inputs["docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json"]
    links = inputs["docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json"]
    if seeds.get("publication_rule") != "initial_drafts_only; independent source review required" or len(seeds.get("companies", [])) != 10:
        raise ValueError("Unexpected seed source and permissions")
    if feed.get("research_version") != "d1.0.1" or len(feed.get("records", [])) != 27 or feed.get("site_published") or feed.get("site_db_written"):
        raise ValueError("Unexpected research release")
    if links.get("version") != "d1.0.2" or len(links.get("companies", [])) != 59:
        raise ValueError("Unexpected link catalogue")
    link_count = sum(len(urls) for entry in links["companies"] for urls in entry["links"].values())
    if link_count != 141:
        raise ValueError("Unexpected URL catalogue count")
    statements = []
    seen = set()
    location_count = 0
    for company in seeds["companies"]:
        key = company["key"]
        if not re.fullmatch(r"[a-z0-9-]{2,50}", key) or key in seen:
            raise ValueError("Invalid or repeated seed key")
        seen.add(key)
        seed_key = "kmm-research-" + key
        company_id = id32(seed_key)
        name = company["display_name"].strip()
        note = company["note"].strip()
        if not (0 < len(name.encode()) <= 240 and len(note.encode()) <= 6000):
            raise ValueError("Oversize company seed")
        statements.append(row("companies", {"id": company_id, "display_name": name, "description": note, "visibility": "draft", "seed_key": seed_key}))
        statements.append(row("project_seed_records", {"seed_key": seed_key, "company_id": company_id, "source_document": seeds["source_document"], "source_checked_on": seeds["source_checked_on"], "seed_version": seeds["seed_version"]}))
        base = {"display_name": name, "legal_name": None, "description": note, "website_url": None}
        statements.append(row("seed_record_states", {"seed_key": seed_key, "company_id": company_id, "base_json": canonical(base), "seed_version": seeds["seed_version"], "source_document": seeds["source_document"], "source_checked_on": seeds["source_checked_on"]}))
        for num, loc in enumerate(company.get("locations", []), 1):
            if loc.get("verification_state") not in ("candidate", "unconfirmed", "private"):
                raise ValueError("Unreviewed location may not be verified")
            statements.append(row("company_locations", {"id": id32(seed_key + ":location:" + str(num)), "company_id": company_id, "name": loc["name"], "kind": loc["kind"], "address": loc.get("address"), "verification_state": loc["verification_state"], "note": loc.get("note")}))
            location_count += 1
        statements.append(row("business_change_events", {"actor_id": None, "record_type": "companies", "record_id": company_id, "operation": "seed_draft_added", "after_json": canonical({"seed_key": seed_key, "snapshot_date": seeds["source_checked_on"]})}))

    feed_batch = id32("initial-company:research-feed:" + SOURCES["docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json"])
    feed_digest = sha((ROOT / "docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json").read_bytes())
    statements.append(row("research_import_batches", {"id": feed_batch, "event_key": "initial:research:d1.0.1:held", "payload_digest": feed_digest, "research_version": "d1.0.1", "actor_id": "@INITIAL_ACTOR@", "status": "held_initial_setup", "record_count": 27, "bound_count": 0}).replace(literal("@INITIAL_ACTOR@"), "@kmm_initial_actor_id"))
    research_ids = set()
    for source in feed["records"]:
        rid = source["review_id"]
        if rid in research_ids or not re.fullmatch(r"r-[a-f0-9]{24}", rid):
            raise ValueError("Duplicate or invalid research review ID")
        research_ids.add(rid)
        payload = canonical(source["source_record"])
        if sha(payload.encode("utf-8")) != source["source_record_sha256"]:
            raise ValueError("Source record integrity mismatch")
        if source["site_identity_mapping_status"] != "UNRESOLVED":
            raise ValueError("Identity unexpectedly approved")
        statements.append(row("research_import_items", {"batch_id": feed_batch, "review_id": rid, "private_stage_id": id32("initial-company:feed:" + rid), "source_record_sha256": source["source_record_sha256"], "priority": source["priority"], "record_status": source["site_data_status"], "source_record_json": payload, "binding_json": "null"}))

    links_batch = id32("initial-company:links:" + SOURCES["docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json"])
    statements.append(row("research_import_batches", {"id": links_batch, "event_key": "initial:links:d1.0.2:held", "payload_digest": SOURCES["docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json"], "research_version": "d1.0.2", "actor_id": "@INITIAL_ACTOR@", "status": "held_initial_setup", "record_count": 59, "bound_count": 0}).replace(literal("@INITIAL_ACTOR@"), "@kmm_initial_actor_id"))
    for idx, entry in enumerate(links["companies"], 1):
        name = entry["company"]
        rid = "r-" + sha(f"{idx}:{name}".encode("utf-8"))[:24]
        payload = canonical(entry)
        statements.append(row("research_import_items", {"batch_id": links_batch, "review_id": rid, "private_stage_id": id32("initial-company:links:" + rid), "source_record_sha256": sha(payload.encode("utf-8")), "priority": "LINK", "record_status": "UNAPPROVED_LINKS", "source_record_json": payload, "binding_json": "null"}))

    header = [
        "-- Initial Setup ONLY: non-public seed corpus, source-pinned, no verified company/job IDs.",
        "-- This file is run by InitialCompanySql inside the first-install DB transaction.",
        "-- Do not execute manually or against an existing installation.",
        "-- Requires session variable @kmm_initial_actor_id set to the first DEVELOPER's numeric user ID.",
        "-- 10 private draft companies, 2 candidate locations, 27 private research assessments, 59 private link entries / 141 URLs.",
        "-- No publication, jobs, overlay changes, notification outbox, or source alterations.",
    ]
    sql = "\n".join(header + statements) + "\n"
    SQL_PATH.write_text(sql, encoding="utf-8", newline="\n")
    manifest = {
        "format": "kmm-initial-company-sql-v1", "schema": 41,
        "policy": "initial_install_only; private_drafts_and_held_research; no_auto_publication_or_notification",
        "sql_file": "database/initial_company_data.sql", "sql_sha256": sha(sql.encode("utf-8")),
        "statement_count": len(statements),
        "counts": {"draft_companies": 10, "candidate_locations": location_count, "research_held": 27, "link_held": 59, "link_urls": 141, "formal_company_id_approvals": 0},
        "pinned_sources": SOURCES,
        "external_real_mysql_status": "NOT_RUN", "external_publication_approval_status": "NOT_RUN",
    }
    MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("Created SQL:", SQL_PATH.relative_to(ROOT), len(statements), "INSERT statements,", len(sql.encode()), "bytes")
    print("Manifest:", MANIFEST_PATH.relative_to(ROOT))

if __name__ == "__main__":
    main()
