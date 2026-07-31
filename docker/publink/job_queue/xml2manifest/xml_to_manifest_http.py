"""
One-pass JATS XML → IIIF Presentation API 3.0 manifest.
Extracts <fig> elements from <body>, fetches dimensions from info.json,
and writes a multi-canvas manifest ready for upload.

Usage:
    python xml_to_manifest.py article.xml
    python xml_to_manifest.py article.xml output.json
    python xml_to_manifest.py article.xml output.json my_config.json
"""

import json
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path
from urllib.parse import urlparse

# ── Configuration ─────────────────────────────────────────────────────────────

DEFAULT_CONFIG = "manifest_config.json"

def load_config(path: str) -> dict:
    p = Path(path)
    if not p.exists():
        sys.exit(f"✗ Config file not found: {path}")
    with open(p, encoding="utf-8") as f:
        cfg = json.load(f)
    required = ["manifest_id", "base_canvas", "label_it", "label_en",
                "rights", "required_stmt_it", "required_stmt_en"]
    missing = [k for k in required if k not in cfg]
    if missing:
        sys.exit(f"✗ Config is missing required keys: {missing}")
    return cfg

# ─────────────────────────────────────────────────────────────────────────────

XLINK       = "{http://www.w3.org/1999/xlink}"
IIIF_SUFFIX = re.compile(r"/(?:full|square|region)/.*$")


def derive_service_id(url: str) -> str:
    return IIIF_SUFFIX.sub("", url)


def flatten(el) -> str:
    return " ".join("".join(el.itertext()).split()) if el is not None else ""


def coerce_scheme(url: str, force_http_hosts: list) -> str:
    """Return url with scheme forced to http if its hostname is in force_http_hosts."""
    if not force_http_hosts:
        return url
    parsed = urlparse(url)
    if parsed.hostname in force_http_hosts:
        return parsed._replace(scheme="http").geturl()
    return url


def fetch_dimensions(service_id: str, fallback_w: int = 1000, fallback_h: int = 1000,
                     force_http_hosts: list = None) -> tuple:
    force_http_hosts = force_http_hosts or []
    info_url = coerce_scheme(service_id.rstrip("/") + "/info.json", force_http_hosts)

    try:
        req = urllib.request.Request(
            info_url, headers={"User-Agent": "IIIF-manifest-generator/1.0"}
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            info = json.loads(resp.read().decode())
        w, h = int(info.get("width", fallback_w)), int(info.get("height", fallback_h))
        print(f"  ✓ {service_id.split('/')[-1]}  {w}×{h}")
        return w, h
    except Exception as exc:
        print(f"  ⚠ {info_url}: {exc} — using {fallback_w}×{fallback_h}")
        return fallback_w, fallback_h


def extract_and_build(xml_path: str, out_path: str, cfg: dict):
    tree = ET.parse(xml_path)
    root = tree.getroot()

    body = root.find(".//body")
    if body is None:
        print("⚠  No <body> found — scanning entire document.")
        body = root

    FALLBACK_W       = cfg.get("fallback_width",  1000)
    FALLBACK_H       = cfg.get("fallback_height", 1000)
    FETCH_DELAY      = cfg.get("fetch_delay", 0.3)
    FORCE_HTTP_HOSTS = cfg.get("force_http_hosts", [])  # e.g. ["fotothek.biblhertz.it"]

    if FORCE_HTTP_HOSTS:
        print(f"ℹ  HTTP (not HTTPS) will be used for info.json requests to: {FORCE_HTTP_HOSTS}")

    manifest = {
        "@context": "http://iiif.io/api/presentation/3/context.json",
        "id":    cfg["manifest_id"],
        "type":  "Manifest",
        "label": {"it": [cfg["label_it"]], "en": [cfg["label_en"]]},
        "rights": cfg["rights"],
        "requiredStatement": {
            "label": {"it": ["Fonte"],                "en": ["Attribution"]},
            "value": {"it": [cfg["required_stmt_it"]], "en": [cfg["required_stmt_en"]]}
        },
        "behavior": ["individuals"],
        "items": []
    }

    seen_ids   = defaultdict(int)
    skipped    = []
    no_online  = []

    for position, fig in enumerate(body.iter("fig"), start=1):
        fig_id = fig.get("id", "").strip()
        seen_ids[fig_id] += 1

        label     = flatten(fig.find("label")).rstrip(".")
        cap_el    = fig.find("caption")
        cap_title = flatten(cap_el.find("title")) if cap_el is not None else ""
        cap_text  = flatten(cap_el)

        handle = ""
        if cap_el is not None:
            ext = cap_el.find(".//ext-link[@ext-link-type='uri']")
            if ext is not None:
                handle = ext.get(f"{XLINK}href", "").strip()

        online_url = ""
        service_id = ""

        alternatives = fig.find("alternatives")
        graphics = alternatives.findall("graphic") if alternatives is not None \
                   else fig.findall("graphic")

        for g in graphics:
            use  = g.get("specific-use", "")
            href = g.get(f"{XLINK}href", "").strip()
            if use == "online":
                online_url = href
                service_id = derive_service_id(href)
                break
            elif not use and href.startswith("https://") and not online_url:
                online_url = href
                service_id = derive_service_id(href)

        if not online_url:
            no_online.append(fig_id or "pos {}".format(position))
            skipped.append(position)
            continue

        # Fetch dimensions — uses HTTP for hosts listed in force_http_hosts
        w, h = fetch_dimensions(service_id, FALLBACK_W, FALLBACK_H, FORCE_HTTP_HOSTS)
        time.sleep(FETCH_DELAY)

        canvas_id    = f"{cfg['base_canvas']}/canvas/{fig_id}"
        canvas_label = f"{label}. — {cap_title}" if cap_title else f"{label}."

        canvas = {
            "id":     canvas_id,
            "type":   "Canvas",
            "label":  {"it": [canvas_label]},
            "width":  w,
            "height": h,
            "items": [{
                "id":   f"{canvas_id}/page/1",
                "type": "AnnotationPage",
                "items": [{
                    "id":         f"{canvas_id}/annotation/1",
                    "type":       "Annotation",
                    "motivation": "painting",
                    "target":     canvas_id,
                    "body": {
                        "id":     online_url,
                        "type":   "Image",
                        "format": "image/jpeg",
                        "width":  w,
                        "height": h,
                        "service": [{
                            "id":      service_id,
                            "type":    "ImageService3",
                            "profile": "level2"
                        }]
                    }
                }]
            }],
            "thumbnail": [{
                "id":     f"{service_id}/full/300,/0/default.jpg",
                "type":   "Image",
                "format": "image/jpeg"
            }]
        }

        if cap_text:
            canvas["summary"] = {"it": [cap_text]}

        # Per-canvas rights: use figure-level licence if present, otherwise inherit manifest rights
        licence_el = fig.find(".//permissions/license")
        if licence_el is None:
            licence_el = fig.find(".//license")
        licence = licence_el.get(f"{XLINK}href", "").strip() \
                  if licence_el is not None else ""

        if licence:
            canvas["rights"] = licence
        if handle:
            canvas["seeAlso"] = [{"id": handle, "type": "Text", "format": "text/html"}]

        manifest["items"].append(canvas)

    # Write manifest
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)

    # Report
    print(f"\n✓ Manifest written → {out_path}")
    print(f"  {len(manifest['items'])} canvases, {len(skipped)} skipped")

    dupes = {fid: n for fid, n in seen_ids.items() if n > 1}
    if dupes:
        print(f"\n⚠  Duplicate fig ids: {list(dupes.keys())}")
    if no_online:
        print(f"⚠  Figs with no online URL (skipped): {no_online}")


if __name__ == "__main__":
    xml      = sys.argv[1] if len(sys.argv) > 1 else "article.xml"
    out      = sys.argv[2] if len(sys.argv) > 2 else "manifest.json"
    cfg_path = sys.argv[3] if len(sys.argv) > 3 else DEFAULT_CONFIG
    cfg      = load_config(cfg_path)
    extract_and_build(xml, out, cfg)
