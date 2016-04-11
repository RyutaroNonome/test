<?php
session_start();

require '../autoload.php';
require 'env.php';
use Abraham\TwitterOAuth\TwitterOAuth;

/* Access Token、Access Secretがsessionにない場合はclearsessions,phpへリダイレクト */
if (empty($_SESSION['access_token']) || empty($_SESSION['access_token']['oauth_token']) || empty($_SESSION['access_token']['oauth_token_secret'])) {
    header('Location: ./clearsessions.php');
}

/* Access Token、Access Secretを取得 */
$access_token = $_SESSION['access_token'];

/* TwitterOAuthを生成（またまたパラメータが違う...パラメータによって使用できる関数を制御しています） */
$connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $access_token['oauth_token'], $access_token['oauth_token_secret']);

/* ユーザー情報の取得 */
$user = $connection->get("account/verify_credentials");
?>
<?php include('html.inc'); ?>
<div>
<a href="https://twitter.com/<?php echo $user->screen_name;?>">
<img src="<?php echo $user->profile_image_url_https; ?>" alt="...">
</a>
<div>
<h4><a href="https://twitter.com/<?php echo $user->screen_name;?>"><?php echo $user->name;?> <small>@<?php echo $user->screen_name;?></small></a></h4>
<?php echo $user->status->text;?>
</div>
</div>

<html>
<body>
<br>
twitterでのログイン成功です！<br>
それでは、掲示板をお楽しみください！<br><br>

<A Href="http://localhost/twitterlogintest/src/akihabara.php">秋葉原掲示板</A><br><br>
<A Href="http://localhost/twitterlogintest/src/nagoya.php">名古屋掲示板</A><br><br>

</body>
</html>