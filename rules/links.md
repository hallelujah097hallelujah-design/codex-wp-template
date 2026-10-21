# リンクの共通ルール

- アフィリエイトリンクには `rel="sponsored noopener"` を付ける。
- 楽天・Amazonの商品リンクは、ASPや楽天APIで取得した正式なリンクだけを使う。
- 関連記事への内部リンクを、本文中に2〜3本入れる。リンク先は `python3 tools/wp.py links --site <ドメイン> --out work/links.json` の一覧から選び、URLは一覧のものをそのまま使う（推測で作らない）。
