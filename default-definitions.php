<?php

// <!-- custom-crawler-control\default-definitions.php -->

/**
 * 個別制御プラグインのデフォルト定義データ
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 初期 User-Agent 定義リスト
 * @return array
 */
function ggc_get_default_bots() {
    return [
        // --- Google コア ---
        'Google_Core_Search' => [
            'uas' => ['Googlebot', 'AdsBot-Google', 'Mediapartners-Google', 'APIs-Google'],
            'label' => 'Google コア検索 (ウェブ, 広告, AdSense)',
            'group_label' => '1. Google 検索コア',
            'description' => 'Googleの主要な検索エンジンクローラー。インデックス登録に必須です。',
        ],

        // --- Google AI / データ収集 ---
        'Google_AI_Data' => [
            'uas' => ['Gemini', 'Gemini-Deep-Research', 'Gemini-Deepmind', 'Gemini-DeepMind', 'Google-Extended', 'GoogleOther'],
            'label' => 'Google AI / データ収集 (Geminiなど)',
            'group_label' => '2. 主要AI / LLM クローラー',
            'description' => 'GeminiなどのAIサービスや、広範なデータ収集を目的としたクローラー。',
        ],

        // --- OpenAI ---
        'OpenAI' => [
            'uas' => ['GPTBot', 'ChatGPT-User/1.0' , 'ChatGPT-User', 'OAI-SearchBot'],
            'label' => 'GPTBot (OpenAI / ChatGPT)',
            'group_label' => '2. 主要AI / LLM クローラー',
            'description' => 'GPT-4やChatGPTなどOpenAIのデータ収集ボット。',
        ],

        // --- Claude ---
        'Other_AI_LLM' => [
            'uas' => ['ClaudeBot', 'Claude-SearchBot', 'Claude-User'],
            'label' => 'Anthropic Claude',
            'group_label' => '2. 主要AI / LLM クローラー',
            'description' => 'Anthropic社のClaude AIによるデータ収集クローラー。',
        ],

        // --- Amazon ---
        'Amazon_AI' => [
            'uas' => ['Amazonbot'],
            'label' => 'Amazonbot (Amazon検索 / AI)',
            'group_label' => '2. 主要AI / LLM クローラー',
            'description' => 'Amazonの検索・AI向けクローラー。',
        ],

        // --- Microsoft / Bing ---
        'Bing_Core' => [
            'uas' => ['bingbot', 'adidxbot', 'BingPreview'],
            'label' => 'Bing (Microsoft 検索 & 広告)',
            'group_label' => '3. Microsoft / Bing',
            'description' => 'Bingのコア検索クローラー、広告ボットを含みます。',
        ],

        // --- Baidu ---
        'Baidu' => [
            'uas' => ['Baiduspider'],
            'label' => 'Baiduspider (Baidu 検索)',
            'group_label' => '3. 検索エンジン',
            'description' => 'Baidu の検索クローラー。',
        ],

        // --- X / Grok ---
        'X_SNS' => [
            'uas' => ['Twitterbot', 'Xbot', 'GrokBot', 'xAI'],
            'label' => 'X / Twitter & Grok (プレビュー & AI)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'X (旧Twitter) と Grok AIのリンクプレビュー/データ収集に必須です。',
        ],

        // --- Apple ---
        'Apple_Core' => [
            'uas' => ['Applebot'],
            'label' => 'Applebot (Apple Search / Siri)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'Appleの検索結果やSiriの提案に使用されるクローラーです。',
        ],

        // --- Meta (Facebook / Instagram) ---
        'Meta' => [
            'uas' => ['facebookexternalhit', 'Facebot', 'Instagram'],
            'label' => 'Meta (Facebook / Instagram)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'Facebook / Instagram のリンクプレビュー用クローラー。',
        ],

        // --- Slack ---
        'Slack' => [
            'uas' => ['Slackbot-LinkExpanding'],
            'label' => 'Slack (リンク展開)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'Slack のリンクプレビュー用クローラー。',
        ],

        // --- Discord ---
        'Discord' => [
            'uas' => ['Discordbot'],
            'label' => 'Discord (リンク展開)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'Discord のリンクプレビュー用クローラー。',
        ],

        // --- LinkedIn ---
        'LinkedIn' => [
            'uas' => ['LinkedInBot'],
            'label' => 'LinkedIn (リンク展開)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'LinkedIn のリンクプレビュー用クローラー。',
        ],

        // --- Pinterest ---
        'Pinterest' => [
            'uas' => ['Pinterestbot'],
            'label' => 'Pinterest (画像共有)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'Pinterest の画像収集クローラー。',
        ],

        // --- LINE ---
        'LINE' => [
            'uas' => ['Linespider'],
            'label' => 'LINE (リンク展開)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'LINE のリンクプレビュー用クローラー。',
        ],

        // --- LINE（参考：不定だが対応可能） ---
        'LINE_Preview' => [
            'uas' => ['Line'], // 不完全だが参考値
            'label' => 'LINE (リンクプレビュー)',
            'group_label' => '4. SNS / プレビュー系',
            'description' => 'LINE のリンクプレビュー用クローラー。',
        ],

        // --- Ahrefs ---
        'Ahrefs' => [
            'uas' => ['AhrefsBot', 'AhrefsSiteAudit'],
            'label' => 'AhrefsBot (SEO分析)',
            'group_label' => '5. SEO / 分析 / 監視ツール',
            'description' => '被リンクや競合分析で使われるAhrefsのボットです。',
        ],

        // --- 外部監視サービス ---
        'Monitoring' => [
            'uas' => ['Pingdom', 'UptimeRobot', 'StatusCake', 'Uptrends'],
            'label' => '外部監視サービス',
            'group_label' => '5. インフラ・監視',
            'description' => '各種外部監視ツールのクローラー。',
        ],

        // --- Cloudflare ---
        'Cloudflare' => [
            'uas' => ['Cloudflare-HealthChecks', 'Cloudflare-AlwaysOnline'],
            'label' => 'Cloudflare Bot',
            'group_label' => '5. インフラ・監視',
            'description' => 'Cloudflare の正常性チェック・キャッシュ用クローラー。',
        ],

        // --- Shopify / EC ---
        'Shopify' => [
            'uas' => ['Shopify-Webhooks'],
            'label' => 'Shopify Webhook',
            'group_label' => '6. EC / API 連携',
            'description' => 'Shopify が Webhook 用に送信するクローラー。',
        ],
    ];
}


/**
 * 初期 不正UAパターン 定義リスト
 * @return array
 */
function ggc_get_default_browser_patterns() {
    return [
        'curl_fake' => [
            'pattern' => '^curl/',
            'label' => 'cURL (不正ツール)',
            'group_label' => '不正アクセスツール',
            'description' => 'スクリプトによるアクセスツール (通常はブラウザアクセスではない)。',
        ],
        'wget_fake' => [
            'pattern' => 'Wget',
            'label' => 'Wget (不正ツール)',
            'group_label' => '不正アクセスツール',
            'description' => 'Webサイトの再帰的なダウンロードツール。',
        ],
        // Headless/Automation
        'headless_chrome' => [
            'pattern' => 'HeadlessChrome',
            'label' => 'Headless Chrome/Browser',
            'group_label' => 'Headless/Automation',
            'description' => 'スクレイピングツールで使われる可能性が高い。',
        ],
        'phantom_js' => [
            'pattern' => 'PhantomJS',
            'label' => 'PhantomJS',
            'group_label' => 'Headless/Automation',
            'description' => '旧世代のHeadlessブラウザ。',
        ],
        'selenium' => [
            'pattern' => 'Selenium',
            'label' => 'Selenium',
            'group_label' => 'Headless/Automation',
            'description' => '自動テスト/スクレイピングフレームワーク。',
        ],
        'puppeteer' => [
            'pattern' => 'Puppeteer',
            'label' => 'Puppeteer',
            'group_label' => 'Headless/Automation',
            'description' => 'Node.jsのChrome操作ライブラリ。',
        ],
        // 不正なUser-Agent文字列
        'mozilla_4_fake' => [
            'pattern' => 'Mozilla/4.0',
            'label' => 'Mozilla/4.0 (旧式偽装)',
            'group_label' => '不正/旧式UA',
            'description' => '非常に古いブラウザを偽装した不正なアクセス。',
        ],
        // その他のボット/ツール
        'python_requests' => [
            'pattern' => 'python-requests',
            'label' => 'Python Requests',
            'group_label' => 'スクリプトライブラリ',
            'description' => 'Pythonスクリプトからのアクセス。',
        ],
        'python_urllib' => [
            'pattern' => 'urllib',
            'label' => 'Python urllib',
            'group_label' => 'スクリプトライブラリ',
            'description' => 'Python標準ライブラリからのアクセス。',
        ],
        'node_fetch' => [
            'pattern' => 'node-fetch',
            'label' => 'node-fetch',
            'group_label' => 'スクリプトライブラリ',
            'description' => 'Node.jsからのアクセス。',
        ],
        'unknown_bot_1' => [
            'pattern' => '^Java/',
            'label' => 'Java/ (不明ボット)',
            'group_label' => 'その他のボット',
            'description' => 'Java環境からの不明なアクセス。',
        ],
        'unknown_bot_2' => [
            'pattern' => 'Go-http-client',
            'label' => 'Go-http-client (不明ボット)',
            'group_label' => 'その他のボット',
            'description' => 'Go言語からの不明なアクセス。',
        ],
        'unknown_bot_3' => [
            'pattern' => 'okhttp',
            'label' => 'okhttp (不明ボット)',
            'group_label' => 'その他のボット',
            'description' => 'モバイルアプリ等からの不明なアクセス。',
        ]
    ];
}

/**
 * 初期 マークダウンテンプレート
 * @return array
 */
function ggc_get_default_markdown_templates() {
    return [
        'default' => [
            'title' => '閲覧制限中',
            'markdown' => "# 閲覧制限\n\nこのページは現在、アクセス条件により内容が制限されています。\n\n- 対象ユーザー: 許可されたUser-Agent / IPのみ\n- 詳細: 管理者にお問い合わせください\n",
            'image_url' => '',
            'image_id' => 0,
            'random_enabled' => 1,
        ],
        'maintenance' => [
            'title' => 'メンテナンス中',
            'markdown' => "# メンテナンス中\n\nただいまメンテナンスを実施しています。\n\nご不便をおかけしますが、しばらくしてから再度アクセスしてください。\n",
            'image_url' => '',
            'image_id' => 0,
            'random_enabled' => 0,
        ],
        'members_only' => [
            'title' => '会員限定コンテンツ',
            'markdown' => "# 会員限定\n\nこのコンテンツは会員限定で公開されています。\n\n- ログイン済みの方: トップページからアクセスしてください\n- 未登録の方: 会員登録をご検討ください\n",
            'image_url' => '',
            'image_id' => 0,
            'random_enabled' => 0,
        ],
        'verify_access' => [
            'title' => 'アクセス確認',
            'markdown' => "# アクセス確認\n\n不正アクセス防止のため、現在のアクセスは制限されています。\n\n- 正常なアクセスであれば、時間をおいて再度お試しください\n- 問題が続く場合はお問い合わせください\n",
            'image_url' => '',
            'image_id' => 0,
            'random_enabled' => 0,
        ],
        'limited_preview' => [
            'title' => '限定公開',
            'markdown' => "# 限定公開\n\nこのページは特定条件のユーザーにのみ公開されています。\n\n- 条件: 指定のUser-Agent / IP\n- お問い合わせ: サポート窓口までご連絡ください\n",
            'image_url' => '',
            'image_id' => 0,
            'random_enabled' => 0,
        ],
    ];
}

/**
 * 初期 ページ評価（ブロックメッセージ）定義
 * @return array
 */
function ggc_get_default_page_eval_messages() {
    return [
        'default_block' => [
            'label' => 'アクセス禁止（標準）',
            'is_global' => 1,
            'status_code' => 403,
            'message' => 'アクセス禁止：このページは閲覧できません。',
        ],
        'ua_blocked' => [
            'label' => 'User-Agent ブロック',
            'is_global' => 1,
            'status_code' => 403,
            'message' => 'アクセス禁止：許可されていないUser-Agentです。',
        ],
        'ip_blocked' => [
            'label' => 'IP ブロック',
            'is_global' => 1,
            'status_code' => 403,
            'message' => 'アクセス禁止：許可されていないIPアドレスです。',
        ],
        'maintenance_block' => [
            'label' => 'メンテナンス中',
            'is_global' => 0,
            'status_code' => 503,
            'message' => 'メンテナンス中のため一時的に閲覧できません。',
        ],
        'not_found' => [
            'label' => 'ページが見つかりません',
            'is_global' => 0,
            'status_code' => 404,
            'message' => 'ページが見つかりません。',
        ],
        'gone' => [
            'label' => 'ページは利用できません',
            'is_global' => 0,
            'status_code' => 410,
            'message' => 'このページは利用できなくなりました。',
        ],
        'too_many_requests' => [
            'label' => 'リクエスト数が多すぎます',
            'is_global' => 0,
            'status_code' => 429,
            'message' => 'リクエスト数が多すぎます。しばらく時間を置いてからアクセスしてください。',
        ],
        'unavailable_for_legal_reasons' => [
            'label' => '法的理由により利用不可',
            'is_global' => 0,
            'status_code' => 451,
            'message' => '法的理由によりこのページは利用できません。',
        ],
        'bad_request' => [
            'label' => '不正なリクエスト',
            'is_global' => 0,
            'status_code' => 400,
            'message' => '不正なリクエストです。',
        ],
        'unauthorized' => [
            'label' => '認証が必要',
            'is_global' => 0,
            'status_code' => 401,
            'message' => '認証が必要です。ログインしてください。',
        ],
        'method_not_allowed' => [
            'label' => 'メソッドが許可されていない',
            'is_global' => 0,
            'status_code' => 405,
            'message' => 'このHTTPメソッドは許可されていません。',
        ],
        'internal_server_error' => [
            'label' => 'サーバーエラー',
            'is_global' => 0,
            'status_code' => 500,
            'message' => 'サーバーで予期しないエラーが発生しました。',
        ]
    ];
}

/**
 * 初期 IPアドレス範囲 定義リスト (自動更新対象)
 * @return array
 */
function ggc_get_default_ip_ranges() {
    return [
        'Google_IP_Range_1' => [
            'ranges' => [],
            'label' => 'Google IP範囲',
            'group_label' => '検索エンジン',
            'description' => 'Googlebotの正式なIPアドレス範囲。',
            'source_url' => 'https://developers.google.com/search/apis/ipranges/googlebot.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ],
            'Google_IP_Range_2' => [
            'ranges' => [],
            'label' => 'Google IP範囲',
            'group_label' => '検索エンジン・AI / LLM',
            'description' => 'Googlebot、ユーザーがウェブページを開くように要求したときのGeminiのチャット。',
            'source_url' => 'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ],
        'Bing_IP_Range' => [
            'ranges' => [],
            'label' => 'Bing (Microsoft) IP範囲',
            'group_label' => '検索エンジン',
            'description' => 'BingbotのIPアドレス範囲。',
            'source_url' => 'https://www.bing.com/toolbox/bingbot.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ],
    ];
}

/**
 * 初期 IPアドレス範囲 定義リスト2 (自動更新対象)
 * @return array
 */
function ggc_get_default_ip_ranges_2() {
    return [
        'GPTBot_IP_Range_1' => [
            'ranges' => [],
            'label' => 'GPTBot (OpenAI) IP範囲',
            'group_label' => 'AI / LLM',
            'description' => 'OpenAIのGPTBot学習用クローラーのIPアドレス。',
            'source_url' => 'https://openai.com/gptbot.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ], 
        'GPTBot_IP_Range_2' => [
            'ranges' => [],
            'label' => 'GPTuserBot (OpenAI) IP範囲',
            'group_label' => 'AI / LLM',
            'description' => 'ChatGPTのユーザーボットのIPアドレス。',
            'source_url' => 'https://openai.com/chatgpt-user.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ],
        'OpenAI_SearchBot_IP_Range' => [
            'ranges' => [],
            'label' => 'SearchBot (OpenAI) IP範囲',
            'group_label' => 'AI / LLM',
            'description' => 'OpenAIのSearchBotクローラーのIPアドレス。',
            'source_url' => 'https://openai.com/searchbot.json',
            'allow_placeholder' => true,
            'is_auto' => true,
        ],
    ];
}
