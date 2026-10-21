# プラグインの新しい版を出す（管理者向け）

生徒のWordPressは、このリポジトリの `wordpress-plugin/update.json` を6時間ごとに確認し、版が新しければ「更新があります」と表示します。

1. `wordpress-plugin/codex-connect/codex-connect.php` の `Version:` と `CODEX_CONNECT_VERSION` を同じ番号に上げる（例：0.4.0 → 0.4.1）。
2. `wordpress-plugin` フォルダで ZIP を作り直す：`rm codex-connect.zip && zip -r codex-connect.zip codex-connect`
3. `wordpress-plugin/update.json` の `version` を同じ番号にし、`changelog` に変更点を書く。
4. `main` に反映する。

注意：
- このリポジトリが **Public** でないと、生徒のWordPressは更新を確認できません。
- ここに入れたコードは、更新した全員のWordPressで動きます。`main` に書き込める人は最小限にしてください。
