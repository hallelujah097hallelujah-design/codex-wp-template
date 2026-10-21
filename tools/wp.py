#!/usr/bin/env python3
"""WordPress REST API helper for Codex cloud tasks.

Sites come from Codex Personal vault entries, one pair per site. The Codex接続 plugin
shows both names, built from the site's domain (example.com -> EXAMPLE_COM), so two
sites can never share a name:
    WP_SITE_<KEY>   environment variable: the site URL, e.g. https://example.com
    WP_AUTH_<KEY>   network secret: the full Authorization header value,
                    Basic <base64 of "wp_user:application password">
Older numbered names (WP_SITE_1 / WP_AUTH_1) keep working.
For local testing WP_AUTH_<KEY> may instead be "wp_user:application password".
A site is named on the command line by its domain (or its KEY).

Commands (all print JSON):
  sites                                   list configured sites and check each connection
  info   --site S [--out FILE]            site details: theme, plugins, categories, tags...
  list   --site S [--search Q] [--page N] [--per-page N] [--status publish]
  get    --site S --id ID --out FILE      save raw block content to FILE
  draft  --site S --source-id ID --content FILE [--title T]
                                          save a rewrite proposal as a separate draft
  apply  --site S --draft-id ID [--keep-title]
                                          copy the approved draft into the original post;
                                          the draft is turned into a backup of the
                                          original and moved to trash
  discard --site S --draft-id ID          move a rewrite draft to trash
  schedule --site S --draft-id ID --at "YYYY-MM-DD HH:MM" [--keep-title]
                                          apply the draft automatically at that time (site's clock)
  unschedule --site S --draft-id ID       cancel a scheduled apply
  links  --site S [--out FILE]            all published articles (title, URL, categories) for internal links
  audit  --site S [--results]             check all articles for images without alt and broken links
                                          (--results: show the last check without running a new one)
  upload --site S --file PATH [--alt TEXT] upload an image to the media library
  note   --site S --id ID [--status ST] [--note TEXT] [--append] [--source URL ...]
                                          record the rewrite status / memo on an article
                                          (ST: needs_rewrite, in_progress, done, ok)
  queue  --site S [--status ST]           list articles by memo status (default needs_rewrite)
The article memo requires the Codex接続 plugin (0.2.0 or later) on the site;
login-free preview links and site info need 0.3.0 or later; schedule, links and audit need 0.4.0.
"""
import argparse
import base64
import datetime
import json
import mimetypes
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

SITE_RE = re.compile(r"^WP_SITE_([A-Z0-9_]+)$")
DRAFT_PREFIX = "【リライト案 #{id}】"
BACKUP_PREFIX = "【リライト前のバックアップ #{id}】"
DRAFT_RE = re.compile(r"^【リライト案 #(\d+)】")
USER_AGENT = "codex-wp-template/0.4"
# Shown in the plugin's work history (Tools → Codex接続 → 作業履歴)
ACTION_LABELS = {"draft": "リライト案を作成", "apply": "リライト案を反映", "discard": "リライト案を破棄",
                 "upload": "画像をアップロード", "note": "記事メモを更新",
                 "schedule": "予約反映を設定", "unschedule": "予約反映を取り消し"}
CURRENT_ACTION = ""
NOTE_STATUSES = {"needs_rewrite": "要リライト", "in_progress": "対応中", "done": "完了", "ok": "問題なし", "": "なし"}


def site_key(url):
    """Same rule as the plugin: host (+ path) without www., non-alphanumerics -> _, upper case."""
    u = urllib.parse.urlparse(url)
    base = (u.hostname or "").removeprefix("www.") + u.path.rstrip("/").lower()
    return re.sub(r"[^a-z0-9]+", "_", base).strip("_").upper()


def load_sites():
    sites = []
    for key, url in os.environ.items():
        m = SITE_RE.match(key)
        if m and url.strip():
            url = url.strip().rstrip("/")
            if not url.startswith("http"):
                url = "https://" + url
            u = urllib.parse.urlparse(url)
            host = (u.hostname or "").removeprefix("www.")
            sites.append({"key": m.group(1), "domain": u.netloc.lower(), "host": host, "url": url,
                          "auth_secret": f"WP_AUTH_{m.group(1)}", "rules_folder": f"sites/{host}"})
    if not sites:
        sys.exit("サイトが登録されていません（Personal vault に、プラグインの画面に出る WP_SITE_… と WP_AUTH_… を追加してください）")
    return sorted(sites, key=lambda s: s["host"])


def get_site(name):
    name = str(name).strip().lower().removeprefix("https://").removeprefix("http://").rstrip("/")
    matches = [s for s in load_sites()
               if name.removeprefix("www.") in (s["key"].lower(), s["domain"].removeprefix("www."), s["host"],
                                                s["url"].lower().split("://", 1)[1].removeprefix("www."))]
    if len(matches) > 1:
        names = "、".join(f"WP_SITE_{s['key']}" for s in matches)
        sys.exit(f"同じサイトが2回登録されています（{names}）。Personal vault で古いほうを削除してください")
    if not matches:
        sys.exit(f"サイト '{name}' が見つかりません（`python3 tools/wp.py sites` で一覧を確認してください）")
    return matches[0]


def auth_header(site):
    key = site["auth_secret"]
    value = os.environ.get(key)
    if not value:
        sys.exit(f"環境変数 {key} が設定されていません（Personal vault を確認してください）")
    if not value.startswith("Basic ") and ":" in value:
        value = "Basic " + base64.b64encode(value.encode()).decode()
    return value


def request(site, method, path, params=None, body=None, raw=None, headers=None, quiet=False):
    # ?rest_route= works whether or not pretty permalinks are enabled
    query = {"rest_route": path, **(params or {})}
    url = site["url"].rstrip("/") + "/?" + urllib.parse.urlencode(query)
    auth = auth_header(site)
    # X-Codex-Auth carries the same value for hosts that strip Authorization (read by the Codex接続 plugin)
    h = {"Authorization": auth, "X-Codex-Auth": auth, "User-Agent": USER_AGENT, "Accept": "application/json"}
    if CURRENT_ACTION:
        h["X-Codex-Action"] = urllib.parse.quote(CURRENT_ACTION)
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    elif raw is not None:
        data = raw
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            text = r.read().decode()
            try:
                return json.loads(text) if text else None, r.headers
            except json.JSONDecodeError:
                sys.exit(f"WordPressのREST APIから想定外の応答がありました（URLが正しいか確認してください）\n{text[:300]}")
    except urllib.error.HTTPError as e:
        detail = e.read().decode(errors="replace")[:500]
        if quiet:
            raise
        try:  # show WordPress's own message readably (it arrives \u-escaped)
            msg = json.loads(detail).get("message")
            if msg:
                detail = f"{msg}\n{detail}"
        except (json.JSONDecodeError, AttributeError):
            pass
        hint = ""
        if e.code in (401, 403):
            hint = "（認証エラー。アプリケーションパスワード、またはサーバーの国外アクセス制限を確認してください）"
            if '"rest_forbidden_context"' in detail or '"rest_not_logged_in"' in detail:
                hint = ("（ログイン情報がWordPressに届いていません。「Codex接続」プラグインが有効になっているか、"
                        "セキュリティ系プラグインがアプリケーションパスワードを止めていないか確認してください）")
            if '"rest_forbidden"' in detail or '"rest_cannot_edit"' in detail:
                hint = "（この操作は許可されていません。対象の記事IDがリライト案の下書きか、Codex用ユーザーに権限があるか確認してください）"
            if '"codex_paused"' in detail:
                hint = "（このサイトではCodexからの操作が一時停止されています。WordPressの「ツール → Codex接続」で再開できます）"
            if '"codex_readonly"' in detail:
                hint = "（このサイトは「読み取りのみ」に設定されています。WordPressの「ツール → Codex接続」で変更できます）"
        sys.exit(f"HTTP {e.code} {method} {path}{hint}\n{detail}")
    except urllib.error.URLError as e:
        sys.exit(f"{site['url']} に接続できません（URLとインターネットアクセスの許可ドメインを確認してください）: {e.reason}")


def out(obj):
    print(json.dumps(obj, ensure_ascii=False, indent=2))


def preview_link(site, post_id):
    """Login-free preview link from the plugin (0.3.0+); otherwise the normal, login-only one."""
    try:
        r, _ = request(site, "POST", f"/codex-connect/v1/preview/{post_id}", quiet=True)
        return (r["url"], r.get("compare") or None,
                f"ログインしなくても開けます（{r['expires']} まで有効。リンクを知っている人だけが見られます）")
    except (urllib.error.HTTPError, SystemExit, KeyError, TypeError):
        return (site["url"].rstrip("/") + f"/?p={post_id}&preview=true", None,
                "WordPressにログインしたブラウザでプレビューを開いてください")


def cmd_sites(a):
    sites = load_sites()
    result = []
    for s in sites:
        entry = {"domain": s["domain"], "url": s["url"], "vault_name": f"WP_SITE_{s['key']}",
                 "rules_folder": s["rules_folder"], "rules_folder_exists": os.path.isdir(s["rules_folder"]),
                 "password_saved": bool(os.environ.get(s["auth_secret"]))}
        problems = []
        if not entry["password_saved"]:
            problems.append(f"{s['auth_secret']} がありません（名前が WP_SITE_ と同じになっているか確認してください）")
        if site_key(s["url"]) != s["key"] and not s["key"].isdigit():
            problems.append(f"名前がURLと合っていません（このURLなら WP_SITE_{site_key(s['url'])} です）")
        same = [o for o in sites if o is not s and o["host"] == s["host"]]
        if same:
            problems.append("同じサイトが別の名前でも登録されています：" + "、".join(f"WP_SITE_{o['key']}" for o in same))
        try:
            info, _ = request(s, "GET", "/", {"_fields": "name,description"}, quiet=True)
            entry["site_title"] = info.get("name")
        except (SystemExit, urllib.error.URLError):
            entry["site_title"] = None
            problems.append("サイトに接続できません")
        try:
            st, _ = request(s, "GET", "/codex-connect/v1/status", quiet=True)
            entry["plugin_version"] = st.get("plugin_version")
            entry["codex_mode"] = st.get("mode", "on")
        except (SystemExit, urllib.error.URLError):
            entry["plugin_version"] = None
        if problems:
            entry["problems"] = problems
        result.append(entry)
    out(result)


def cmd_info(a):
    site = get_site(a.site)
    info, _ = request(site, "GET", "/codex-connect/v1/site-info")
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            json.dump(info, f, ensure_ascii=False, indent=2)
        out({"saved": a.out, "name": info.get("name"), "theme": info.get("theme")})
    else:
        out(info)


def cmd_list(a):
    site = get_site(a.site)
    params = {"per_page": a.per_page, "page": a.page, "status": a.status,
              "context": "edit", "_fields": "id,date,modified,status,title,link,meta"}
    if a.search:
        params["search"] = a.search
    posts, headers = request(site, "GET", "/wp/v2/posts", params)
    out({"total": headers.get("X-WP-Total"), "pages": headers.get("X-WP-TotalPages"),
         "posts": [{"id": p["id"], "title": p["title"]["raw"], "status": p["status"],
                    "modified": p["modified"], "link": p["link"],
                    "memo_status": NOTE_STATUSES.get((p.get("meta") or {}).get("_codex_status", ""), "")}
                   for p in posts]})


def cmd_get(a):
    site = get_site(a.site)
    p, _ = request(site, "GET", f"/wp/v2/posts/{a.id}", {"context": "edit"})
    with open(a.out, "w", encoding="utf-8") as f:
        f.write(p["content"]["raw"])
    out({"id": p["id"], "title": p["title"]["raw"], "status": p["status"], "link": p["link"],
         "modified": p["modified"], "categories": p.get("categories"), "tags": p.get("tags"),
         "featured_media": p.get("featured_media"), "excerpt": p["excerpt"]["raw"],
         "content_file": a.out, "content_chars": len(p["content"]["raw"]),
         "memo": memo_of(p)})


def today():
    return datetime.date.today().isoformat()


def memo_of(p):
    meta = p.get("meta") or {}
    if "_codex_status" not in meta:
        return None
    return {"status": NOTE_STATUSES.get(meta.get("_codex_status", ""), meta.get("_codex_status")),
            "note": meta.get("_codex_note", ""), "sources": meta.get("_codex_sources", ""),
            "checked_at": meta.get("_codex_checked_at", "")}


def set_memo(site, post_id, meta):
    # Plugin endpoint: saves the memo without changing the article's last-modified date
    body = {k.replace("_codex_", ""): v for k, v in meta.items()}
    r, _ = request(site, "POST", f"/codex-connect/v1/note/{post_id}", body=body)
    return {"id": r["id"], "meta": {"_codex_" + k: v for k, v in r.items() if k != "id"}}


def cmd_draft(a):
    site = get_site(a.site)
    src, _ = request(site, "GET", f"/wp/v2/posts/{a.source_id}", {"context": "edit"})
    with open(a.content, encoding="utf-8") as f:
        content = f.read()
    title = a.title or src["title"]["raw"]
    body = {"status": "draft", "title": DRAFT_PREFIX.format(id=a.source_id) + title,
            "content": content, "categories": src.get("categories", []),
            "tags": src.get("tags", []), "featured_media": src.get("featured_media", 0)}
    d, _ = request(site, "POST", "/wp/v2/posts", body=body)
    if "_codex_status" in (src.get("meta") or {}):
        set_memo(site, a.source_id, {"_codex_status": "in_progress"})
    url, compare, note = preview_link(site, d["id"])
    result = {"draft_id": d["id"], "source_id": a.source_id, "preview": url}
    if compare:
        result["compare"] = compare  # before/after, colour-coded
    result["note"] = note
    out(result)


def cmd_apply(a):
    site = get_site(a.site)
    d, _ = request(site, "GET", f"/wp/v2/posts/{a.draft_id}", {"context": "edit"})
    m = DRAFT_RE.match(d["title"]["raw"])
    if not m or d["status"] != "draft":
        sys.exit("指定された投稿はリライト案の下書きではありません")
    source_id = int(m.group(1))
    src, _ = request(site, "GET", f"/wp/v2/posts/{source_id}", {"context": "edit"})
    body = {"content": d["content"]["raw"]}
    if not a.keep_title:
        body["title"] = DRAFT_RE.sub("", d["title"]["raw"])
    # Keep the pre-rewrite version: the draft becomes a backup of the original, then goes to trash
    request(site, "POST", f"/wp/v2/posts/{a.draft_id}", body={
        "title": BACKUP_PREFIX.format(id=source_id) + src["title"]["raw"],
        "content": src["content"]["raw"]})
    p, _ = request(site, "POST", f"/wp/v2/posts/{source_id}", body=body)
    request(site, "DELETE", f"/wp/v2/posts/{a.draft_id}")
    if "_codex_status" in (src.get("meta") or {}):
        set_memo(site, source_id, {"_codex_status": "done", "_codex_checked_at": today()})
    out({"updated_id": source_id, "link": p["link"], "status": p["status"],
         "backup_id": a.draft_id,
         "note": "リライト前の本文は「リライト前のバックアップ」としてゴミ箱に入っています（30日間保存）"})


def cmd_discard(a):
    site = get_site(a.site)
    d, _ = request(site, "GET", f"/wp/v2/posts/{a.draft_id}", {"context": "edit"})
    if not DRAFT_RE.match(d["title"]["raw"]):
        sys.exit("指定された投稿はリライト案の下書きではありません")
    request(site, "DELETE", f"/wp/v2/posts/{a.draft_id}")
    out({"draft_trashed": a.draft_id})


def cmd_schedule(a):
    site = get_site(a.site)
    r, _ = request(site, "POST", f"/codex-connect/v1/schedule/{a.draft_id}",
                   body={"at": a.at, "keep_title": a.keep_title})
    out({"draft_id": a.draft_id, "apply_at": r["apply_at"], "timezone": r["timezone"],
         "note": "この時刻を過ぎて最初にサイトが表示されたときに反映されます（アクセスが少ないと数分遅れることがあります）"})


def cmd_unschedule(a):
    site = get_site(a.site)
    request(site, "DELETE", f"/codex-connect/v1/schedule/{a.draft_id}")
    out({"draft_id": a.draft_id, "apply_at": None})


def cmd_links(a):
    site = get_site(a.site)
    items, page = [], 1
    while True:
        r, _ = request(site, "GET", "/codex-connect/v1/links", {"page": page, "per_page": 500})
        items += r["items"]
        if page >= r["pages"]:
            break
        page += 1
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            json.dump(items, f, ensure_ascii=False, indent=1)
        out({"saved": a.out, "count": len(items)})
    else:
        out(items)


def cmd_audit(a):
    site = get_site(a.site)
    if not a.results:
        offset = 0
        while offset is not None:
            r, _ = request(site, "POST", "/codex-connect/v1/audit", body={"offset": offset})
            print(f"{r['checked']} / {r['total']} 記事を点検しました", file=sys.stderr)
            offset = r["next_offset"]
    r, _ = request(site, "GET", "/codex-connect/v1/audit")
    issues = r.get("issues", [])
    out({"checked_at": r.get("started"), "finished": bool(r.get("finished")), "articles": r.get("total"),
         "summary": {label: sum(1 for i in issues if i["type"] == t)
                     for t, label in (("link", "リンク切れ"), ("alt", "画像の説明文なし"), ("link_unknown", "確認できないリンク"))},
         "issues": issues})


def cmd_upload(a):
    site = get_site(a.site)
    ctype = mimetypes.guess_type(a.file)[0] or "application/octet-stream"
    name = os.path.basename(a.file)
    with open(a.file, "rb") as f:
        data = f.read()
    m, _ = request(site, "POST", "/wp/v2/media", raw=data, headers={
        "Content-Type": ctype,
        "Content-Disposition": f'attachment; filename="{urllib.parse.quote(name)}"'})
    if a.alt:
        request(site, "POST", f"/wp/v2/media/{m['id']}", body={"alt_text": a.alt})
    out({"media_id": m["id"], "url": m["source_url"]})


def cmd_note(a):
    site = get_site(a.site)
    p, _ = request(site, "GET", f"/wp/v2/posts/{a.id}", {"context": "edit", "_fields": "id,title,meta"})
    current = p.get("meta") or {}
    if "_codex_status" not in current:
        sys.exit("記事メモが使えません（サイトの「Codex接続」プラグインを 0.2.0 以上に更新してください）")
    meta = {}
    if a.status is not None:
        meta["_codex_status"] = a.status
        meta["_codex_checked_at"] = today()
    if a.note is not None:
        old = current.get("_codex_note", "")
        meta["_codex_note"] = f"{old}\n[{today()}] {a.note}".strip() if a.append and old else a.note
    if a.source:
        old = [u for u in current.get("_codex_sources", "").splitlines() if u.strip()]
        meta["_codex_sources"] = "\n".join(dict.fromkeys(old + a.source))
    if not meta:
        out({"id": p["id"], "title": p["title"]["raw"], "memo": memo_of(p)})
        return
    p = set_memo(site, a.id, meta)
    out({"id": p["id"], "memo": memo_of(p)})


def cmd_queue(a):
    site = get_site(a.site)
    q, _ = request(site, "GET", "/codex-connect/v1/queue", {"status": a.status, "limit": a.limit})
    for item in q["posts"]:
        item["status"] = NOTE_STATUSES.get(item.get("status", ""), item.get("status"))
    out(q)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("sites")
    p = sub.add_parser("info")
    p.add_argument("--site", required=True)
    p.add_argument("--out")
    p = sub.add_parser("list")
    p.add_argument("--site", required=True)
    p.add_argument("--search")
    p.add_argument("--page", type=int, default=1)
    p.add_argument("--per-page", type=int, default=20)
    p.add_argument("--status", default="publish")
    p = sub.add_parser("get")
    p.add_argument("--site", required=True)
    p.add_argument("--id", type=int, required=True)
    p.add_argument("--out", required=True)
    p = sub.add_parser("draft")
    p.add_argument("--site", required=True)
    p.add_argument("--source-id", type=int, required=True)
    p.add_argument("--content", required=True)
    p.add_argument("--title")
    p = sub.add_parser("apply")
    p.add_argument("--site", required=True)
    p.add_argument("--draft-id", type=int, required=True)
    p.add_argument("--keep-title", action="store_true")
    p = sub.add_parser("discard")
    p.add_argument("--site", required=True)
    p.add_argument("--draft-id", type=int, required=True)
    p = sub.add_parser("schedule")
    p.add_argument("--site", required=True)
    p.add_argument("--draft-id", type=int, required=True)
    p.add_argument("--at", required=True, help='site time, e.g. "2026-10-21 09:00"')
    p.add_argument("--keep-title", action="store_true")
    p = sub.add_parser("unschedule")
    p.add_argument("--site", required=True)
    p.add_argument("--draft-id", type=int, required=True)
    p = sub.add_parser("links")
    p.add_argument("--site", required=True)
    p.add_argument("--out")
    p = sub.add_parser("audit")
    p.add_argument("--site", required=True)
    p.add_argument("--results", action="store_true")
    p = sub.add_parser("upload")
    p.add_argument("--site", required=True)
    p.add_argument("--file", required=True)
    p.add_argument("--alt")
    p = sub.add_parser("note")
    p.add_argument("--site", required=True)
    p.add_argument("--id", type=int, required=True)
    p.add_argument("--status", choices=[k for k in NOTE_STATUSES if k])
    p.add_argument("--note")
    p.add_argument("--append", action="store_true", help="add to the existing memo with today's date")
    p.add_argument("--source", action="append", help="URL checked (repeatable)")
    p = sub.add_parser("queue")
    p.add_argument("--site", required=True)
    p.add_argument("--status", default="needs_rewrite", choices=[k for k in NOTE_STATUSES if k])
    p.add_argument("--limit", type=int, default=50)
    a = ap.parse_args()
    global CURRENT_ACTION
    CURRENT_ACTION = ACTION_LABELS.get(a.cmd, "")
    globals()["cmd_" + a.cmd](a)


if __name__ == "__main__":
    main()
