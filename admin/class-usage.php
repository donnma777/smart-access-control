<?php
namespace SmartAccessControl\Admin;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// methods for the "usage" tab are provided via a trait so they can be
// separated from the huge admin settings class and kept in their own file.
// the trait is imported by `Custom_Admin_Settings`.

trait Custom_Admin_Usage {
    /**
     * プラグインの使い方セクションの表示
     */
    public function render_usage_section() {
        ?>
        <div class="ggc-usage-section">
            <?php
            $this->render_usage_intro();
            $this->render_usage_steps();
            $this->render_usage_troubleshooting();
            $this->render_usage_app_info();
            ?>
        </div>
        <?php
    }

    private function render_usage_intro() {
        ?>
        <h2>プラグインの使い方</h2>
        <p>このプラグインは、WordPressの投稿や固定ページごとに、特定のクローラー（検索エンジン、AIボットなど）やIPアドレスからのアクセスを制御するための強力なツールです。</p>

        <div class="notice notice-error inline ggc-notice-box">
            <h3 class="ggc-notice-title">⚠️ 重要な注意事項と免責事項</h3>

            <h4 class="ggc-notice-subtitle">1. 免責事項</h4>
            <p>本プラグインの利用により生じたいかなる損害（アクセス制限の誤判定、検索エンジン評価への影響、収益・機会損失など）についても、作者は一切の責任を負いません。利用者の判断と責任においてご利用ください。</p>

            <h4 class="ggc-notice-subtitle">2. 制御の優先順位と技術的制限</h4>
            <p>本プラグインは <strong>WordPress (PHP) レイヤー</strong> で動作します。そのため、以下の制限があります。</p>
            <ul class="ggc-list-disc">
                <li><strong>robots.txt やサーバー設定が優先されます:</strong> robots.txt、Webサーバー設定（Apache/Nginx）、WAF、CDNなどで拒否されているアクセスは、本プラグインに到達する前にブロックされます。</li>
                <li><strong>PHPが実行されないアクセスは制御できません:</strong> 画像ファイル、CSS、JSなどの静的ファイルへの直接アクセスや、キャッシュプラグイン（WP Super Cacheなど）によって生成された静的HTMLへのアクセスは、PHPを経由しないため制御できません。<br>
                <strong>対策:</strong> 会員限定ページなど重要なページでは、キャッシュプラグインの除外設定を行ってください。</li>
                <li><strong>500 番台エラーに注意:</strong> 504 や 500 などを返すと、サーバーやプロキシが独自エラーページを挿入し、一度ブロックしても二回目以降は正常ページが返される場合があります。テスト時はキャッシュを完全に削除するか、ブロック用ステータスを 403 に変更してください。</li>
                <li><strong>表示するブラウザのキャッシュが残っている場合、ページが表示されたり、画像が表示されたままになる事があります。</li>
                <li><strong>クローキングになる可能性:</strong> ユーザーのアクセス条件によって表示内容が変わるため、検索エンジン等から見た際にクローキングと判断される可能性があります。検索ポリシーに抵触しない運用設計（全ユーザーに同等の内容を返す、明確な許可制の会員エリアでのみ使用する等）を検討してください。</li>
            </ul>

            <h4 class="ggc-notice-subtitle">3. 完全なブロックの保証はありません</h4>
            <ul class="ggc-list-disc">
                <li><strong>UA偽装:</strong> 悪意のあるクローラーが一般的なブラウザの User-Agent を偽装した場合、UA判定だけでは防げないことがあります（IP制限との併用を推奨）。</li>
                <li><strong>保証の限界:</strong> 本プラグインは、アクセス制御の労力を減らし、既知のボットを効率的に管理するためのツールです。完全なセキュリティ防御が必要な場合は、WAFなどの導入をご検討ください。</li>
            </ul>

            <h4 class="ggc-notice-subtitle">4. 設定時の注意</h4>
            <ul class="ggc-list-disc">
                <li><strong>自分自身をブロックしない:</strong> 特に「IPアドレス評価」でホワイトリスト（許可）モードを使用する場合、自分のIPアドレスを含めないとページを閲覧できなくなります（管理画面には影響しません）。</li>
            </ul>
        </div>
        <?php
    }

    private function render_usage_steps() {
        ?>
        <hr>

        <h3>1. 設定のステップ</h3>

        <h4>Step 1: 定義リストの作成・設定画面の各タブの設定</h4>
        <p>まず、制御に使用する「リスト」を作成します。初期設定として「グローバル設定」タブの「おすすめ設定をインポート」することをお勧めします。</p>
        <p>タブ設定で定義リストを作成します。</p>
        <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th class="ggc-th-20">タブ名</th>
                    <th>用途例</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>グローバル設定</strong></td>
                    <td>定義リストで設定したルールを全ページで適用します。IPリストの自動更新頻度、おすすめ設定、データの初期化もこの画面で行えます。</td>
                </tr>
                <tr>
                    <td><strong>マークダウン</strong></td>
                    <td>マークダウンのテンプレートを作成・編集します。テンプレートは投稿側で選択またはランダム表示に利用できます。</td>
                </tr>
                <tr>
                    <td><strong>ページの評価</strong></td>
                    <td>アクセスブロックする際に使用するメッセージを編集します。</td>
                </tr>
                <tr>
                    <td><strong>User-Agent 定義 1 & 2</strong></td>
                    <td>
                        User-Agentのリストを登録します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li>
                                <strong>定義1（Bot系）:</strong>【判定方式】<u>前方一致・大文字小文字無視</u>（User-Agentの先頭から一致。例: Googlebot など）<br>
                                <span style="font-size:90%;color:#666;">例: <code>Googlebot</code> → <code>Googlebot/2.1</code> で一致</span>
                            </li>
                            <li>
                                <strong>定義2（Tool系）:</strong>【判定方式】<u>部分一致・大文字小文字無視</u>（User-Agent内に含まれていれば一致。<br>
                                ただし短いもの（curl, Java など）は <code>^</code> で先頭限定の正規表現として判定されます）<br>
                                <span style="font-size:90%;color:#666;">例: <code>^curl</code> → <code>curl/8.0.1</code> で一致、<code>python-requests</code> → <code>Mozilla/5.0 python-requests/2.28</code> で一致</span>
                            </li>
                        </ul>
                        <div style="margin:0.5em 0 0 0.5em; font-size:90%; color:#444; background:#f8f8f8; border-left:3px solid #ccc; padding:0.7em 1em;">
                            <strong>【設計意図・運用上の注意】</strong><br>
                            <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                                <li>Bot系（定義1）は「Googlebot」など明確なクローラー名を前方一致で判定します。<br>大文字小文字は無視されます（例: <code>Googlebot</code> も <code>googlebot</code> も一致）。</li>
                                <li>Tool系（定義2）は curl, Java, python-requests などのツール名やライブラリ名を部分一致で判定します。<br>ただし「curl」「Java」など短い名前は誤検知防止のため <code>^</code> を付けて先頭限定にしてください。</li>
                                <li>Tool系のリストに <code>curl</code> だけを登録すると「curl/8.0.1」だけでなく「mycurlbot」なども一致してしまうため、<code>^curl</code> のように記述してください。</li>
                                <li>正規表現の特殊記号は <code>^</code>（先頭一致）のみ利用可能です。<br>それ以外の正規表現はサポートしていません。</li>
                                <li>リストの記述ミスや曖昧な登録は、誤検知や本来のBot/Tool以外のアクセスもブロックする原因となります。<br>登録内容をよくご確認ください。</li>
                            </ul>
                            <strong>【判定例】</strong><br>
                            <table style="border-collapse:collapse; margin:0.5em 0;">
                                <tr><th style="border:1px solid #ccc; padding:2px 8px;">リスト登録値</th><th style="border:1px solid #ccc; padding:2px 8px;">User-Agent例</th><th style="border:1px solid #ccc; padding:2px 8px;">判定</th></tr>
                                <tr><td style="border:1px solid #ccc; padding:2px 8px;">Googlebot</td><td style="border:1px solid #ccc; padding:2px 8px;">Googlebot/2.1 (+http://www.google.com/bot.html)</td><td style="border:1px solid #ccc; padding:2px 8px;">○（一致）</td></tr>
                                <tr><td style="border:1px solid #ccc; padding:2px 8px;">^curl</td><td style="border:1px solid #ccc; padding:2px 8px;">curl/8.0.1</td><td style="border:1px solid #ccc; padding:2px 8px;">○（一致）</td></tr>
                                <tr><td style="border:1px solid #ccc; padding:2px 8px;">^curl</td><td style="border:1px solid #ccc; padding:2px 8px;">mycurlbot/1.0</td><td style="border:1px solid #ccc; padding:2px 8px;">×（一致しない）</td></tr>
                                <tr><td style="border:1px solid #ccc; padding:2px 8px;">python-requests</td><td style="border:1px solid #ccc; padding:2px 8px;">Mozilla/5.0 python-requests/2.28</td><td style="border:1px solid #ccc; padding:2px 8px;">○（一致）</td></tr>
                                <tr><td style="border:1px solid #ccc; padding:2px 8px;">Java</td><td style="border:1px solid #ccc; padding:2px 8px;">Java/19.0.2</td><td style="border:1px solid #ccc; padding:2px 8px;">○（一致、ただし短いので^Java推奨）</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><strong>IPアドレス範囲 1 & 2</strong></td>
                    <td>許可または拒否したいIPアドレスの範囲（CIDR形式）を登録します。<br>
                    公開IPリストURLを設定し「自動更新」を有効にすると、定期的に最新のIP範囲を取り込みます。</td>
                </tr>
                <tr>
                    <td><strong>診断ツール</strong></td>
                    <td>現在アクセスしているユーザーの情報を確認や設定情報の確認ができます。登録しているIPも確認可能です。</td>
                </tr>
                <tr>
                    <td><strong>プラグインの使い方</strong></td>
                    <td>この画面です。プラグインの使い方や設定方法について説明しています。</td>
                </tr>
            </tbody>
        </table>

        <h4>Step 2: 設定画面のグローバル設定</h4>
                <p>グローバル設定では、プラグイン全体の動作や評価方法を設定できます。</p>
                <p>選択する項目によって、評価の対象や動作が変わります。</p>
        <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th class="ggc-th-20">設定名</th>
                    <th>用途例</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>グローバル設定 評価-マークダウン置換</strong></td>
                    <td>
                        投稿や固定ページ本文全体をマークダウンテンプレートで自動置換します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>評価方法：</strong>評価の方法を選択して、条件に応じて本文を置換します。</li>
                            <li><strong>User-Agentの評価-マークダウン：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ本文を置換できます。</li>
                            <li><strong>IPアドレスの評価-マークダウン：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ本文を置換できます。</li>
                            <li><strong>テンプレート選択方法：</strong>「指定したテンプレートを常に使用」または「ランダムで選択」など、表示するテンプレートの選択方法を設定できます。</li>
                        </ul>
                        例：特定のクローラーやIPからのアクセス時に、本文を非公開メッセージや会員向け案内に差し替える。
                    </td>
                </tr>
                <tr>
                    <td><strong>グローバル設定 評価-メディア</strong></td>
                    <td>
                        画像やメディアファイルの表示・非表示、または代替テキストへの置換を一括で制御します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>評価方法：</strong>評価の方法を選択して、条件に応じて画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>メディア表示モード：</strong>コンテンツの表示方法を選択して、条件に応じて画像やメディアの表示・非表示やテキスト置換を行います。</li>
                            <li><strong>代替テキスト：</strong>画像やメディアを非表示にする代わりに、指定したテキスト（例：「閲覧制限中」など）を表示できます。</li>
                            <li><strong>メディアを非表示：</strong>条件に一致した場合、画像やメディアファイル自体をページ上から非表示にします。</li>
                            <li><strong>アイキャッチ画像表示方法：</strong>アイキャッチ画像の表示方法を選択して、条件に応じて表示・非表示や代替テキストの設定を行います。</li>
                            <li><strong>アイキャッチ画像の代替テキスト：</strong>アイキャッチ画像（サムネイル画像）に対して、条件に応じて代替テキストを自動で設定できます。</li>
                            <li><strong>User-Agentの評価-メディア：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ、画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>IPアドレスの評価-メディア：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、画像やメディアの表示・非表示や置換を行います。</li>
                        </ul>
                        例：特定の条件下で画像を非表示にしたり、著作権保護のために代替テキストへ変更する。
                    </td>
                </tr>
                <tr>
                    <td><strong>グローバル設定 評価-ページ</strong></td>
                    <td>
                        アクセス可否やリダイレクト、ブロックメッセージの表示などを設定します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>評価方法：</strong>評価の方法を選択して、条件に応じてページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>User-Agentの評価-ページ：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>IPアドレスの評価-ページ：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>ページ評価の動作：</strong>条件に一致した場合、カスタムメッセージの表示や、指定URLへのリダイレクト、または完全なブロックなどの動作を選択できます。</li>
                            <li><strong>メッセージ内容：</strong>ページ評価の動作に応じて表示されるカスタムメッセージの内容を設定します。</li>
                        </ul>
                        例：特定のUser-AgentやIPアドレスからのアクセスをブロックし、カスタムメッセージや別URLへ転送する。
                    </td>
                </tr>
                <tr>
                    <td><strong>IPアドレス自動更新設定</strong></td>
                    <td>IPアドレスの範囲で設定したリストの自動更新タイミングを設定します。</td>
                </tr>
                <tr>
                    <td><strong>その他の設定</strong></td>
                    <td>上記以外の細かな動作や例外設定、プラグインの動作全般に関わるオプションをまとめています。
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li>おすすめ設定のインポート：<strong>定義リストなど、おすすめ設定をインポートできます。</strong></li>
                            <li>全データのクリア：<strong>定義リストなど、すべての保存データをクリアできます。</strong></li>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>評価方法は下記の通りです。</p>
        <p>「グローバル設定」＞「投稿ページ・固定ページ」の優先度で制御が行われます。</p>
        <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th class="ggc-th-20">評価方法</th>
                    <th>挙動</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>無効</strong></td>
                    <td>
                        全設定を無効化します。グローバル設定、投稿・固定ページの設定に関わらず、すべての評価が無効化されます。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>投稿・固定ページ個別設定</strong></td>
                    <td>
                        投稿・固定ページごとの設定（ブラックリスト/ホワイトリスト等）に従って評価されます。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>全ページで設定</strong></td>
                    <td>
                        グローバル設定で設定した内容がすべてのページに反映されます。投稿・固定ページの設定に関わらず、グローバル設定の内容がすべてのページに反映されます。<br>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>User-Agent、IPアドレスの評価の挙動は下記の通りです。</p>
        <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th class="ggc-th-20">評価</th>
                    <th>挙動</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>設定しない</strong></td>
                    <td>
                        全ページで評価を行いません。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>ブロックリスト</strong></td>
                    <td>
                        選択したリストに対して評価を行います。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>ホワイトリスト</strong></td>
                    <td>
                        選択したリストに対して評価を行い、除外設定をします。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>全許可</strong></td>
                    <td>
                        すべての評価を行いません。<br>
                    </td>
                </tr>
                <tr>
                    <td><strong>全拒否</strong></td>
                    <td>
                        評価を行わず、すべて拒否します。<br>
                    </td>
                </tr>
            </tbody>
        </table>




        <h4>Step 3: 投稿・固定ページでの適用</h4>
        <p>投稿・固定ページ毎に設定を行います。</p>
        <p>記事の投稿画面（または固定ページの編集画面）のサイドバーにある「アクセス制御」ボックスで設定します。</p>
        <p>選択する項目によって、評価の対象や動作が変わります。</p>
       <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th class="ggc-th-20">設定名</th>
                    <th>用途例</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>マークダウン評価</strong></td>
                    <td>
                        マークダウンテンプレートで自動置換します。※置換方法によって設定が異なります。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>置換方法：</strong>マークダウン本文、またはテンプレートを置換の方法を設定します。</li>
                            <li><strong>マークダウンのページタイトル：</strong>マークダウン本文のタイトル部分を設定します。</li>
                            <li><strong>マークダウン用のアイキャッチ画像：</strong>マークダウン本文に使用するアイキャッチ画像を設定します。</li>
                            <li><strong>置換するマークダウン本文：</strong>マークダウン本文の内容を設定します。</li>     
                            <li><strong>テンプレート選択：</strong>表示するテンプレートを選択します。</li>                 
                            <li><strong>User-Agentの評価-マークダウン：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ本文を置換できます。</li>
                            <li><strong>IPアドレスの評価-マークダウン：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ本文を置換できます。</li>
                            <li><strong>マークダウンのプレビュー：</strong>マークダウン本文のプレビューを確認できます。</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <td><strong>メディア評価</strong></td>
                    <td>
                        画像やメディアファイルの表示・非表示、または代替テキストへの置換を一括で制御します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>メディア表示モード：</strong>メディアの表示方法を選択して、条件に応じて画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>アイキャッチ画像の非表示設定：</strong>投稿編集画面では次の3つから選びます。<br>
                                <ol style="margin:0 0 0 1em; padding:0; list-style:decimal;">
                                    <li>設定しない</li>
                                    <li>評価に従って個別でテキスト置換・非表示<br>
                                        → メディアを個別で「通常表示/非表示/テキスト置換」が指定できます。テキスト置換を選ぶと入力欄が現れます。</li>
                                    <li>評価に従ってすべて非表示<br>
                                メディアをすべて非表示にします。</li>
                                </ol>
                            </li>
                            <li><strong>ブロックの表示モード：</strong>各メディアを選択後に「アクセス制御＞ブロック表示モード」から設定できます。通常表示、非表示、テキスト置換のいずれかを選択できます。</li>
                            <li><strong>メディア表示設定：</strong>メディア表示モードで「テキスト置換」を選択した場合に、置換するテキストを入力します。</li>
                            <li><strong>User-Agentの評価-メディア：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ、画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>IPアドレスの評価-メディア：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>IPアドレスの評価-メディア：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、画像やメディアの表示・非表示や置換を行います。</li>
                            <li><strong>メディアプレビュー：</strong>メディアの表示・非表示や代替テキストの置換をプレビューできます。保存後に有効です。</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <td><strong>ページ評価</strong></td>
                    <td>
                        ページ全体へのアクセス可否やリダイレクト、ブロックメッセージの表示など設定します。<br>
                        <ul style="margin:0 0 0 1.2em; padding:0; list-style:disc;">
                            <li><strong>User-Agentの評価方法-ページ：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>User-Agentの評価-ページ：</strong>特定のクローラーやボット（User-Agent）からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>User-Agentの評価-ブロックメッセージ：</strong>ページブロック時にテンプレートまたは任意の定義メッセージを指定して表示します。</li>
                            <li><strong>User-Agentの評価時のメッセージ定義：</strong>ページブロック時のカスタムメッセージを定義します。</li>
                            <li><strong>User-Agentの評価-リダイレクトURL：</strong>リダイレクト先URLを定義します。</li>
                            <li><strong>IPアドレスの評価方法-ページ：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>IPアドレスの評価-ページ：</strong>特定のIPアドレスやIP範囲からのアクセス時のみ、ページ全体の表示可否やリダイレクトを制御します。</li>
                            <li><strong>IPアドレスの評価-ブロックメッセージ：</strong>ページブロック時にテンプレートまたは任意の定義メッセージを指定して表示します。</li>
                            <li><strong>IPアドレス評価時のメッセージ定義：</strong>ページブロック時のカスタムメッセージを定義します。</li>
                            <li><strong>IPアドレスの評価-リダイレクトURL：</strong>リダイレクト先URLを定義します。</li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }


    private function render_usage_troubleshooting() {
        ?>
        <hr>

        <h3>2. トラブルシューティング</h3>

        <table class="widefat striped ggc-table-spaced">
            <thead>
                <tr>
                    <th style="width:32%;">よくある質問</th>
                    <th>回答・対処法</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>設定を間違えてページが見られなくなりました。</strong></td>
                    <td>管理画面（ダッシュボード）にはアクセス制御は適用されません。管理画面にログインし、グローバル設定を「制御設定しない」にします。個別設定は、該当する記事の編集画面で「全許可」または「設定しない」に戻してください。</td>
                </tr>
                <tr>
                    <td><strong>IPアドレスの自動更新が動きません。</strong></td>
                    <td>「グローバル設定」タブで更新頻度が「停止」になっていないか確認してください。また、「診断ツール」タブで次回の実行予定時刻を確認できます。「今すぐIP更新を強制実行する」ボタンで手動更新も可能です。</td>
                </tr>
                <tr>
                    <td><strong>特定のボットだけブロックしたい</strong></td>
                    <td>「User-Agent 定義1」にそのボットを追加し、記事の編集画面で「User-Agent の評価」を「ブラックリスト」にして、そのボットにチェックを入れてください。</td>
                </tr>
                <tr>
                    <td><strong>504などのサーバ側でエラーが発生する</strong></td>
                    <td>サーバの設定によってアプリが動作しない場合があります。WAF設定などを確認してください。また本プラグインはクーロンを使用します。許可されているか確認してください。</td>
                </tr>
                <tr>
                    <td><strong>500系エラーで最初だけブロックし、リロードすると通常表示される</strong></td>
                    <td>プロキシやキャッシュサーバーは504/500などを受け取ると独自ページに差し替えたり、最初のレスポンスをキャッシュすることがあります。完全な検証の際はブラウザ・サーバーキャッシュを消去するか、ブロックステータスを403など5xx以外に設定してみてください。</td>
                </tr>
                <tr>
                    <td><strong>マークダウンの画像や表が表示されない</strong></td>
                    <td>画像は <code>![alt](URL)</code> の書式に対応しています。表（テーブル）は簡易レンダラでは未対応のため表示されません。</td>
                </tr>
                <tr>
                    <td><strong>個別で画像などのメディアを表示したくない。テキストに置換したい。</strong></td>
                    <td>投稿・固定ページから、各画像やメディアを個別に選択してください。選択後に「ブロック＞アクセス制御＞メディア表示設定」のテキストボックスに任意のテキストを入力してください。</td>
                </tr>
                <tr>
                    <td><strong>ページブロックのステータスコードは何の意味がある？</strong></td>
                    <td>ページブロック時に返されるHTTPステータスコードの意味を示します。例えば、403はアクセス禁止、404はページが見つからない、500はサーバエラーを示します。410はページ削除された意味を持ち、BOTのクロール頻度を下げる目的などに使用できます。詳しくは検索してみてください。</td>
                </tr>
                <tr>
                    <td><strong>保存がうまく出来ない</strong></td>
                    <td>プラグインのアップデートをすると保存がうまく出来ない事があります。グローバル設定の「すべての保存データをクリアする」を実行すると解決する場合があります。</td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private function render_usage_app_info() {
        ?>
        <hr>

        <h3>3. アプリ情報</h3>
        <table class="widefat striped ggc-table-info">
            <tbody>
                <tr>
                    <td class="ggc-td-30"><strong>プラグイン名</strong></td>
                    <td>Smart Access Control</td>
                </tr>
                <tr>
                    <td><strong>バージョン</strong></td>
                    <td>4.0.0</td>
                </tr>
                <tr>
                    <td><strong>作者</strong></td>
                    <td>donnma (<a href="https://donnma.com/" target="_blank">donnma.com</a>)</td>
                </tr>
                <tr>
                    <td><strong>GitHub</strong></td>
                    <td><a href="https://github.com/donnma777/smart-access-control" target="_blank">donnma777/smart-access-control</a></td>
                </tr>
                <tr>
                    <td><strong>リリース情報</strong></td>
                    <td><a href="https://github.com/donnma777/smart-access-control/releases" target="_blank">Releases</a></td>
                </tr>
                <tr>
                    <td><strong>X (Twitter)</strong></td>
                    <td><a href="https://x.com/donnma777" target="_blank">@donnma777</a></td>
                </tr>
            </tbody>
        </table>
        <?php
    }
}

// Backward compatibility
if (! trait_exists('Custom_Admin_Usage')) {
    class_alias(__NAMESPACE__ . '\\Custom_Admin_Usage', 'Custom_Admin_Usage');
}

