<?php

/* 設定 */
// DSN(Data Source Name)
define('DB_DSN', 'mysql:dbname=nagoya;host=127.0.0.1;charset=utf8');
define('DB_USER', 'root');               // ユーザー名
define('DB_PASS', '282828');                   // パスワード
define('SESSION_NAME', 'MiniBoard');     // セッションクッキーに用いる名前
define('DISP_MAX',  10);                 // 1ページの最大表示数
define('LIMIT_SEC', 5);                  // 連続投稿を禁止する秒数
define('TOKEN_MAX', 10);                 // ワンタイムトークン蓄積最大数
date_default_timezone_set('Asia/Tokyo'); // タイムゾーン
mb_internal_encoding('UTF-8');           // 内部エンコーディング

/**
 * HTML特殊文字をエスケープする関数
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * RuntimeExceptionを生成する関数
 * http://qiita.com/mpyw/items/6bd99ff62571c02feaa1
 */
function e($msg, Exception &$previous = null) {
    return new RuntimeException($msg, 0, $previous);
}

/**
 * 例外スタックのメッセージ部分を配列に変換する関数
 * http://qiita.com/mpyw/items/6bd99ff62571c02feaa1
 */
function exception_to_array(Exception $e) {
    do {
        $msgs[] = $e->getMessage();
    } while ($e = $e->getPrevious());
    return array_reverse($msgs);
}

/* 変数の初期化 */
// リクエストパラメータをトリミングした後展開
foreach (array('name', 'email', 'text', 'token', 'page', 'submit') as $v) {
    $$v = isset($_POST[$v]) && is_string($_POST[$v]) ? trim($_POST[$v]) : '';
}
// ページ番号を1以上の整数になるように補正
$page = max(1, (int)$page);

/* セッションの初期化 */
session_name(SESSION_NAME); // セッション名を設定
@session_start();           // セッション開始
// セッション変数を初期化
if (!$_SESSION) {
    $_SESSION = array(
        'name'  => '',
        'email' => '',
        'text'  => '',
        'token' => array(),
        'prev'  => null,
    );
}

/* PDOでの処理 */
try {

    // 接続
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
    // プリペアドステートメントのエミュレーションを無効化
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    // SQL実行でエラーが発生したときに例外をスローするように設定
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 送信ボタンが押された場合
    if ($submit) {
        try {
            // セッション変数に書き込む情報をセット
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            $_SESSION['text'] = $text;
            // ワンタイムトークンが直近指定個に含まれていなければ弾く
            if (!isset($_SESSION['token'][$token])) {
                throw e('フォームの有効期限が切れています。', $e);
            }
            // ワンタイムトークンを消費させる
            unset($_SESSION['token'][$token]);
            // 最後の投稿から指定秒経過していなければ弾く
            if ($_SESSION['prev'] !== null) {
                $diff = $_SERVER['REQUEST_TIME'] - $_SESSION['prev'];
                if (($limit = LIMIT_SEC - $diff) > 0) {
                    throw e("投稿間隔が短すぎます。{$limit}秒ほどお待ちください。", $e);
                }
            }
            // 名前をチェック
            if (!$len = mb_strlen($name) or $len > 30) {
                $e = e('名前は30字以下で入力してください。', $e);
            }
            // Eメールアドレスをチェック
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $e = e('有効なEメールアドレスを入力してください。', $e);
            }
            // 本文をチェック
            if (!$len = mb_strlen($text) or $len > 140) {
                $e = e('本文は140字以下で入力してください。', $e);
            }
            // 例外がここまでに1つでも発生していればスローする
            if (!empty($e)) {
                throw $e;
            }
            // プリペアドステートメントを生成
            $stmt = $pdo->prepare(implode(' ', array(
                'INSERT',
                'INTO mini_board(`name`, `email`, `text`, `time`)',
                'VALUES(?, ?, ?, ?)',
            )));
            // 書き込みを実行
            $stmt->execute(array(
                $name,
                $email,
                $text,
                date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'])
            ));
            // セッションに時間を記録し、メッセージをセット
            $_SESSION['prev'] = $_SERVER['REQUEST_TIME'];
            // フォームの投稿内容をリセットする
            $_SESSION['text'] = '';
            // 正しい処理を行ったが、形式上例外としてスロー
            throw e('書き込みました', $e);
        } catch (Exception $e) { }
    }
    // プリペアドステートメントを生成
    $stmt = $pdo->prepare(implode(' ', array(
        'SELECT',
        'SQL_CALC_FOUND_ROWS `name`, `email`, `text`, `time`',
        'FROM mini_board',
        'ORDER BY `id` DESC',
        'LIMIT ?, ?',
    )));
    // 値をバインド
    $stmt->bindValue(1, ($page - 1) * DISP_MAX, PDO::PARAM_INT);
    $stmt->bindValue(2, DISP_MAX, PDO::PARAM_INT);
    // 読み出しを実行
    $stmt->execute();
    // ページ番号にあった分だけ取り出す
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // 現在のページの件数をセット
    $current_count = count($articles);
    // 総件数をセット
    $whole_count = (int)$pdo->query('SELECT FOUND_ROWS()')->fetchColumn();
    // 総ページ数をセット
    $page_count = ceil($whole_count / DISP_MAX);

} catch (Exception $e) { }

/* ワンタイムトークンを次の投稿のために準備 */
$_SESSION['token'] = array_slice(
    array($token = sha1(mt_rand()) => 1) + $_SESSION['token'],
    0,
    TOKEN_MAX
);

/* ヘッダー送信 */
header('Content-Type: application/xhtml+xml; charset=utf-8');

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head>
    <title>ミニ掲示板</title>
    <style type="text/css"><![CDATA[
    <!--
    #wrapper {
      width: 100%;
    }
    #header, #container, #footer {
      width: 650px;
      margin: 0px auto;
    }
    #header, #footer {
      text-align: center;
    }
    #messages, #textarea, #articles {
      width: 550px;
      margin: -3px auto 0px;
      border-top: 3px double brown;
      padding: 28px;
    }
    #articles { 
      overflow: hidden;
      padding-top: 0px;
    }
    .article {
      margin-top: -1px;
      border-top: 1px dotted brown;
      padding: 19px;
    }
    .article_name {
      font-size: 27px;
    }
    .article_text {
      margin: 20px;
    }
    .article_time {
      text-align: right;
      font-size: 11px;
    }
    .page {
      font-size: 11px;
      text-align: right;
    }
    body {
      background: antiquewhite;
    }
    h1 {
      color: fuchsia;
    }
    textarea {
      width: 100%;
      height: 150px;
    }
    label {
      display: block;
      margin: 10px 10px;
    }
    -->
 ]]></style>
  </head>
  <body>
    <div id="wrapper">
      <div id="header">
        <h1>～ミニ掲示板～</h1>
      </div>
      <div id="container">
        <div id="textarea">
          <form action="" method="post">
            <label>名前: <input name="name" type="text" value="<?=h($_SESSION['name'])?>" /></label>
            <label>Eメール: <input name="email" type="text" value="<?=h($_SESSION['email'])?>" /></label>
            <label>本文<p><textarea name="text"><?=h($_SESSION['text'])?></textarea></p></label>
            <label style="text-align:right;"><input type="submit" name="submit" value="投稿" /></label>
            <label><input type="hidden" name="token" value="<?=h($token)?>" /></label>
          </form>
        </div>
<?php if (!empty($e)): ?>
        <div id="messages">
<?php foreach (exception_to_array($e) as $msg): ?>
          <div><?=h($msg)?></div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
<?php if (!empty($articles)): ?>
        <div id="articles">
<?php foreach ($articles as $article): ?>
          <div class="article">
            <div class="article_name"><a href="mailto:<?=h($article['email'])?>"><?=h($article['name'])?></a></div>
            <div class="article_text"><pre><?=h($article['text'])?></pre></div>
            <div class="article_time"><?=h($article['time'])?></div>
          </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
      <div id="footer">
        <div>
<?php if ($page > 1): ?>
          <a href="?page=<?=$page-1?>">前</a> | 
<?php endif; ?>
          <a href="?">最新</a>
<?php if (!empty($page_count) and $page < $page_count): ?>
           | <a href="?page=<?=$page+1?>">次</a>
<?php endif; ?>
        </div>
        <p class="page"><?php
          if (empty($current_count)) {
            echo 'まだ書き込みはありません';
          } else {
            printf('%d件中%d件目～%d件目(%dページ中%dページ目)を表示中',
              $whole_count,
              ($tmp = ($page - 1) * DISP_MAX) + 1,
              $tmp + $current_count,
              $page_count,
              $page
            );
          }
        ?></p>
      </div>
    </div>
  </body>
</html>