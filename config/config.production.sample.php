<?php
// ロリポップ本番用の設定テンプレート。
//
// 使い方:
//   1. このファイルを同じ config フォルダに config.production.php という名前でコピーする
//   2. ★印の箇所を埋める(パスワードはご自身で入力してください)
//   3. deploy\lolipop-deploy.ps1 を実行すると、サーバー上の config/config.php として転送される
//
// config.production.php は .gitignore 済みなのでGitHubには上がりません。

return [
    'site_name' => 'IdeaForum',
    'base_url'  => 'https://fusion.upper.jp',
    'base_path' => '/ideaforum',   // https://fusion.upper.jp/ideaforum/ で公開する場合
    'timezone'  => 'Asia/Tokyo',
    'debug'     => false,          // 本番では必ず false のまま

    // ロリポップ ユーザー専用ページ → データベース の値
    // IdeaForum専用に作成したDB (MySQL 8.4)。WordPressのDBとは別サーバーなので注意。
    'db' => [
        'host'     => 'mysql84-1.lolipop.lan',
        'name'     => 'LAA1700269-ideaforum',
        'user'     => 'LAA1700269',
        'password' => '★このDBのパスワードをここに★',
    ],

    'mail' => [
        'driver'    => 'mail',
        'from'      => '★送信元メールアドレス(例 noreply@fusion.upper.jp)★',
        'from_name' => 'IdeaForum',
    ],

    // Cloudflare Turnstile。空のままでも動くが、その場合bot対策は
    // ハニーポットとレート制限のみになる。
    'turnstile' => [
        'site_key'   => '',
        'secret_key' => '',
    ],

    'limits' => [
        'register_per_ip_per_day' => 5,
        'login_fail_per_ip_10min' => 10,
        'login_fail_per_email_10min' => 5,
        'ideas_per_user_per_day'  => 10,
        'posts_per_user_per_day'  => 60,
        'post_cooldown_seconds'   => 20,
    ],

    'blocked_email_domains' => [
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com',
        '10minutemail.com', 'temp-mail.org', 'yopmail.com',
        'trashmail.com', 'getnada.com', 'tempmail.dev',
    ],
];
