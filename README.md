# WordPress リライト用 Codex テンプレート（試作版）

Codexのクラウドから、WordPressの記事リライトを行うためのテンプレートです。
パスワードはこのリポジトリには入れず、各自のCodexのPersonal vaultに保存します。

- 初期設定：[docs/setup.md](docs/setup.md)
- サイトの登録：WordPressの「ツール → Codex接続」に表示される `WP_SITE_<ドメイン>`（URL）と `WP_AUTH_<ドメイン>`（ログイン情報）を Codex の Personal vault に追加。名前はドメインから自動で決まるので、サイト同士で上書きされない。
- スキル：`.agents/skills/wp-rewrite`（リライト案の作成）、`.agents/skills/wp-apply-rewrite`（反映・破棄）
- WordPress用プラグイン：`wordpress-plugin/codex-connect.zip`（接続情報の作成、記事メモ、ログインなしのプレビューと比較、予約反映、作業履歴と週のまとめ、一時停止スイッチ、記事の点検、サイト情報、自動更新）。新しい版の出し方は [docs/release.md](docs/release.md)
- WordPress操作スクリプト：`tools/wp.py`（Python標準ライブラリのみ、追加インストール不要）

## できること

- 記事一覧の取得、記事本文の取得
- リライト案を別の下書きとして保存し、プレビューURLを返す（公開中の記事はそのまま）
- OKが出たら元の記事に反映（リライト前の本文はバックアップとしてゴミ箱に30日保存）
- 画像のアップロード（体験談や写真の組み込み用）
