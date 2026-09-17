"""
One-pass JATS XML → IIIF Presentation API 3.0 manifest.
Extracts <fig> elements from <body>, fetches dimensions from info.json,
and writes a multi-canvas manifest ready for upload.

manifest_id/base_canvas and the article label are auto-derived per file
(from the filename and <article-title>) unless overridden in the config —
see manifest_config.json.model.

Usage:
    # Single article
    python xml-to-manifest.py article.xml
    python xml-to-manifest.py article.xml output.json
    python xml-to-manifest.py article.xml output.json my_config.json

    # Whole volume — one XML per article in a folder, one manifest per article out
    python xml-to-manifest.py path/to/volume_folder/ output_folder/
    python xml-to-manifest.py path/to/volume_folder/ output_folder/ my_config.json
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

    # rights/attribution are institution-level and can't be derived from the XML.
    required = ["rights", "required_stmt_it", "required_stmt_en"]
    missing = [k for k in required if k not in cfg]
    if missing:
        sys.exit(f"✗ Config is missing required keys: {missing}")

    # manifest_id/base_canvas: either given explicitly, or derivable per file
    # from base_url + filename.
    has_explicit_ids = "manifest_id" in cfg and "base_canvas" in cfg
    if not has_explicit_ids and "base_url" not in cfg:
        sys.exit("✗ Config must provide either manifest_id + base_canvas, "
                  "or base_url (to derive per-article ids from the filename)")
    return cfg

# ─────────────────────────────────────────────────────────────────────────────

XLINK       = "{http://www.w3.org/1999/xlink}"
XML_NS      = "{http://www.w3.org/XML/1998/namespace}"
IIIF_SUFFIX = re.compile(r"/(?:full|square|region)/.*$")


class ArticleError(Exception):
    """Raised for per-article problems (missing title, bad ids) so batch
    mode can skip just that file instead of aborting the whole run."""


def derive_ids(xml_path: str, cfg: dict) -> tuple:
    """Return (manifest_id, base_canvas), from explicit config values or
    derived from base_url + the XML filename stem."""
    if "manifest_id" in cfg and "base_canvas" in cfg:
        return cfg["manifest_id"], cfg["base_canvas"]
    slug = Path(xml_path).stem
    base_url = cfg["base_url"].rstrip("/")
    return f"{base_url}/{slug}.json", f"{base_url}/{slug}"


def derive_labels(root, primary_lang: str = "it") -> dict:
    """
    Auto-derive the manifest label from JATS <title-group>:
      - <article-title>            → labels[primary_lang]
      - <trans-title-group xml:lang="..."><trans-title>  → labels[that lang]

    A second language is only added when the XML actually carries a
    <trans-title-group> — no title is duplicated across languages that
    aren't genuinely present in the source.

    If the article's own root <article xml:lang="..."> is set, it wins
    over the config's primary_lang default — a French article in an
    otherwise English volume must not have its title filed under "en"
    just because that's the volume default.
    """
    article_lang = root.get(f"{XML_NS}lang")
    if article_lang:
        primary_lang = article_lang.split("-")[0].lower()

    title_group = root.find(".//title-group")
    if title_group is None:
        return {}

    labels = {}
    article_title = flatten(title_group.find("article-title"))
    if article_title:
        labels[primary_lang] = [article_title]

    for tt_group in title_group.findall("trans-title-group"):
        lang = tt_group.get(f"{XML_NS}lang")
        trans_title = flatten(tt_group.find("trans-title"))
        if lang and trans_title:
            labels[lang] = [trans_title]

    return labels


def resolve_label(root, cfg: dict) -> dict:
    """Manual label_it/label_en in the config always win (backward compat);
    otherwise derive from the JATS title-group."""
    manual = {}
    if cfg.get("label_it"):
        manual["it"] = [cfg["label_it"]]
    if cfg.get("label_en"):
        manual["en"] = [cfg["label_en"]]
    if manual:
        return manual

    labels = derive_labels(root, cfg.get("primary_lang", "it"))
    if not labels:
        raise ArticleError(
            "no <article-title> found in <title-group>, and no "
            "label_it/label_en override given in config"
        )
    return labels


def derive_service_id(url: str) -> str:
    return IIIF_SUFFIX.sub("", url)


# IIIF Presentation 3.0's recognized rights vocabularies are canonically
# http:// — validators match the exact string, even though both sites now
# redirect http → https. Editors will naturally type https:// (or paste it
# from a browser bar), so normalize rather than reject.
RIGHTS_HTTP_HOSTS = {"creativecommons.org", "rightsstatements.org"}


def normalize_rights_uri(url: str) -> str:
    if not url:
        return url
    parsed = urlparse(url)
    if parsed.scheme == "https" and parsed.hostname in RIGHTS_HTTP_HOSTS:
        return parsed._replace(scheme="http").geturl()
    return url


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

    manifest_id, base_canvas = derive_ids(xml_path, cfg)
    label = resolve_label(root, cfg)

    body = root.find(".//body")
    if body is None:
        print("⚠  No <body> found — scanning entire document.")
        body = root

    FALLBACK_W       = cfg.get("fallback_width",  1000)
    FALLBACK_H       = cfg.get("fallback_height", 1000)
    FETCH_DELAY      = cfg.get("fetch_delay", 0.3)
    FORCE_HTTP_HOSTS = cfg.get("force_http_hosts", [])  # e.g. ["images.example.org"]

    if FORCE_HTTP_HOSTS:
        print(f"ℹ  HTTP (not HTTPS) will be used for info.json requests to: {FORCE_HTTP_HOSTS}")

    manifest = {
        "@context": "http://iiif.io/api/presentation/3/context.json",
        "id":    manifest_id,
        "type":  "Manifest",
        "label": label,
        "rights": normalize_rights_uri(cfg["rights"]),
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

        canvas_id    = f"{base_canvas}/canvas/{fig_id}"
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
        licence = normalize_rights_uri(
            licence_el.get(f"{XLINK}href", "").strip()
            if licence_el is not None else ""
        )

        if licence:
            canvas["rights"] = licence
        if handle:
            canvas["seeAlso"] = [{"id": handle, "type": "Text", "format": "text/html"}]

        manifest["items"].append(canvas)

    dupes = {fid: n for fid, n in seen_ids.items() if n > 1}

    if not manifest["items"]:
        reason = "no <fig> elements found" if not seen_ids else \
                  "no figure had a usable online/IIIF image URL"
        print(f"\n⚠  No canvases ({reason}) — manifest not written for {Path(xml_path).name}")
        if dupes:
            print(f"⚠  Duplicate fig ids: {list(dupes.keys())}")
        return 0

    # Write manifest
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)

    # Report
    print(f"\n✓ Manifest written → {out_path}")
    print(f"  {len(manifest['items'])} canvases, {len(skipped)} skipped")

    if dupes:
        print(f"\n⚠  Duplicate fig ids: {list(dupes.keys())}")
    if no_online:
        print(f"⚠  Figs with no online URL (skipped): {no_online}")

    return len(manifest["items"])


def is_article_file(xml_path: Path) -> bool:
    """A real JATS article has a <body> with the figures. Volume/issue-level
    metadata files (e.g. volume-meta.xml) may still carry a <title-group>
    (a volume title) but have no <body> — that's the reliable signal to
    tell them apart, not the presence of a title."""
    try:
        root = ET.parse(xml_path).getroot()
    except ET.ParseError:
        return False
    return root.find(".//body") is not None


def process_folder(xml_dir: str, out_dir: str, cfg: dict):
    """Batch mode: build one manifest per JATS article XML file in xml_dir.
    Non-article files (no <body> — e.g. volume-meta.xml) and files with no
    derivable <article-title> are reported and skipped rather than
    aborting the whole run."""
    xml_dir = Path(xml_dir)
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    xml_files = sorted(xml_dir.glob("*.xml"))
    if not xml_files:
        sys.exit(f"✗ No .xml files found in {xml_dir}")

    print(f"Found {len(xml_files)} XML file(s) in {xml_dir}\n")

    built, skipped_non_article, empty, failed = [], [], [], []
    for xml_path in xml_files:
        if not is_article_file(xml_path):
            print(f"── {xml_path.name} ──\n⚠  No <body> — not an article, skipped.\n")
            skipped_non_article.append(xml_path.name)
            continue

        out_path = out_dir / f"{xml_path.stem}.json"
        print(f"── {xml_path.name} ──")
        try:
            n_canvases = extract_and_build(str(xml_path), str(out_path), cfg)
            if n_canvases:
                built.append(xml_path.name)
            else:
                empty.append(xml_path.name)
        except ArticleError as exc:
            print(f"⚠  Skipped: {exc}")
            failed.append(xml_path.name)
        print()

    print(f"── Batch complete: {len(built)} manifest(s) written, "
          f"{len(empty)} article(s) had no images, "
          f"{len(skipped_non_article)} non-article file(s) skipped, "
          f"{len(failed)} failed ──")
    if empty:
        print(f"  No images: {empty}")
    if skipped_non_article:
        print(f"  Not articles: {skipped_non_article}")
    if failed:
        print(f"  Failed: {failed}")


if __name__ == "__main__":
    if len(sys.argv) > 1 and Path(sys.argv[1]).is_dir():
        xml_dir  = sys.argv[1]
        out_dir  = sys.argv[2] if len(sys.argv) > 2 else "manifests"
        cfg_path = sys.argv[3] if len(sys.argv) > 3 else DEFAULT_CONFIG
        cfg      = load_config(cfg_path)
        process_folder(xml_dir, out_dir, cfg)
    else:
        xml      = sys.argv[1] if len(sys.argv) > 1 else "article.xml"
        out      = sys.argv[2] if len(sys.argv) > 2 else "manifest.json"
        cfg_path = sys.argv[3] if len(sys.argv) > 3 else DEFAULT_CONFIG
        cfg      = load_config(cfg_path)
        try:
            extract_and_build(xml, out, cfg)
        except ArticleError as exc:
            sys.exit(f"✗ {exc}")
