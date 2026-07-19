<?php
// このファイルを config.php にコピーして環境に合わせて編集する。
// config.php は .gitignore 済み(認証情報をリポジトリに入れないため)。
return [
    // サイト
    'site_name' => 'IdeaForum',
    'base_url'  => 'https://example.com',   // スキーム+ホスト。末尾スラッシュなし
    // サブフォルダに設置する場合のパス (例: '/ideaforum')。
    // ドキュメントルート直下なら空文字のままにする。
    'base_path' => '',
    'timezone'  => 'Asia/Tokyo',
    'debug'     => false,                    // 本番では必ず false

    // DB (ロリポップのユーザー専用ページに記載の値)
    'db' => [
        'host'     => 'mysql-xxx.lolipop.jp',
        'name'     => 'LAxxxxxxx-ideaforum',
        'user'     => 'LAxxxxxxx',
        'password' => '',
    ],

    // メール送信
    //   'mail'  : mb_send_mail() を使用(ロリポップ本番用)
    //   'file'  : storage/mail/ に書き出すだけ(ローカル開発用)
    'mail' => [
        'driver' => 'mail',
        'from'   => 'noreply@example.com',
        'from_name' => 'IdeaForum',
    ],

    // Cloudflare Turnstile (荒らし対策)。キーを空にすると無効化される。
    'turnstile' => [
        'site_key'   => '',
        'secret_key' => '',
    ],

    // 荒らし対策の各種上限
    'limits' => [
        'register_per_ip_per_day' => 5,
        'login_fail_per_ip_10min' => 10,
        'login_fail_per_email_10min' => 5,
        'ideas_per_user_per_day'  => 10,
        'posts_per_user_per_day'  => 60,
        'post_cooldown_seconds'   => 20,
    ],

    // 使い捨てメールドメインの拒否リスト(必要に応じて追加)
    'blocked_email_domains' => [
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com',
        '10minutemail.com', 'temp-mail.org', 'yopmail.com',
        'trashmail.com', 'getnada.com', 'tempmail.dev',
    ],
];
